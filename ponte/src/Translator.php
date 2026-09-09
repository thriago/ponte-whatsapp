<?php
// src/Translator.php — o miolo da ponte. Traduz nos dois sentidos:
//
//   ENTRADA: evento bruto do webhook do wuzapi -> payload de
//            POST /api/v1/canal/mensagem do sistema de atendimento.
//   SAÍDA:   payload de GET /pendentes -> chamada no wuzapi.
//
// ⚠️ Nota sobre a ENTRADA: o formato exato do JSON que o wuzapi/whatsmeow
// manda (maiúscula/minúscula de "Info"/"info", "PushName"/"pushName", formato
// do JID — string "5511...@s.whatsapp.net" vs objeto {User,Server}) está
// documentado de forma incompleta no README/API.md oficiais dele. Por isso
// este parser tenta várias variantes de chave (helper `campo()`) em vez de
// travar numa só, e a ponte GRAVA o payload bruto em todo evento de entrada
// (EventLog) — se o parser errar o nome de um campo, o JSON cru está ali pra
// corrigir em minutos, em vez de caçar às cegas.
final class Translator
{
    /** Busca a primeira chave existente entre variantes de maiúscula/minúscula. */
    private static function campo(array $arr, array $chaves): mixed
    {
        foreach ($chaves as $c) {
            if (array_key_exists($c, $arr)) return $arr[$c];
        }
        return null;
    }

    /**
     * Extrai só os dígitos de um JID wuzapi/whatsmeow — string ou
     * {User,Server}.
     *
     * ⚠️ Quem manda de um APARELHO SECUNDÁRIO (multi-device do WhatsApp)
     * chega com o formato `<usuário>[:<aparelho>]@<servidor>` —
     * `"121655418425487:76@lid"`, `"5511999998888:76@s.whatsapp.net"`. O
     * `:76` é o ID do aparelho, NUNCA parte do número. Um
     * `preg_replace('/\D+/', ...)` direto engole os dois pontos e gruda o
     * aparelho no telefone (`5511999998888` vira `551199999888876`): a
     * allowlist não bate mais e mensagem legítima é descartada. Mensagem do
     * aparelho principal não tem o sufixo, então o problema só aparece
     * quando alguém usa o WhatsApp Web ou um segundo celular.
     * Cortar o `:<aparelho>` ANTES de extrair dígitos —
     * função única, usada por TODO o parse de JID de entrada (Sender, Chat,
     * SenderAlt, e a chave do mapa de LID em resolverTelefoneRemetente) —
     * um ponto só de correção cobre todos.
     */
    private static function digitosDoJid(mixed $jid): ?string
    {
        if (is_string($jid)) {
            $antesDoArroba = explode('@', $jid, 2)[0];
            $semAparelho   = explode(':', $antesDoArroba, 2)[0];
            $digitos = preg_replace('/\D+/', '', $semAparelho);
            return $digitos !== '' ? $digitos : null;
        }
        if (is_array($jid)) {
            $user = self::campo($jid, ['User', 'user']);
            if (is_string($user)) {
                $semAparelho = explode(':', $user, 2)[0];
                $digitos = preg_replace('/\D+/', '', $semAparelho);
                return $digitos !== '' ? $digitos : null;
            }
        }
        return null;
    }

    private static function ehGrupo(mixed $jid): bool
    {
        if (is_string($jid)) return str_contains($jid, '@g.us');
        if (is_array($jid)) return (self::campo($jid, ['Server', 'server']) === 'g.us');
        return false;
    }

    /**
     * Status/stories do WhatsApp (`Chat = "status@broadcast"`) precisam de
     * filtro PRÓPRIO. Sem ele, caem no filtro de grupo (que só pega `@g.us`,
     * não `@broadcast`) e seguem até a resolução de remetente, onde falham
     * por "LID sem SenderAlt": a mensagem É descartada, que é o certo, só
     * que com um motivo enganoso no registro de eventos — e quem for
     * investigar depois perde tempo caçando um "bug de LID" que não existe.
     */
    private static function ehStatus(mixed $jid): bool
    {
        if (is_string($jid)) return str_contains($jid, '@broadcast');
        if (is_array($jid)) return (self::campo($jid, ['Server', 'server']) === 'broadcast');
        return false;
    }

    /**
     * LID (LinkedID) — o modo de endereçamento novo da Meta, que aparece em
     * payload real: o `Sender`/`Chat` deixa de ser o telefone
     * (`5511...@s.whatsapp.net`) e vira um ID opaco (`121655418425487@lid`).
     * O telefone de verdade, quando existe, vem num campo IRMÃO: `SenderAlt`
     * (`RecipientAlt` na saída, que não é o caminho daqui — ver nota
     * abaixo). Nem todo evento vem em LID; os dois formatos convivem, então
     * NUNCA assumir um dos dois.
     */
    private static function ehLid(mixed $jid): bool
    {
        if (is_string($jid)) return str_contains($jid, '@lid');
        if (is_array($jid)) return (self::campo($jid, ['Server', 'server']) === 'lid');
        return false;
    }

    /**
     * Resolve o telefone de verdade do remetente, tratando LID. Reaproveita
     * `digitosDoJid()` (só dígitos, JID string ou objeto) — a mesma
     * normalização de sempre, agora alimentada pela origem certa.
     *
     * @return array{ok:bool,de:?string,motivo:?string}
     */
    private static function resolverTelefoneRemetente(array $info, mixed $sender, mixed $chat): array
    {
        $origem = $sender ?? $chat;

        if (self::ehLid($origem)) {
            // O LID em si (só dígitos antes do "@lid") é a CHAVE do mapa —
            // reaproveita digitosDoJid() porque um LID é, estruturalmente,
            // "dígitos antes de @algumacoisa", igual um telefone.
            $lid = self::digitosDoJid($origem);
            $alt = self::campo($info, ['SenderAlt', 'senderAlt', 'sender_alt']);
            $de  = self::digitosDoJid($alt);

            if ($de !== null) {
                // Os dois vieram juntos — grava/atualiza o par pra próxima
                // vez que chegar só o LID. Atualiza
                // mesmo se já existia: se a pessoa trocar de número mantendo
                // o LID, o mapa acompanha em vez de ficar preso no antigo.
                if ($lid !== null) {
                    require_once __DIR__ . '/LidMap.php';
                    LidMap::gravar($lid, $de);
                }
                return ['ok' => true, 'de' => $de, 'motivo' => null];
            }

            // Sem SenderAlt neste evento — tenta o mapa local ANTES de
            // recusar. Reduz o caso (não elimina): só resolve se os dois
            // já vieram juntos alguma vez antes pra este mesmo LID.
            if ($lid !== null) {
                require_once __DIR__ . '/LidMap.php';
                $doMapa = LidMap::resolver($lid);
                if ($doMapa !== null) {
                    return ['ok' => true, 'de' => $doMapa, 'motivo' => null];
                }
            }

            // NÃO inventa. Um LID sem SenderAlt e sem par guardado não liga
            // com telefone nenhum — melhor recusar (log explícito, contato
            // nenhum criado) do que gravar um identificador opaco que não
            // bate com o ERP, com a régua, com a busca do agente (é
            // exatamente a limitação do "ext:<id>" que a doc do canal
            // Integrador já descreve pra outros casos de identificador
            // opaco).
            $senderBruto = is_string($origem) ? $origem : json_encode($origem);
            return ['ok' => false, 'de' => null, 'motivo' => "remetente veio em LID sem SenderAlt utilizável e sem par guardado (Sender={$senderBruto}) — recusando em vez de inventar contato"];
        }

        // Caminho antigo — Sender/Chat já é o telefone direto. Convive com
        // o LID; não presumir que só um dos dois formatos vai aparecer.
        $de = self::digitosDoJid($sender) ?? self::digitosDoJid($chat);
        if ($de === null) {
            return ['ok' => false, 'de' => null, 'motivo' => 'não consegui extrair o telefone do JID — ver payload bruto'];
        }
        return ['ok' => true, 'de' => $de, 'motivo' => null];
    }

    /**
     * @return array{ignorar:true,motivo:string}|array{ignorar:false,idExterno:string,de:string,tipo:string,texto:?string,nome:?string,midiaRef:?array}
     */
    public static function wuzapiEventParaGuiaChat(array $envelope): array
    {
        $tipoEvento = (string) self::campo($envelope, ['type', 'Type']);
        if ($tipoEvento !== 'Message') {
            return ['ignorar' => true, 'motivo' => "evento tipo={$tipoEvento} (só \"Message\" interessa aqui)"];
        }

        $event = self::campo($envelope, ['event', 'Event']);
        if (!is_array($event)) {
            return ['ignorar' => true, 'motivo' => 'evento sem campo "event"'];
        }

        $info = self::campo($event, ['Info', 'info']);
        if (!is_array($info)) {
            return ['ignorar' => true, 'motivo' => 'evento sem "Info"'];
        }

        // Eco da própria mensagem (o que ESTA sessão acabou de mandar) — sem
        // isso vira laço infinito.
        $isFromMe = self::campo($info, ['IsFromMe', 'isFromMe', 'is_from_me']);
        if (!empty($isFromMe)) {
            return ['ignorar' => true, 'motivo' => 'eco da própria mensagem (IsFromMe)'];
        }

        $chat = self::campo($info, ['Chat', 'chat']);
        if (self::ehGrupo($chat)) {
            return ['ignorar' => true, 'motivo' => 'mensagem de grupo — a ponte só trata conversa de um pra um'];
        }
        if (self::ehStatus($chat)) {
            return ['ignorar' => true, 'motivo' => 'status/story do WhatsApp (Chat=status@broadcast) — não é mensagem de cliente'];
        }

        $sender = self::campo($info, ['Sender', 'sender']) ?? $chat;
        $resolvido = self::resolverTelefoneRemetente($info, $sender, $chat);
        $de = $resolvido['de'];
        if (!$resolvido['ok']) {
            return ['ignorar' => true, 'motivo' => $resolvido['motivo']];
        }

        // ── Allowlist (filtro opcional de números) ──────────────────────
        // Descarta e SÓ LOGA — o número descartado precisa aparecer no
        // motivo, pra dar pra ver depois quem tentou falar. Checado aqui
        // (não em index.php) pra valer pros dois caminhos de conteúdo
        // (texto e mídia) com um ponto só de imposição.
        require_once __DIR__ . '/Allowlist.php';
        if (!Allowlist::permitido($de)) {
            return ['ignorar' => true, 'motivo' => "remetente fora da allowlist (de={$de}) — ver ALLOWLIST_NUMEROS no .env"];
        }

        $idExterno = (string) (self::campo($info, ['ID', 'Id', 'id']) ?? ('sem-id-' . bin2hex(random_bytes(4))));
        $nome      = self::campo($info, ['PushName', 'pushName', 'push_name']);
        $nome      = is_string($nome) && $nome !== '' ? $nome : null;

        $msg = self::campo($event, ['Message', 'message']);
        if (!is_array($msg)) {
            return ['ignorar' => true, 'motivo' => 'evento sem "Message" (provável status/reação/edição — v1 ignora)'];
        }

        // Texto simples ou com link preview.
        $texto = self::campo($msg, ['conversation', 'Conversation']);
        if (!is_string($texto) || $texto === '') {
            $ext = self::campo($msg, ['extendedTextMessage', 'ExtendedTextMessage']);
            if (is_array($ext)) {
                $texto = self::campo($ext, ['text', 'Text']);
            }
        }
        if (is_string($texto) && $texto !== '') {
            return ['ignorar' => false, 'idExterno' => $idExterno, 'de' => $de, 'tipo' => 'texto', 'texto' => $texto, 'nome' => $nome, 'midiaRef' => null];
        }

        // Mídia: procura o primeiro sub-objeto de mídia conhecido.
        //
        // ⚠️ `tipoWuzapi`: o wuzapi espera o tipo em INGLÊS
        // (`image`/`audio`/`video`/`document`), enquanto a API do canal usa
        // os nomes em português. `audio` e `video` são iguais nos dois
        // idiomas — quem esquece a tradução só descobre no primeiro envio
        // de imagem, com um "tipo de mídia desconhecido: imagem".
        $mapaMidia = [
            'imageMessage'    => ['gc' => 'imagem',    'wz' => 'image'],
            'audioMessage'    => ['gc' => 'audio',     'wz' => 'audio'],
            'videoMessage'    => ['gc' => 'video',     'wz' => 'video'],
            'documentMessage' => ['gc' => 'documento', 'wz' => 'document'],
        ];
        foreach ($mapaMidia as $chaveWa => $tipos) {
            $m = self::campo($msg, [$chaveWa, ucfirst($chaveWa)]);
            if (!is_array($m)) continue;

            $url        = self::campo($m, ['url', 'URL', 'Url']);
            $mediaKey   = self::campo($m, ['mediaKey', 'MediaKey']);
            $mimetype   = self::campo($m, ['mimetype', 'Mimetype', 'mimeType']);
            $fileSha256 = self::campo($m, ['fileSHA256', 'fileSha256', 'FileSHA256']);
            $fileLength = self::campo($m, ['fileLength', 'FileLength']);
            $fileEncSha = self::campo($m, ['fileEncSHA256', 'fileEncSha256', 'FileEncSHA256']);
            $directPath = self::campo($m, ['directPath', 'DirectPath']);
            $legenda    = self::campo($m, ['caption', 'Caption']);

            // ⚠️ NÃO descarta a mensagem aqui: mídia que falha não pode
            // fazer a mensagem inteira sumir.
            // Mesmo sem `url`/`mediaKey` utilizáveis, monta o midiaRef assim
            // mesmo (com o que tiver, mesmo vazio) e deixa o CALLER
            // (rotaWebhookWuzapi) tentar o download; se falhar — vai falhar,
            // com um erro claro — o próprio caller já manda um texto de
            // fallback em vez de descartar. Um ponto SÓ de "nunca perde
            // mensagem", não dois caminhos divergentes.
            return [
                'ignorar'  => false,
                'idExterno'=> $idExterno,
                'de'       => $de,
                'tipo'     => $tipos['gc'],
                'texto'    => is_string($legenda) && $legenda !== '' ? $legenda : null,
                'nome'     => $nome,
                'midiaRef' => [
                    'tipoWuzapi'    => $tipos['wz'],
                    'url'           => is_string($url) ? $url : '',
                    'mediaKey'      => is_string($mediaKey) ? $mediaKey : '',
                    'mimetype'      => is_string($mimetype) ? $mimetype : 'application/octet-stream',
                    'fileSha256'    => (string) $fileSha256,
                    'fileLength'    => (int) $fileLength,
                    'fileEncSha256' => is_string($fileEncSha) ? $fileEncSha : null,
                    'directPath'    => is_string($directPath) ? $directPath : null,
                ],
            ];
        }

        return ['ignorar' => true, 'motivo' => 'mensagem sem texto nem mídia reconhecida (sticker/localização/contato/enquete — fora do v1)'];
    }

    /**
     * SAÍDA — payload de uma `entrega` (GET /pendentes ou webhook do
     * GuiaChat) -> o que mandar pro wuzapi.
     *
     * @return array{tipo:string,phone:string,texto:?string,midia:?array{url:string,tamanho:?int}}
     */
    public static function entregaGuiaChatParaWuzapi(array $payload): array
    {
        $tipo  = (string) ($payload['tipo'] ?? 'texto');
        $mapa  = ['imagem' => 'image', 'audio' => 'audio', 'video' => 'video', 'documento' => 'document'];
        return [
            'tipo'  => $mapa[$tipo] ?? 'text',
            'phone' => (string) ($payload['para'] ?? ''),
            'texto' => isset($payload['texto']) ? (string) $payload['texto'] : null,
            'midia' => is_array($payload['midia'] ?? null) ? $payload['midia'] : null,
        ];
    }
}
