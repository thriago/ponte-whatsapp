<?php
// public/index.php — front controller único. Sem framework, sem rota
// mágica: um switch com regex, de propósito. Roda sob `php -S`
// (entrypoint.sh).
declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/EventLog.php';
require_once __DIR__ . '/../src/WuzapiClient.php';
require_once __DIR__ . '/../src/GuiaChatClient.php';
require_once __DIR__ . '/../src/Translator.php';
require_once __DIR__ . '/../src/Allowlist.php';
require_once __DIR__ . '/../src/Envios.php';

Auth::iniciarSessao();

const AVISO_RISCO = 'Esta ferramenta usa uma API não-oficial do WhatsApp. O número pareado pode '
    . 'ser bloqueado pela Meta a qualquer momento, sem aviso e sem recurso — inclusive nos '
    . 'primeiros dias de uso. Não use um número que você não possa perder. Não é homologada '
    . 'pela Meta, não tem garantia e não tem suporte.';

// Mesmo teto que a API do canal aceita no upload de mídia de entrada —
// baixar mais que isso do wuzapi seria trabalho jogado fora.
const LIMITE_MIDIA_BYTES = 20 * 1024 * 1024;

function wuzapi(): WuzapiClient
{
    return new WuzapiClient(getenv('WUZAPI_BASE_URL') ?: 'http://wuzapi:8080', getenv('WUZAPI_ADMIN_TOKEN') ?: '');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function layout(string $titulo, string $corpo, bool $comMenu = true): void
{
    $flashes = Auth::consumirFlash();
    $flashHtml = '';
    foreach ($flashes as $f) {
        $flashHtml .= '<div class="flash flash-' . h($f['tipo']) . '">' . h($f['msg']) . '</div>';
    }
    $menu = '';
    if ($comMenu) {
        $menu = '<nav class="menu">'
            . '<a href="/sessoes">Sessões</a>'
            . '<a href="/eventos">Últimos eventos</a>'
            . '<span class="menu-user">' . h($_SESSION['admin_username'] ?? '') . '</span>'
            . '<a href="/logout">Sair</a>'
            . '</nav>';
    }
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        . '<title>' . h($titulo) . '</title>'
        . '<link rel="stylesheet" href="/style.css"></head><body>'
        . '<div class="aviso-risco">⚠ ' . h(AVISO_RISCO) . '</div>'
        // Destaque em vermelho é pro modo HOMOLOGAÇÃO (lista preenchida): é o
        // estado excepcional, em que a maioria dos clientes não é atendida e
        // ninguém pode descobrir isso por acidente. Lista vazia é o normal.
        . '<div class="aviso-allowlist' . (Allowlist::vazia() ? '' : ' aviso-allowlist-homologacao') . '">'
        . (Allowlist::vazia() ? '✓ ' : '🔒 ') . h(Allowlist::resumoParaUi()) . '</div>'
        . $menu
        . '<main>' . $flashHtml . $corpo . '</main>'
        . '</body></html>';
}

function exigirLogin(): void
{
    if (!Auth::logado()) {
        header('Location: /login');
        exit;
    }
    if (Auth::precisaTrocarSenha() && ($_SERVER['REQUEST_URI'] ?? '') !== '/trocar-senha') {
        header('Location: /trocar-senha');
        exit;
    }
}

/**
 * ACL de IP — vazio = confia só no bind do docker-compose
 * (`127.0.0.1:${UI_PORT}:8090`), que é a fronteira real: só processo rodando
 * NA MESMA máquina alcança a porta. Não se aplica ao webhook interno (rede
 * docker, wuzapi -> ponte).
 *
 * ⚠️ No Docker Desktop (Windows e Mac), tráfego do HOST pro container, mesmo
 * saindo de 127.0.0.1 do lado de fora, chega ao container com REMOTE_ADDR =
 * gateway da rede docker (ex: 172.18.0.1), NÃO 127.0.0.1 — quem faz essa
 * tradução é o vpnkit. Ou seja: **checar REMOTE_ADDR==127.0.0.1 bloquearia
 * quem instalou**, rodando na própria máquina. Por isso, com ACL_IPS vazio
 * (o padrão recomendado — interface só local), não barramos por IP aqui: o
 * bind do compose já resolve. Só quando ACL_IPS é PREENCHIDO (decisão
 * consciente de expor a porta pra LAN, mudando o bind pra 0.0.0.0) é que
 * este filtro entra em ação — e mesmo aí, confirme o REMOTE_ADDR real no
 * SO de destino antes de confiar cegamente (o mesmo vpnkit pode mascarar
 * IP de LAN também; em Docker Engine puro no Linux isso não acontece).
 */
function checarAclIp(): void
{
    $lista = array_filter(array_map('trim', explode(',', (string) (getenv('ACL_IPS') ?: ''))));
    if (!$lista) return; // confia no bind do compose — ver nota acima

    $remoto = $_SERVER['REMOTE_ADDR'] ?? '';
    if (in_array($remoto, ['127.0.0.1', '::1'], true)) return;
    if (in_array($remoto, $lista, true)) return;
    http_response_code(403);
    echo 'Acesso negado para este IP (' . h($remoto) . '). Adicione este IP em ACL_IPS no .env se isto for esperado.';
    exit;
}

function csrfCampo(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(Auth::csrfToken()) . '">';
}

function exigirCsrf(): void
{
    if (!Auth::csrfValido($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        echo 'CSRF inválido — recarregue a página e tente de novo.';
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════
//  ROTEAMENTO
// ══════════════════════════════════════════════════════════════════════

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Serve estático simples (style.css) sem passar pelo resto do roteador.
if ($path === '/style.css') {
    header('Content-Type: text/css; charset=utf-8');
    readfile(__DIR__ . '/style.css');
    exit;
}

// Webhook do wuzapi: rede docker interna, sem ACL de IP de navegador.
if ($metodo === 'POST' && preg_match('#^/webhook/wuzapi/(\d+)$#', $path, $m)) {
    rotaWebhookWuzapi((int) $m[1]);
    exit;
}

checarAclIp();

switch (true) {
    case $path === '/' :
        header('Location: ' . (Auth::logado() ? '/sessoes' : '/login'));
        break;

    case $path === '/login' && $metodo === 'GET':
        if (Auth::logado()) { header('Location: /sessoes'); break; }
        layout('Entrar', viewLogin(), false);
        break;

    case $path === '/login' && $metodo === 'POST':
        exigirCsrf();
        $ok = Auth::tentarLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($ok) {
            header('Location: ' . (Auth::precisaTrocarSenha() ? '/trocar-senha' : '/sessoes'));
        } else {
            Auth::flash('Usuário ou senha inválidos.', 'erro');
            header('Location: /login');
        }
        break;

    case $path === '/logout':
        Auth::sair();
        header('Location: /login');
        break;

    case $path === '/trocar-senha' && $metodo === 'GET':
        exigirLogin();
        layout('Trocar senha', viewTrocarSenha());
        break;

    case $path === '/trocar-senha' && $metodo === 'POST':
        exigirLogin();
        exigirCsrf();
        $nova = (string) ($_POST['nova_senha'] ?? '');
        $confirma = (string) ($_POST['confirma_senha'] ?? '');
        if (strlen($nova) < 8) {
            Auth::flash('A nova senha precisa ter pelo menos 8 caracteres.', 'erro');
            header('Location: /trocar-senha');
        } elseif ($nova !== $confirma) {
            Auth::flash('As senhas não conferem.', 'erro');
            header('Location: /trocar-senha');
        } else {
            Auth::trocarSenha($nova);
            Auth::flash('Senha trocada com sucesso.', 'ok');
            header('Location: /sessoes');
        }
        break;

    case $path === '/sessoes' && $metodo === 'GET':
        exigirLogin();
        layout('Sessões', viewSessoes());
        break;

    case $path === '/sessoes/nova' && $metodo === 'POST':
        exigirLogin();
        exigirCsrf();
        rotaCriarSessao();
        break;

    case (bool) preg_match('#^/sessoes/(\d+)/remover$#', $path, $m) && $metodo === 'POST':
        exigirLogin();
        exigirCsrf();
        rotaRemoverSessao((int) $m[1]);
        break;

    case (bool) preg_match('#^/sessoes/(\d+)/desconectar$#', $path, $m) && $metodo === 'POST':
        exigirLogin();
        exigirCsrf();
        rotaDesconectarSessao((int) $m[1]);
        break;

    case (bool) preg_match('#^/sessoes/(\d+)/qr$#', $path, $m) && $metodo === 'GET':
        exigirLogin();
        rotaTelaQr((int) $m[1]);
        break;

    case (bool) preg_match('#^/api/sessoes/(\d+)/status$#', $path, $m) && $metodo === 'GET':
        exigirLogin();
        rotaApiStatus((int) $m[1]);
        break;

    case (bool) preg_match('#^/sessoes/(\d+)/integrar$#', $path, $m) && $metodo === 'GET':
        exigirLogin();
        rotaTelaIntegrar((int) $m[1]);
        break;

    case (bool) preg_match('#^/sessoes/(\d+)/integrar$#', $path, $m) && $metodo === 'POST':
        exigirLogin();
        exigirCsrf();
        rotaSalvarIntegrar((int) $m[1]);
        break;

    case (bool) preg_match('#^/sessoes/(\d+)/testar$#', $path, $m) && $metodo === 'POST':
        exigirLogin();
        exigirCsrf();
        rotaTestarConexao((int) $m[1]);
        break;

    case $path === '/eventos' && $metodo === 'GET':
        exigirLogin();
        layout('Últimos eventos', viewEventos());
        break;

    default:
        http_response_code(404);
        layout('Não encontrado', '<p>Página não encontrada.</p>', Auth::logado());
}

// ══════════════════════════════════════════════════════════════════════
//  VIEWS
// ══════════════════════════════════════════════════════════════════════

function viewLogin(): string
{
    return '<div class="card card-login">'
        . '<h1>Entrar</h1>'
        . '<form method="post" action="/login">'
        . csrfCampo()
        . '<label>Usuário<br><input type="text" name="username" autofocus required></label><br>'
        . '<label>Senha<br><input type="password" name="password" required></label><br>'
        . '<button type="submit">Entrar</button>'
        . '</form>'
        . '</div>';
}

function viewTrocarSenha(): string
{
    $obrigatorio = Auth::precisaTrocarSenha();
    $aviso = $obrigatorio ? '<p class="aviso-inline">Troca obrigatória no primeiro acesso.</p>' : '';
    return '<div class="card">'
        . '<h1>Trocar senha</h1>' . $aviso
        . '<form method="post" action="/trocar-senha">'
        . csrfCampo()
        . '<label>Nova senha (mín. 8 caracteres)<br><input type="password" name="nova_senha" required minlength="8"></label><br>'
        . '<label>Confirmar<br><input type="password" name="confirma_senha" required minlength="8"></label><br>'
        . '<button type="submit">Trocar</button>'
        . '</form></div>';
}

/**
 * Consulta o wuzapi DE VERDADE e atualiza a linha da sessão — usado tanto na
 * lista (Tela 2) quanto no poll da tela de QR. Existe pra nunca mostrar um
 * status cacheado que ficou pra trás. Status errado aqui trava a instalação
 * inteira: a lista fica em "Aguardando QR" mesmo com a sessão já pareada, e
 * sem chegar em "conectado" não existe caminho na interface pra "Integrar".
 *
 * Não REGRIDE sozinho: uma sessão já `conectado` só volta a outro estado por
 * ação explícita (desconectar/remover) — uma falha transitória de rede
 * consultando o wuzapi não pode fazer o usuário reler o QR à toa.
 */
function statusAoVivo(array $s): array
{
    $st = wuzapi()->status((string) $s['wuzapi_token']);
    if ($st['loggedIn']) {
        // Mesmo corte de Translator::digitosDoJid() — o JID pode vir com
        // sufixo de aparelho (":76", multi-device), que não é parte do
        // número. Aqui é só exibição, mas o formato do JID é o mesmo.
        $numero = $st['jid'] !== null ? explode(':', explode('@', $st['jid'])[0], 2)[0] : null;
        if (($s['status'] ?? null) !== 'conectado') {
            EventLog::registrar((int) $s['id'], 'sistema', 'sessao_conectada', null, "numero={$numero}");
        }
        Db::get()->prepare("UPDATE sessoes SET status='conectado', numero=? WHERE id = ?")->execute([$numero, $s['id']]);
        $s['status'] = 'conectado';
        $s['numero'] = $numero;
    }
    return $s;
}

function viewSessoes(): string
{
    $sessoes = Db::get()->query('SELECT * FROM sessoes ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    $sessoes = array_map('statusAoVivo', $sessoes);
    $linhas = '';
    foreach ($sessoes as $s) {
        $statusLabel = ['desconectado' => 'Desconectado', 'aguardando_qr' => 'Aguardando QR', 'conectado' => 'Conectado'][$s['status']] ?? h($s['status']);
        $linhas .= '<tr>'
            . '<td>' . h($s['nome']) . '</td>'
            . '<td>' . h($statusLabel) . '</td>'
            . '<td>' . h($s['numero'] ?: '—') . '</td>'
            . '<td><code>' . h($s['wuzapi_token']) . '</code></td>'
            . '<td>' . h($s['guiachat_url'] ? 'configurado' : '— não integrado —') . '</td>'
            . '<td class="acoes">'
            . '<a href="/sessoes/' . (int) $s['id'] . '/qr">conectar / QR</a>'
            . ($s['status'] === 'conectado' ? ' · <a href="/sessoes/' . (int) $s['id'] . '/integrar">integrar</a>' : '')
            . ' · <form method="post" action="/sessoes/' . (int) $s['id'] . '/desconectar" style="display:inline">' . csrfCampo() . '<button type="submit" class="link">desconectar</button></form>'
            . ' · <form method="post" action="/sessoes/' . (int) $s['id'] . '/remover" style="display:inline" onsubmit="return confirm(\'Remover esta sessão? Isso desfaz o pareamento.\')">' . csrfCampo() . '<button type="submit" class="link link-perigo">remover</button></form>'
            . '</td></tr>';
    }
    if ($linhas === '') $linhas = '<tr><td colspan="6"><em>Nenhuma sessão ainda.</em></td></tr>';

    return '<h1>Sessões</h1>'
        . '<table><thead><tr><th>Nome</th><th>Status</th><th>Número</th><th>Token</th><th>GuiaChat</th><th>Ações</th></tr></thead>'
        . '<tbody>' . $linhas . '</tbody></table>'
        . '<h2>Nova sessão</h2>'
        . '<form method="post" action="/sessoes/nova" class="form-inline">'
        . csrfCampo()
        . '<label>Nome (ex: Comercial, Suporte)<br><input type="text" name="nome" required></label> '
        . '<button type="submit">Nova sessão</button>'
        . '</form>';
}

function viewEventos(): string
{
    $eventos = EventLog::ultimos(100);
    $linhas = '';
    foreach ($eventos as $e) {
        $cor = (str_contains($e['tipo'], 'falhou') || str_contains($e['tipo'], 'descartada') || $e['tipo'] === 'sessao_caiu') ? 'evento-erro' : (str_contains($e['tipo'], 'enviado') || str_contains($e['tipo'], 'confirmar') ? 'evento-ok' : '');
        $linhas .= '<tr class="' . $cor . '">'
            . '<td>' . h($e['criado_em']) . '</td>'
            . '<td>' . h($e['direcao']) . '</td>'
            . '<td>' . h($e['sessao_nome'] ?: '—') . '</td>'
            . '<td>' . h($e['tipo']) . '</td>'
            . '<td>' . h((string) ($e['http_code'] ?? '—')) . '</td>'
            . '<td class="detalhe">' . h(mb_substr((string) $e['detalhe'], 0, 300)) . '</td>'
            . '</tr>';
    }
    if ($linhas === '') $linhas = '<tr><td colspan="6"><em>Nada ainda.</em></td></tr>';
    return '<h1>Últimos eventos</h1><p>As últimas 100 linhas — o que entrou, o que saiu, o que falhou.</p>'
        . '<table><thead><tr><th>Quando</th><th>Direção</th><th>Sessão</th><th>Tipo</th><th>HTTP</th><th>Detalhe</th></tr></thead>'
        . '<tbody>' . $linhas . '</tbody></table>';
}

// ══════════════════════════════════════════════════════════════════════
//  ROTAS COM LÓGICA
// ══════════════════════════════════════════════════════════════════════

function rotaCriarSessao(): void
{
    $nome = trim((string) ($_POST['nome'] ?? ''));
    if ($nome === '') {
        Auth::flash('Dê um nome pra sessão.', 'erro');
        header('Location: /sessoes');
        return;
    }

    $pdo = Db::get();
    $token = 'wz_' . bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO sessoes (nome, wuzapi_token, status, criado_em) VALUES (?, ?, ?, ?)');
    $stmt->execute([$nome, $token, 'desconectado', Db::agora()]);
    $id = (int) $pdo->lastInsertId();

    $webhookUrl = rtrim((string) (getenv('PONTE_INTERNAL_URL') ?: 'http://ponte:8090'), '/') . '/webhook/wuzapi/' . $id;
    $r = wuzapi()->criarUsuario($nome, $token, $webhookUrl);
    if (!$r['ok']) {
        $pdo->prepare('DELETE FROM sessoes WHERE id = ?')->execute([$id]);
        EventLog::registrar(null, 'sistema', 'criar_sessao_falhou', null, $r['erro'] ?? 'sem detalhe');
        Auth::flash('Não consegui criar a sessão no wuzapi: ' . ($r['erro'] ?? '?'), 'erro');
        header('Location: /sessoes');
        return;
    }

    $pdo->prepare('UPDATE sessoes SET wuzapi_user_id = ? WHERE id = ?')->execute([$r['id'], $id]);
    EventLog::registrar($id, 'sistema', 'sessao_criada', 200, "nome={$nome}");
    Auth::flash('Sessão "' . $nome . '" criada. Clique em "conectar / QR" pra parear o WhatsApp.', 'ok');
    header('Location: /sessoes');
}

function rotaRemoverSessao(int $id): void
{
    $stmt = Db::get()->prepare('SELECT * FROM sessoes WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        if (!empty($s['wuzapi_user_id'])) {
            wuzapi()->removerUsuario((string) $s['wuzapi_user_id']);
        }
        Db::get()->prepare('DELETE FROM sessoes WHERE id = ?')->execute([$id]);
        EventLog::registrar(null, 'sistema', 'sessao_removida', null, "id={$id} nome={$s['nome']}");
        Auth::flash('Sessão removida.', 'ok');
    }
    header('Location: /sessoes');
}

function rotaDesconectarSessao(int $id): void
{
    $stmt = Db::get()->prepare('SELECT * FROM sessoes WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($s) {
        wuzapi()->desconectar((string) $s['wuzapi_token']);
        Db::get()->prepare("UPDATE sessoes SET status='desconectado', numero=NULL WHERE id = ?")->execute([$id]);
        Auth::flash('Sessão desconectada.', 'ok');
    }
    header('Location: /sessoes');
}

function sessaoOu404(int $id): ?array
{
    $stmt = Db::get()->prepare('SELECT * FROM sessoes WHERE id = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) {
        http_response_code(404);
        layout('Sessão não encontrada', '<p>Sessão não encontrada.</p>');
        exit;
    }
    return $s;
}

function rotaTelaQr(int $id): void
{
    $s = sessaoOu404($id);
    // Dispara o connect UMA vez ao entrar na tela — não a cada poll do AJAX
    // — a tela atualiza sozinha, sem passo manual de "clique pra verificar".
    if ($s['status'] !== 'conectado') {
        wuzapi()->conectar((string) $s['wuzapi_token']);
        Db::get()->prepare("UPDATE sessoes SET status='aguardando_qr' WHERE id = ?")->execute([$id]);
    }
    $corpo = '<h1>Conectar — ' . h($s['nome']) . '</h1>'
        . '<div id="qr-area"><p>Carregando QR…</p></div>'
        . '<p><a href="/sessoes">&larr; voltar pra sessões</a></p>'
        . '<script>
(function() {
    var area = document.getElementById("qr-area");
    function poll() {
        fetch("/api/sessoes/' . (int) $id . '/status").then(function(r){ return r.json(); }).then(function(d){
            if (d.status === "conectado") {
                area.innerHTML = "<p class=\"ok-grande\">✔ Conectado" + (d.numero ? (" — " + d.numero) : "") + "</p>"
                    + "<p><a href=\"/sessoes/' . (int) $id . '/integrar\">Continuar para Integrar &rarr;</a></p>";
                return;
            }
            if (d.qrcode) {
                area.innerHTML = "<p>Abra o WhatsApp no celular &rarr; Aparelhos conectados &rarr; Conectar um aparelho &rarr; aponte pra este QR:</p>"
                    + "<img src=\"" + d.qrcode + "\" alt=\"QR code\" style=\"width:280px;height:280px\">";
            } else {
                area.innerHTML = "<p>Aguardando o wuzapi gerar o QR…</p>";
            }
            setTimeout(poll, 2000);
        }).catch(function(){ setTimeout(poll, 3000); });
    }
    poll();
})();
</script>';
    layout('Conectar', $corpo);
}

function rotaApiStatus(int $id): void
{
    header('Content-Type: application/json; charset=utf-8');
    $s = sessaoOu404($id);
    $s = statusAoVivo($s); // mesma checagem ao vivo da lista — ver comentário na função

    if ($s['status'] === 'conectado') {
        echo json_encode(['status' => 'conectado', 'numero' => $s['numero']]);
        return;
    }

    Db::get()->prepare("UPDATE sessoes SET status='aguardando_qr' WHERE id = ? AND status != 'conectado'")->execute([$id]);
    $qr = wuzapi()->qr((string) $s['wuzapi_token']);
    echo json_encode(['status' => 'aguardando_qr', 'qrcode' => $qr]);
}

function rotaTelaIntegrar(int $id): void
{
    $s = sessaoOu404($id);
    if ($s['status'] !== 'conectado') {
        Auth::flash('Pareie o WhatsApp primeiro — a ordem é parear, depois integrar.', 'erro');
        header('Location: /sessoes/' . $id . '/qr');
        return;
    }
    $corpo = '<h1>Integrar — ' . h($s['nome']) . '</h1>'
        . '<p>Duas coisas, e só elas: a URL do GuiaChat e o token do canal (o <code>gc_int_…</code> '
        . 'que a tela do canal gerou).</p>'
        . '<form method="post" action="/sessoes/' . $id . '/integrar">'
        . csrfCampo()
        . '<label>URL do GuiaChat<br><input type="url" name="guiachat_url" value="' . h($s['guiachat_url'] ?: '') . '" placeholder="https://seu-tenant.exemplo.com.br" required style="width:100%;max-width:480px"></label><br>'
        . '<label>Token do canal<br><input type="text" name="guiachat_token" value="' . h($s['guiachat_token'] ?: '') . '" placeholder="gc_int_..." required style="width:100%;max-width:480px"></label><br>'
        . '<button type="submit">Salvar</button>'
        . '</form>'
        . ($s['guiachat_url'] ? '<form method="post" action="/sessoes/' . $id . '/testar" style="margin-top:1em">' . csrfCampo() . '<button type="submit">Testar conexão</button></form>' : '')
        . '<p><a href="/sessoes">&larr; voltar pra sessões</a></p>';
    layout('Integrar', $corpo);
}

function rotaSalvarIntegrar(int $id): void
{
    sessaoOu404($id);
    $url = trim((string) ($_POST['guiachat_url'] ?? ''));
    $tok = trim((string) ($_POST['guiachat_token'] ?? ''));
    if ($url === '' || $tok === '') {
        Auth::flash('Preencha os dois campos.', 'erro');
        header('Location: /sessoes/' . $id . '/integrar');
        return;
    }
    Db::get()->prepare('UPDATE sessoes SET guiachat_url = ?, guiachat_token = ? WHERE id = ?')->execute([rtrim($url, '/'), $tok, $id]);
    EventLog::registrar($id, 'sistema', 'integrar_salvo', null, "url={$url}");
    Auth::flash('Salvo. Clique em "Testar conexão" pra confirmar que o token vale.', 'ok');
    header('Location: /sessoes/' . $id . '/integrar');
}

function rotaTestarConexao(int $id): void
{
    $s = sessaoOu404($id);
    if (empty($s['guiachat_url']) || empty($s['guiachat_token'])) {
        Auth::flash('Preencha e salve a URL e o token antes de testar.', 'erro');
        header('Location: /sessoes/' . $id . '/integrar');
        return;
    }
    $gc = new GuiaChatClient((string) $s['guiachat_url'], (string) $s['guiachat_token']);
    $r = $gc->testarConexao();
    EventLog::registrar($id, 'sistema', 'testar_conexao', null, $r['mensagem']);
    Auth::flash($r['mensagem'], $r['ok'] ? 'ok' : 'erro');
    header('Location: /sessoes/' . $id . '/integrar');
}

/**
 * Recibo de entregue/lido -> POST /canal/status, só pras mensagens que a
 * própria ponte enviou (as que estão em `envios` com o mesmo wa_id). Recibo
 * de qualquer outra coisa (status, grupo, mensagem mandada pelo celular) é
 * ignorado em silêncio: são muitos e não interessam ao atendimento.
 */
function repassarRecibo(array $s, array $envelope): void
{
    $estado = (string) ($envelope['state'] ?? '');
    $status = ['Delivered' => 'entregue', 'Read' => 'lido'][$estado] ?? null;
    if ($status === null) return;

    $evento = $envelope['event'] ?? $envelope['Event'] ?? null;
    $ids = is_array($evento) ? ($evento['MessageIDs'] ?? $evento['MessageIds'] ?? []) : [];
    if (!is_array($ids)) return;

    $gc = new GuiaChatClient((string) $s['guiachat_url'], (string) $s['guiachat_token']);
    foreach ($ids as $waId) {
        if (!is_string($waId) || $waId === '') continue;
        if (Envios::porWaId((int) $s['id'], $waId) === null) continue;
        [$code, $resp] = $gc->status($waId, $status);
        EventLog::registrar((int) $s['id'], 'saida', 'recibo_' . $status, $code, "mensagem {$waId}: " . json_encode($resp));
    }
}

/**
 * ENTRADA (WhatsApp -> sistema de atendimento). Recebe o webhook do wuzapi,
 * traduz (ver src/Translator.php) e repassa.
 */
function rotaWebhookWuzapi(int $sessaoId): void
{
    $corpoBruto = file_get_contents('php://input') ?: '';
    $assinatura = $_SERVER['HTTP_X_HMAC_SIGNATURE'] ?? '';
    $chave = getenv('WUZAPI_HMAC_KEY') ?: '';

    // Confere (loga match/mismatch) mas não bloqueia ainda — ver nota em
    // Translator.php sobre confirmar o formato exato com o número real
    // pareado amanhã. A fronteira principal aqui é a rede docker interna.
    if ($chave !== '' && $assinatura !== '') {
        $esperada = hash_hmac('sha256', $corpoBruto, $chave);
        if (!hash_equals($esperada, $assinatura)) {
            EventLog::registrar($sessaoId, 'entrada', 'hmac_nao_bate', null, 'x-hmac-signature não confere com a chave configurada — ver Translator.php');
        }
    }

    $envelope = json_decode($corpoBruto, true);
    if (!is_array($envelope)) {
        EventLog::registrar($sessaoId, 'entrada', 'payload_invalido', null, substr($corpoBruto, 0, 500));
        http_response_code(200); // wuzapi não precisa de retry aqui — payload não vai virar JSON só de repetir
        echo json_encode(['ok' => true]);
        return;
    }

    $stmt = Db::get()->prepare('SELECT * FROM sessoes WHERE id = ?');
    $stmt->execute([$sessaoId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);

    // O WhatsApp desconectou o aparelho (removido em "Aparelhos conectados",
    // ou derrubado pelo próprio WhatsApp). Sem isto a tela seguia mostrando
    // "Conectado" com a sessão morta.
    $tipoEvento = (string) ($envelope['type'] ?? $envelope['Type'] ?? '');
    if ($tipoEvento === 'LoggedOut') {
        if ($s) {
            Db::get()->prepare("UPDATE sessoes SET status = 'desconectado' WHERE id = ?")->execute([$sessaoId]);
            EventLog::registrar($sessaoId, 'sistema', 'sessao_caiu', null, 'o WhatsApp desconectou este aparelho — leia o QR de novo em "conectar / QR"');
        }
        echo json_encode(['ok' => true]);
        return;
    }
    if ($tipoEvento === 'ReadReceipt') {
        if ($s && !empty($s['guiachat_url']) && !empty($s['guiachat_token'])) {
            repassarRecibo($s, $envelope);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    if (!$s || empty($s['guiachat_url']) || empty($s['guiachat_token'])) {
        EventLog::registrar($sessaoId, 'entrada', 'sessao_sem_integracao', null, 'sessão não integrada ainda (falta URL/token do GuiaChat) — payload bruto: ' . substr($corpoBruto, 0, 6000));
        echo json_encode(['ok' => true]);
        return;
    }

    $trad = Translator::wuzapiEventParaGuiaChat($envelope);
    if ($trad['ignorar']) {
        EventLog::registrar($sessaoId, 'entrada', 'ignorado', null, $trad['motivo'] . ' — payload bruto: ' . substr($corpoBruto, 0, 6000));
        echo json_encode(['ok' => true]);
        return;
    }

    $gc = new GuiaChatClient((string) $s['guiachat_url'], (string) $s['guiachat_token']);

    if ($trad['midiaRef'] === null) {
        [$code, $resp] = $gc->enviarMensagemTexto($trad['idExterno'], $trad['de'], (string) $trad['texto'], $trad['nome']);
        EventLog::registrar($sessaoId, 'entrada', 'mensagem_texto', $code, json_encode($resp));
        echo json_encode(['ok' => true]);
        return;
    }

    // Mídia: baixa do wuzapi (sob demanda — nunca deixamos o wuzapi
    // pré-baixar, é rodado com -skipmedia) e reencaminha por upload direto.
    $ref = $trad['midiaRef'];

    // Teto de tamanho ANTES de baixar: medir antes de materializar. O
    // `fileLength` já vem no payload de origem, então não é preciso puxar
    // um byte sequer pra saber se o arquivo é grande demais. O limite é o
    // mesmo teto que a API do canal aceita no upload (20 MB) — baixar mais
    // que isso é trabalho jogado fora, o upload seguinte recusaria.
    if ((int) ($ref['fileLength'] ?? 0) > LIMITE_MIDIA_BYTES) {
        $mb = round(((int) $ref['fileLength']) / 1024 / 1024, 1);
        $dl = ['ok' => false, 'base64' => null, 'erro' => "arquivo de {$mb}MB acima do limite de " . (LIMITE_MIDIA_BYTES / 1024 / 1024) . "MB — não baixado"];
    } else {
        $dl = wuzapi()->baixarMidia((string) $s['wuzapi_token'], $ref['tipoWuzapi'], $ref);
    }
    if (!$dl['ok']) {
        // ⚠️ Falha de download NÃO pode fazer a mensagem inteira sumir.
        // Sem este caminho de fallback, o cliente manda um áudio, o
        // download falha (por exemplo com uma referência de mídia que o
        // wuzapi não consegue usar — ver Translator.php) e ninguém fica
        // sabendo que ele falou: nem vira contato, nem aparece pro agente.
        // Num canal de atendimento isso é inaceitável — melhor uma conversa
        // incompleta que uma conversa invisível.
        //
        // Por isso a mensagem SEMPRE chega — como texto, com um marcador
        // claro do que era e do que houve, a legenda (se tinha) e o MESMO
        // id_externo (a idempotência do contrato continua valendo: se o
        // wuzapi reentregar o mesmo evento, o GuiaChat reconhece como
        // duplicata, não cria mensagem nova).
        //
        // O payload bruto vai pro log aqui também (antes só ia pra "ignorado"
        // e "sessao_sem_integracao") — sem isso não dava pra descobrir POR
        // QUE o download falhou (achado testando com mensagem real: o log
        // só guardava a string de erro do wuzapi, nunca o payload de origem).
        EventLog::registrar($sessaoId, 'entrada', 'download_midia_falhou', null,
            ($dl['erro'] ?? '?') . ' — payload bruto: ' . substr($corpoBruto, 0, 6000));

        $rotulo = ['imagem' => 'imagem', 'audio' => 'áudio', 'video' => 'vídeo', 'documento' => 'documento'][$trad['tipo']] ?? $trad['tipo'];
        $marcador = "[{$rotulo} — não foi possível baixar]";
        $textoFallback = ($trad['texto'] !== null && $trad['texto'] !== '')
            ? ($marcador . ' ' . $trad['texto'])
            : $marcador;

        [$code, $resp] = $gc->enviarMensagemTexto($trad['idExterno'], $trad['de'], $textoFallback, $trad['nome']);
        EventLog::registrar($sessaoId, 'entrada', 'mensagem_midia_fallback_texto', $code, json_encode($resp));
        echo json_encode(['ok' => true]);
        return;
    }

    $bytes = base64_decode($dl['base64'], true);
    $tmp = tempnam(sys_get_temp_dir(), 'gcint_');
    file_put_contents($tmp, $bytes);
    $ext = explode('/', $ref['mimetype'])[1] ?? 'bin';
    $nomeArquivo = 'midia_' . $trad['idExterno'] . '.' . $ext;

    [$code, $resp] = $gc->enviarMensagemComArquivo(
        $trad['idExterno'], $trad['de'], $trad['tipo'], $tmp, $ref['mimetype'], $nomeArquivo, $trad['texto'], $trad['nome']
    );
    @unlink($tmp);
    EventLog::registrar($sessaoId, 'entrada', 'mensagem_midia_' . $trad['tipo'], $code, json_encode($resp));
    echo json_encode(['ok' => true]);
}
