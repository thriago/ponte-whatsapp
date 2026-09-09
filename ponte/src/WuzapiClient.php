<?php
// src/WuzapiClient.php — fala com o motor (wuzapi). Fronteira deliberada:
// SÓ este arquivo conhece a API do wuzapi. Trocar de motor no futuro é
// reescrever este arquivo, sem tocar na interface, no instalador nem no
// contrato com o sistema de atendimento.
//
// Referência: API.md do wuzapi (asternic/wuzapi, commit pinado no
// docker-compose.yml). Admin endpoints usam header `Authorization`; os
// endpoints de sessão/chat aceitam `Token` (usado nos exemplos oficiais).

final class WuzapiClient
{
    public function __construct(
        private string $baseUrl,
        private string $adminToken
    ) {
    }

    // ── Admin (gestão de sessões/usuários) ──────────────────────────────

    /**
     * @return array{ok:bool,id:?string,erro:?string}
     *
     * ⚠️ A resposta real do wuzapi (conferida contra o commit preso no
     * docker-compose.yml) vem ENVELOPADA (`{"code":201,"data":{...,
     * "id":"..."},"success":true}`) e o `id` é uma STRING (UUID hex) — não
     * o `{"id":2}` inteiro que o API.md dele ainda documenta. A migration
     * "change_id_to_string" do próprio wuzapi mudou isso e a doc ficou pra
     * trás; onde a doc e o binário divergirem, vale o binário.
     */
    public function criarUsuario(string $nome, string $token, string $webhookUrl, string $eventos = 'Message'): array
    {
        [$code, $body, $raw] = Http::json('POST', $this->baseUrl . '/admin/users', [
            'Authorization: ' . $this->adminToken,
        ], [
            'name'    => $nome,
            'token'   => $token,
            'webhook' => $webhookUrl,
            'events'  => $eventos,
        ]);
        $id = $body['data']['id'] ?? $body['id'] ?? null; // aceita as duas formas, envelopada ou crua
        if ($code >= 200 && $code < 300 && $id !== null) {
            return ['ok' => true, 'id' => (string) $id, 'erro' => null];
        }
        return ['ok' => false, 'id' => null, 'erro' => "HTTP {$code}: {$raw}"];
    }

    public function removerUsuario(string $wuzapiUserId): bool
    {
        [$code] = Http::json('DELETE', $this->baseUrl . '/admin/users/' . rawurlencode($wuzapiUserId), [
            'Authorization: ' . $this->adminToken,
        ]);
        return $code >= 200 && $code < 300;
    }

    /** @return array<int,array<string,mixed>> */
    public function listarUsuarios(): array
    {
        [$code, $body] = Http::json('GET', $this->baseUrl . '/admin/users', [
            'Authorization: ' . $this->adminToken,
        ]);
        if ($code !== 200 || !is_array($body)) return [];
        // Mesma observação acima: envelopado em "data" na versão pinada.
        if (isset($body['data']) && is_array($body['data'])) return $body['data'];
        return $body;
    }

    // ── Sessão (por token de sessão, não o admin) ───────────────────────

    /** @return array{ok:bool,detalhe:string} */
    public function conectar(string $sessaoToken): array
    {
        [$code, $body, $raw] = Http::json('POST', $this->baseUrl . '/session/connect', [
            'Token: ' . $sessaoToken,
        ], [
            'Subscribe' => ['Message'],
            'Immediate' => true,
        ]);
        return ['ok' => $code >= 200 && $code < 300, 'detalhe' => $raw];
    }

    public function desconectar(string $sessaoToken): bool
    {
        [$code] = Http::json('POST', $this->baseUrl . '/session/disconnect', ['Token: ' . $sessaoToken]);
        return $code >= 200 && $code < 300;
    }

    /**
     * @return array{connected:bool,loggedIn:bool,jid:?string}
     *
     * ⚠️ Conferido contra o wuzapi de verdade, com sessão já pareada
     * (`curl -H "Token: ..." /session/status`): a resposta vem com as chaves
     * em **minúsculo** (`connected`, `loggedIn`, `jid`, `qrcode`) — não
     * `Connected`/`LoggedIn` como o `API.md` dele documenta. É o mesmo tipo
     * de divergência entre doc e binário do `id` em `/admin/users`.
     * Ler a chave errada aqui custa caro: `!empty()` de chave inexistente é
     * sempre `false`, então a sessão apareceria SEMPRE como "aguardando QR"
     * mesmo conectada, e a tela de Integrar (que só libera depois de
     * `status===conectado`) nunca apareceria. Este método devolve também o
     * `jid`, o que evita uma segunda chamada em `/admin/users` só pra pegar
     * o número — ver `rotaApiStatus()`.
     *
     * O endpoint `/session/qr`, em contraste, usa `QRCode` (maiúsculo) de
     * verdade — conferido também contra o servidor real, não é o mesmo bug.
     * Cada endpoint tem a casing que tem; não assumir uniformidade.
     */
    public function status(string $sessaoToken): array
    {
        [$code, $body] = Http::json('GET', $this->baseUrl . '/session/status', ['Token: ' . $sessaoToken]);
        $d = ($body['data'] ?? null);
        $d = is_array($d) ? $d : [];
        $jid = $d['jid'] ?? $d['Jid'] ?? $d['JID'] ?? null;
        return [
            'connected' => $code === 200 && !empty($d['connected'] ?? $d['Connected'] ?? null),
            'loggedIn'  => $code === 200 && !empty($d['loggedIn'] ?? $d['LoggedIn'] ?? null),
            'jid'       => (is_string($jid) && $jid !== '') ? $jid : null,
        ];
    }

    /** @return ?string data:image/png;base64,... (ou null se ainda não tiver QR) */
    public function qr(string $sessaoToken): ?string
    {
        [$code, $body] = Http::json('GET', $this->baseUrl . '/session/qr', ['Token: ' . $sessaoToken]);
        if ($code === 200 && !empty($body['data']['QRCode'])) {
            return (string) $body['data']['QRCode'];
        }
        return null;
    }

    // ── Envio (saída: GuiaChat -> WhatsApp) ─────────────────────────────

    /** @return array{ok:bool,detalhe:string} */
    public function enviarTexto(string $sessaoToken, string $phone, string $body): array
    {
        [$code, , $raw] = Http::json('POST', $this->baseUrl . '/chat/send/text', ['Token: ' . $sessaoToken], [
            'Phone' => $phone,
            'Body'  => $body,
        ]);
        return ['ok' => $code >= 200 && $code < 300, 'detalhe' => $raw];
    }

    /** @param 'image'|'audio'|'video'|'document' $tipo */
    public function enviarMidia(string $sessaoToken, string $tipo, string $phone, string $dataUri, ?string $caption = null, ?string $fileName = null): array
    {
        $campo = ['image' => 'Image', 'audio' => 'Audio', 'video' => 'Video', 'document' => 'Document'][$tipo] ?? null;
        if ($campo === null) {
            return ['ok' => false, 'detalhe' => "tipo de mídia desconhecido: {$tipo}"];
        }
        $payload = ['Phone' => $phone, $campo => $dataUri];
        if ($caption !== null && $tipo !== 'audio') $payload['Caption'] = $caption;
        if ($tipo === 'document') $payload['FileName'] = $fileName ?: 'arquivo';

        [$code, , $raw] = Http::json('POST', $this->baseUrl . '/chat/send/' . $tipo, ['Token: ' . $sessaoToken], $payload);
        return ['ok' => $code >= 200 && $code < 300, 'detalhe' => $raw];
    }

    // ── Download sob demanda (entrada: mídia que chegou com -skipmedia) ──
    //
    // A regra que mantém a memória sob controle: NUNCA deixar o wuzapi
    // baixar mídia sozinho. Aqui é sempre 1 arquivo, sob pedido, só das
    // mensagens que vão de fato ser encaminhadas.

    /**
     * @param 'image'|'audio'|'video'|'document' $tipo
     * @param array{url:string,mediaKey:string,mimetype:string,fileSha256:string,fileLength:int,fileEncSha256?:string,directPath?:?string} $ref
     * @return array{ok:bool,base64:?string,erro:?string}
     *
     * ⚠️ Causa do "no url present" (conferida no handlers.go do commit
     * preso, `DownloadImage()`/`DownloadAudio()`/
     * `DownloadVideo()`/`DownloadDocument()`): o handler monta a
     * `waE2E.ImageMessage`/... com `URL` **e** `DirectPath` — e é o
     * `whatsmeow.Download()` (chamado por baixo) quem decide como buscar
     * o arquivo, priorizando `DirectPath` (é o caminho atual do CDN da
     * Meta; `URL` é mais um campo legado). Mandar `Url` sem `DirectPath`
     * faz toda mídia falhar com "no url present" mesmo havendo uma URL de
     * verdade no payload. O `-skipmedia` só impede o wuzapi de baixar
     * sozinho: a referência da mídia sempre vem no evento, e o que faltava
     * era REPASSAR o `directPath` daqui pra frente.
     */
    public function baixarMidia(string $sessaoToken, string $tipo, array $ref): array
    {
        $endpoint = ['image' => 'downloadimage', 'audio' => 'downloadaudio', 'video' => 'downloadvideo', 'document' => 'downloaddocument'][$tipo] ?? null;
        if ($endpoint === null) {
            return ['ok' => false, 'base64' => null, 'erro' => "tipo de mídia desconhecido: {$tipo}"];
        }
        // Curto-circuito: sem Url NEM DirectPath não tem o que pedir —
        // poupa uma chamada HTTP que sabemos que vai falhar.
        $temUrl = trim((string) ($ref['url'] ?? '')) !== '';
        $temDirectPath = trim((string) ($ref['directPath'] ?? '')) !== '';
        if (!$temUrl && !$temDirectPath) {
            return ['ok' => false, 'base64' => null, 'erro' => 'sem Url nem DirectPath no payload de origem — ver payload bruto no log de entrada (Translator.php não recebeu referência utilizável)'];
        }
        $payload = [
            'Url'        => $ref['url'],
            'DirectPath' => $ref['directPath'] ?? '',
            'Mimetype'   => $ref['mimetype'],
            'FileSHA256' => $ref['fileSha256'],
            'FileLength' => $ref['fileLength'],
            'MediaKey'   => $ref['mediaKey'],
        ];
        if (!empty($ref['fileEncSha256'])) $payload['FileEncSHA256'] = $ref['fileEncSha256'];

        [$code, $body, $raw] = Http::json('POST', $this->baseUrl . '/chat/' . $endpoint, ['Token: ' . $sessaoToken], $payload, 60);
        if ($code === 200 && !empty($body['data'])) {
            // O wuzapi devolve o campo em chaves diferentes conforme a versão
            // (ex: "Data"/"Base64"/"data:...;base64,..."); aceitamos as
            // variações conhecidas em vez de travar num nome só.
            $d = $body['data'];
            foreach (['Base64', 'base64', 'Data', 'data'] as $k) {
                if (!empty($d[$k]) && is_string($d[$k])) {
                    $val = $d[$k];
                    $b64 = str_contains($val, 'base64,') ? substr($val, strpos($val, 'base64,') + 7) : $val;
                    return ['ok' => true, 'base64' => $b64, 'erro' => null];
                }
            }
        }
        return ['ok' => false, 'base64' => null, 'erro' => "HTTP {$code}: {$raw}"];
    }
}
