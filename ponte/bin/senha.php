<?php
// bin/senha.php — define uma senha nova para o administrador da interface.
//
// Uso (dentro do container):  php /app/bin/senha.php "<senha nova>"
// Quem chama isto normalmente é o senha.sh, de fora.
//
// Existe porque "esqueci a senha" não pode virar reinstalar tudo: a senha é
// guardada como hash, então não há como mostrá-la depois da troca — só
// substituir. Não mexe em mais nada da instalação (sessões pareadas,
// integração e histórico continuam iguais).
declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';

$nova = (string) ($argv[1] ?? '');
if (mb_strlen($nova) < 8) {
    fwrite(STDERR, "Senha muito curta (mínimo 8 caracteres).\n");
    exit(1);
}

$pdo  = Db::get();
$hash = password_hash($nova, PASSWORD_BCRYPT);

// Quem define a senha aqui já é dono da máquina — não faz sentido exigir que
// ele troque de novo no primeiro login, então must_change_password sai zerado.
$stmt = $pdo->prepare('UPDATE admin SET password_hash = ?, must_change_password = 0 WHERE username = ?');
$stmt->execute([$hash, 'admin']);

if ($stmt->rowCount() === 0) {
    // Não deveria acontecer: o migrar.php cria o admin no primeiro boot.
    $pdo->prepare('INSERT INTO admin (username, password_hash, must_change_password, criado_em) VALUES (?, ?, 0, ?)')
        ->execute(['admin', $hash, Db::agora()]);
    fwrite(STDOUT, "Admin não existia — criado com a senha informada.\n");
    exit(0);
}

fwrite(STDOUT, "Senha do usuário admin alterada.\n");
