<?php
// bin/puxar_loop.php — o laço do modo `puxar` (a referência da API, em
// /doc/api da instalação, descreve o modo). Roda em segundo plano, separado
// do servidor web (entrypoint.sh). Para CADA sessão com URL e token
// preenchidos:
//
//   GET  <guiachat>/api/v1/canal/pendentes   (a cada N segundos)
//   ...envia no wuzapi...
//   POST <guiachat>/api/v1/canal/confirmar   (com o resultado de cada uma)
//
// Regra de ouro do contrato: ler não confirma; uma entrega não confirmada
// volta sozinha em 60s. Em cima disso, quatro regras da ponte:
//
// 1. Nunca enviar a mesma entrega duas vezes. O que já foi entregue ao
//    wuzapi fica registrado (src/Envios.php); se a entrega voltar da fila,
//    a ponte só reconfirma. Isso inclui o envio que estourou o tempo de
//    espera: o wuzapi não cancela, então ele provavelmente saiu.
// 2. Mensagem velha não sai. Passou de MENSAGEM_VALIDADE_MIN (padrão 5) sem
//    conseguir enviar, a entrega é confirmada como `falhou` e o agente vê o
//    motivo. Mandar a resposta de uma hora atrás, fora de contexto, é pior
//    que não mandar.
// 3. Sessão desconectada não acumula fila. Com o WhatsApp deslogado, a
//    entrega espera no máximo 1 minuto (cobre uma reconexão rápida) e depois
//    falha com o aviso de que é preciso ler o QR de novo.
// 4. O número é conferido no WhatsApp antes de enviar (nono dígito, ver
//    src/NumeroWhatsapp.php). Número sem WhatsApp falha com o motivo, em vez
//    de "enviar" pro nada.
//
// Um único consumidor por token, sempre (o contrato avisa: dois processos
// com o mesmo token colidem na trava de visibilidade). Este loop é esse
// único consumidor — não subir um segundo.
declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/WuzapiClient.php';
require_once __DIR__ . '/../src/GuiaChatClient.php';
require_once __DIR__ . '/../src/Translator.php';
require_once __DIR__ . '/../src/EventLog.php';
require_once __DIR__ . '/../src/Allowlist.php';
require_once __DIR__ . '/../src/Envios.php';
require_once __DIR__ . '/../src/NumeroWhatsapp.php';

$wuzapiBase  = getenv('WUZAPI_BASE_URL') ?: 'http://wuzapi:8080';
$adminToken  = getenv('WUZAPI_ADMIN_TOKEN') ?: '';
$webhookBase = rtrim((string) (getenv('PONTE_INTERNAL_URL') ?: 'http://ponte:8090'), '/') . '/webhook/wuzapi/';
$intervaloS  = 3;
$validadeMin = max(1, (int) (getenv('MENSAGEM_VALIDADE_MIN') ?: 5));
$validadeS   = $validadeMin * 60;
$esperaDesconectadoS = 60;

fwrite(STDOUT, "[puxar_loop] iniciado — intervalo={$intervaloS}s, validade={$validadeMin}min, wuzapi={$wuzapiBase}\n");
fwrite(STDOUT, "[puxar_loop] " . Allowlist::linhaDeBoot() . "\n");

$eventosAssinados = [];
$ultimaLimpeza    = 0;

/** Marca a sessão como desconectada na tela (uma vez, quando muda). */
function sessaoCaiu(int $sid, string $motivo): void
{
    $upd = Db::get()->prepare("UPDATE sessoes SET status = 'desconectado' WHERE id = ? AND status = 'conectado'");
    $upd->execute([$sid]);
    if ($upd->rowCount() > 0) {
        EventLog::registrar($sid, 'sistema', 'sessao_caiu', null, $motivo . ' — leia o QR de novo em "conectar / QR"');
    }
}

while (true) {
    try {
        $sessoes = Db::get()->query("
            SELECT * FROM sessoes
            WHERE guiachat_url IS NOT NULL AND guiachat_url != ''
              AND guiachat_token IS NOT NULL AND guiachat_token != ''
              AND modo_entrega = 'puxar'
        ")->fetchAll(PDO::FETCH_ASSOC);
        if (time() - $ultimaLimpeza > 3600) {
            Envios::limparAntigos();
            $ultimaLimpeza = time();
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "[puxar_loop] erro lendo sessoes: {$e->getMessage()}\n");
        sleep($intervaloS);
        continue;
    }

    foreach ($sessoes as $s) {
        $sid    = (int) $s['id'];
        $wzTok  = (string) $s['wuzapi_token'];
        $gc     = new GuiaChatClient((string) $s['guiachat_url'], (string) $s['guiachat_token']);
        $wz     = new WuzapiClient($wuzapiBase, $adminToken);

        // Sessões criadas antes dos recibos de entregue/lido assinavam só
        // "Message". Ajusta uma vez por execução do laço.
        if (empty($eventosAssinados[$sid]) && $wz->assinarEventos($wzTok, $webhookBase . $sid)) {
            $eventosAssinados[$sid] = true;
            EventLog::registrar($sid, 'sistema', 'eventos_assinados', null, implode(',', WuzapiClient::EVENTOS));
        }

        [$code, $body] = $gc->pendentes(50);
        if ($code !== 200 || !is_array($body) || empty($body['ok'])) {
            EventLog::registrar($sid, 'saida', 'pendentes_falhou', $code, json_encode($body ?? ['sem_corpo' => true]));
            continue;
        }

        $entregas = is_array($body['entregas'] ?? null) ? $body['entregas'] : [];
        if (!$entregas) continue;

        $st = $wz->status($wzTok);
        $deslogado = $st['respondeu'] && !$st['loggedIn'];
        if ($deslogado) sessaoCaiu($sid, 'o WhatsApp desta sessão está desconectado');

        $itens = [];
        $falhar = function (int $id, string $motivo) use ($sid, &$itens): void {
            Envios::marcar($sid, $id, 'falhou', null, $motivo);
            $itens[] = ['id' => $id, 'status' => 'falhou', 'erro' => $motivo];
            EventLog::registrar($sid, 'saida', 'entrega_descartada', null, "entrega #{$id}: {$motivo}");
        };

        foreach ($entregas as $entrega) {
            $id      = (int) ($entrega['id'] ?? 0);
            $payload = is_array($entrega['payload'] ?? null) ? $entrega['payload'] : [];
            $trad    = Translator::entregaGuiaChatParaWuzapi($payload);
            if ($id <= 0) continue;

            // ── Regra 1: já tratada antes, só reconfirma ────────────────
            $reg = Envios::registrarVista($sid, $id);
            $estado = (string) ($reg['estado'] ?? 'aguardando');
            if (in_array($estado, ['enviado', 'incerto', 'enviando'], true)) {
                // `enviando` aqui = o processo caiu no meio do envio; trata
                // como incerto, nunca como "não foi".
                if ($estado === 'enviando') Envios::marcar($sid, $id, 'incerto');
                $itens[] = ['id' => $id, 'status' => 'enviado', 'id_externo' => $reg['wa_id'] ?? null];
                EventLog::registrar($sid, 'saida', 'reconfirmado_sem_reenviar', null, "entrega #{$id} já tinha sido entregue ao wuzapi ({$estado}) — não reenviada");
                continue;
            }
            if ($estado === 'falhou') {
                $itens[] = ['id' => $id, 'status' => 'falhou', 'erro' => (string) ($reg['erro'] ?? 'falhou')];
                continue;
            }

            // ── Regra 2: velha demais ───────────────────────────────────
            $desde = $trad['enviadoEm'] ?? (int) strtotime((string) $reg['visto_em']);
            $idade = time() - $desde;
            if ($idade > $validadeS) {
                $falhar($id, 'mensagem descartada: ficou ' . (int) round($idade / 60) . " min sem conseguir sair pelo WhatsApp (limite {$validadeMin} min)");
                continue;
            }

            if ($trad['phone'] === '') {
                $falhar($id, 'entrega sem número de destino');
                continue;
            }

            // Allowlist: última barreira antes de alguém real receber. Fora
            // da lista nunca chama o wuzapi.
            if (!Allowlist::permitido($trad['phone'])) {
                $falhar($id, "destino {$trad['phone']} fora da lista de números permitidos (ALLOWLIST_NUMEROS=" . Allowlist::configuracaoBruta() . ')');
                continue;
            }

            // ── Regra 3: sessão desconectada ────────────────────────────
            if ($deslogado) {
                if ($idade >= $esperaDesconectadoS) {
                    $falhar($id, 'o WhatsApp da ponte está desconectado — leia o QR de novo na ponte');
                }
                continue; // mais nova que 1 min: espera uma reconexão rápida
            }

            // ── Regra 4: número como o WhatsApp conhece ─────────────────
            $num = NumeroWhatsapp::resolver($wz, $wzTok, $trad['phone']);
            if (!$num['existe']) {
                $falhar($id, "o número {$trad['phone']} não tem WhatsApp");
                continue;
            }
            $destino = $num['numero'];
            if ($num['consultou'] && $destino !== preg_replace('/\D+/', '', $trad['phone'])) {
                EventLog::registrar($sid, 'saida', 'numero_ajustado', null, "{$trad['phone']} está no WhatsApp como {$destino}");
            }

            // ── Envio ───────────────────────────────────────────────────
            if ($trad['tipo'] === 'text') {
                Envios::marcar($sid, $id, 'enviando');
                $resultado = $wz->enviarTexto($wzTok, $destino, (string) $trad['texto']);
            } elseif ($trad['midia'] !== null && !empty($trad['midia']['url'])) {
                // Baixa do GuiaChat (Bearer do canal) e reencaminha pro wuzapi
                // como data URI — mesma direção "sob demanda" da entrada,
                // agora ao contrário.
                [$dlCode, $bytes, $contentType] = $gc->baixarMidia((string) $trad['midia']['url']);
                if ($dlCode !== 200 || $bytes === null) {
                    EventLog::registrar($sid, 'saida', 'download_midia_falhou', $dlCode, "entrega #{$id}: " . (string) $trad['midia']['url']);
                    continue; // não confirma — tenta de novo no próximo poll, até a validade
                }
                $mime    = $contentType ?: 'application/octet-stream';
                $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                Envios::marcar($sid, $id, 'enviando');
                $resultado = $wz->enviarMidia($wzTok, $trad['tipo'], $destino, $dataUri, $trad['texto']);
            } else {
                $falhar($id, 'entrega sem texto nem mídia');
                continue;
            }

            if ($resultado['ok']) {
                Envios::marcar($sid, $id, 'enviado', $resultado['id']);
                $itens[] = ['id' => $id, 'status' => 'enviado', 'id_externo' => $resultado['id']];
                EventLog::registrar($sid, 'saida', 'enviado_wuzapi', 200, "entrega #{$id} para {$destino} (tipo={$trad['tipo']})");
            } elseif ($resultado['timeout']) {
                // Pode ter saído: confirma e NUNCA reenvia (regra 1).
                Envios::marcar($sid, $id, 'incerto');
                $itens[] = ['id' => $id, 'status' => 'enviado'];
                EventLog::registrar($sid, 'saida', 'envio_sem_resposta', null, "entrega #{$id} para {$destino}: o wuzapi não respondeu a tempo; tratada como enviada pra não duplicar — {$resultado['detalhe']}");
            } else {
                // Recusado: não saiu. Volta pra fila e tenta de novo até a validade.
                Envios::marcar($sid, $id, 'aguardando', null, $resultado['detalhe']);
                if ($resultado['semSessao']) sessaoCaiu($sid, 'o wuzapi não tem sessão ativa pra este número');
                EventLog::registrar($sid, 'saida', 'envio_wuzapi_falhou', null, "entrega #{$id}: " . $resultado['detalhe']);
            }
        }

        if ($itens) {
            [$ccode, $cbody] = $gc->confirmarResultados($itens);
            EventLog::registrar($sid, 'saida', 'confirmar', $ccode, json_encode($cbody));
        }
    }

    sleep($intervaloS);
}
