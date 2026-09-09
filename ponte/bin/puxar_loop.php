<?php
// bin/puxar_loop.php — o laço do modo `puxar` (a referência da API, em
// /doc/api da instalação, descreve o modo). Roda em segundo plano, separado
// do servidor web (entrypoint.sh). Para CADA sessão com URL e token
// preenchidos:
//
//   GET  <guiachat>/api/v1/canal/pendentes   (a cada N segundos)
//   ...envia no wuzapi...
//   POST <guiachat>/api/v1/canal/confirmar   (SÓ dos ids que realmente enviou)
//
// Regra de ouro do contrato (as "4 regras pra não duplicar mensagem"): ler
// não confirma, confirma só depois de entregar. Se o envio pro wuzapi falhar
// (por exemplo com a sessão ainda não pareada), a entrega NÃO é confirmada —
// ela volta sozinha em 60s pro próximo consumidor.
// Isso é comportamento correto, não bug: mensagem não enviada não pode
// desaparecer da fila.
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

$wuzapiBase  = getenv('WUZAPI_BASE_URL') ?: 'http://wuzapi:8080';
$adminToken  = getenv('WUZAPI_ADMIN_TOKEN') ?: '';
$intervaloS  = 3;

fwrite(STDOUT, "[puxar_loop] iniciado — intervalo={$intervaloS}s, wuzapi={$wuzapiBase}\n");
fwrite(STDOUT, "[puxar_loop] " . Allowlist::linhaDeBoot() . "\n");

while (true) {
    try {
        $sessoes = Db::get()->query("
            SELECT * FROM sessoes
            WHERE guiachat_url IS NOT NULL AND guiachat_url != ''
              AND guiachat_token IS NOT NULL AND guiachat_token != ''
              AND modo_entrega = 'puxar'
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        fwrite(STDERR, "[puxar_loop] erro lendo sessoes: {$e->getMessage()}\n");
        sleep($intervaloS);
        continue;
    }

    foreach ($sessoes as $s) {
        $sid = (int) $s['id'];
        $gc  = new GuiaChatClient((string) $s['guiachat_url'], (string) $s['guiachat_token']);
        $wz  = new WuzapiClient($wuzapiBase, $adminToken);

        [$code, $body] = $gc->pendentes(50);
        if ($code !== 200 || !is_array($body) || empty($body['ok'])) {
            EventLog::registrar($sid, 'saida', 'pendentes_falhou', $code, json_encode($body ?? ['sem_corpo' => true]));
            continue;
        }

        $entregas = is_array($body['entregas'] ?? null) ? $body['entregas'] : [];
        if (!$entregas) continue;

        $idsParaConfirmar = [];
        foreach ($entregas as $entrega) {
            $id      = (int) ($entrega['id'] ?? 0);
            $payload = is_array($entrega['payload'] ?? null) ? $entrega['payload'] : [];
            $trad    = Translator::entregaGuiaChatParaWuzapi($payload);

            if ($trad['phone'] === '') {
                EventLog::registrar($sid, 'saida', 'entrega_sem_destino', null, json_encode($payload));
                continue;
            }

            // ── Allowlist (filtro opcional de números) ──────────────────
            // Última barreira antes de alguém real receber uma mensagem: se
            // por qualquer caminho uma entrega chegou até aqui pra um
            // destinatário fora da lista, NUNCA chama o wuzapi. CONFIRMA
            // (não é falha transitória pra tentar de novo — é decisão
            // definitiva de não entregar) e loga o número bloqueado.
            if (!Allowlist::permitido($trad['phone'])) {
                $idsParaConfirmar[] = $id;
                EventLog::registrar($sid, 'saida', 'bloqueado_allowlist', null, "entrega #{$id}: destino {$trad['phone']} fora da allowlist — NÃO enviado ao wuzapi. ALLOWLIST_NUMEROS=" . Allowlist::configuracaoBruta());
                continue;
            }

            $resultado = null;
            if ($trad['tipo'] === 'text') {
                $resultado = $wz->enviarTexto((string) $s['wuzapi_token'], $trad['phone'], (string) $trad['texto']);
            } elseif ($trad['midia'] !== null && !empty($trad['midia']['url'])) {
                // Baixa do GuiaChat (Bearer do canal) e reencaminha pro wuzapi
                // como data URI — mesma direção "sob demanda" da entrada,
                // agora ao contrário.
                [$dlCode, $bytes, $contentType] = $gc->baixarMidia((string) $trad['midia']['url']);
                if ($dlCode !== 200 || $bytes === null) {
                    EventLog::registrar($sid, 'saida', 'download_midia_falhou', $dlCode, "entrega #{$id}: " . (string) $trad['midia']['url']);
                    continue; // não confirma — tenta de novo no próximo poll
                }
                $mime    = $contentType ?: 'application/octet-stream';
                $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                $resultado = $wz->enviarMidia((string) $s['wuzapi_token'], $trad['tipo'], $trad['phone'], $dataUri, $trad['texto']);
            } else {
                EventLog::registrar($sid, 'saida', 'entrega_tipo_sem_conteudo', null, json_encode($payload));
                continue;
            }

            if ($resultado['ok']) {
                $idsParaConfirmar[] = $id;
                EventLog::registrar($sid, 'saida', 'enviado_wuzapi', 200, "entrega #{$id} para {$trad['phone']} (tipo={$trad['tipo']})");
            } else {
                // NÃO confirma — a entrega some por 60s e volta sozinha
                // (regra 3 do contrato). Esperado hoje: sessão sem número
                // real pareado ainda, wuzapi recusa o envio.
                EventLog::registrar($sid, 'saida', 'envio_wuzapi_falhou', null, "entrega #{$id}: " . $resultado['detalhe']);
            }
        }

        if ($idsParaConfirmar) {
            [$ccode, $cbody] = $gc->confirmar($idsParaConfirmar);
            EventLog::registrar($sid, 'saida', 'confirmar', $ccode, json_encode($cbody));
        }
    }

    sleep($intervaloS);
}
