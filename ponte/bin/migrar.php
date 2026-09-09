<?php
// bin/migrar.php — roda no start do container (entrypoint.sh). Idempotente:
// cria as tabelas se não existirem, e semeia o admin inicial só se a tabela
// `admin` estiver vazia (nunca sobrescreve uma senha já trocada).
declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Allowlist.php';

Db::migrate();

// Em que modo a ponte subiu, no log de boot (docker compose logs ponte).
// Vazia = atende todos; preenchida = homologação. Quem lê o log precisa saber
// disso sem abrir arquivo nenhum.
fwrite(STDOUT, '[allowlist] ' . Allowlist::linhaDeBoot() . "\n");

$pdo = Db::get();
$existe = (int) $pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();

if ($existe === 0) {
    /* Se quem instalou escolheu a senha (o instalador pergunta), ela vale — e
       não faz sentido exigir troca no primeiro login de uma senha que a própria
       pessoa acabou de definir. Sem escolha, sorteia uma e obriga a troca. */
    $escolhida = trim((string) (getenv('SENHA_ADMIN_INICIAL') ?: ''));
    $daPessoa  = ($escolhida !== '' && mb_strlen($escolhida) >= 8);

    $senha = $daPessoa ? $escolhida : bin2hex(random_bytes(6)); // 12 chars, fácil de digitar
    $hash  = password_hash($senha, PASSWORD_BCRYPT);
    $pdo->prepare('INSERT INTO admin (username, password_hash, must_change_password, criado_em) VALUES (?, ?, ?, ?)')
        ->execute(['admin', $hash, $daPessoa ? 0 : 1, Db::agora()]);

    if ($daPessoa) {
        fwrite(STDOUT, "Admin criado com a senha definida na instalacao.\n");
    } else {
        // Duas formas de descobrir a senha: o log (o instalador e o senha.sh
        // leem daqui) e um arquivo ao lado do banco. O caminho acompanha o
        // DB_PATH pra funcionar também na instalação sem Docker, onde
        // /app/data não existe.
        $arquivo = dirname(getenv('DB_PATH') ?: '/app/data/app.db') . '/senha_inicial.txt';
        @file_put_contents($arquivo,
            "usuario=admin\nsenha={$senha}\ngerada_em=" . Db::agora() . "\n" .
            "(válida até o primeiro login — a troca é obrigatória)\n");
        @chmod($arquivo, 0600);

        // Linha propositalmente greppável — ver instalar.sh e senha.sh.
        fwrite(STDOUT, "SENHA_INICIAL_ADMIN={$senha}\n");
    }
} else {
    fwrite(STDOUT, "Admin já existe — não mexi na senha.\n");
}
