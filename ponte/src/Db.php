<?php
// src/Db.php — SQLite próprio da ponte (não é o banco do wuzapi nem o do
// sistema de atendimento). Guarda: login do admin, sessões (token do wuzapi
// + URL e token do canal, colados por quem instalou) e o log de eventos.

final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $path = getenv('DB_PATH') ?: '/app/data/app.db';
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        // O puxar_loop.php (processo separado, escreve em eventos a cada
        // poll) e o servidor web (webhook de entrada, também escreve em
        // eventos) disputam o MESMO arquivo. WAL sozinho não evita
        // "database is locked" numa escrita concorrente: bastam dois POSTs
        // de webhook em sequência rápida. busy_timeout faz o SQLite ESPERAR
        // e tentar de novo, em vez de estourar erro na hora.
        $pdo->exec('PRAGMA busy_timeout=5000');
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::get();
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS admin (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                must_change_password INTEGER NOT NULL DEFAULT 1,
                criado_em TEXT NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sessoes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                wuzapi_user_id TEXT,
                wuzapi_token TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT \'desconectado\',
                numero TEXT,
                guiachat_url TEXT,
                guiachat_token TEXT,
                modo_entrega TEXT NOT NULL DEFAULT \'puxar\',
                conectar_solicitado INTEGER NOT NULL DEFAULT 0,
                criado_em TEXT NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS eventos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sessao_id INTEGER,
                direcao TEXT NOT NULL,
                tipo TEXT NOT NULL,
                http_code INTEGER,
                detalhe TEXT,
                criado_em TEXT NOT NULL
            )
        ');
        // Par LID <-> telefone — fica só aqui, o sistema de atendimento
        // nunca vê o LID. Uma linha por LID, telefone e quando foi visto
        // pela última vez. Ver src/LidMap.php.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS lid_telefone (
                lid TEXT PRIMARY KEY,
                telefone TEXT NOT NULL,
                visto_em TEXT NOT NULL
            )
        ');
        // O que já foi entregue ao wuzapi, por entrega. É o que impede a
        // mesma resposta de sair duas vezes quando a entrega volta da fila
        // (ver src/Envios.php). wa_id = id da mensagem no WhatsApp, usado
        // pra ligar os recibos de entregue/lido de volta à entrega.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS envios (
                sessao_id INTEGER NOT NULL,
                entrega_id INTEGER NOT NULL,
                estado TEXT NOT NULL,
                wa_id TEXT,
                erro TEXT,
                visto_em TEXT NOT NULL,
                atualizado_em TEXT NOT NULL,
                PRIMARY KEY (sessao_id, entrega_id)
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_envios_wa_id ON envios (wa_id)');
        // Telefone como veio do sistema de atendimento -> número como o
        // WhatsApp conhece (nono dígito). Ver src/NumeroWhatsapp.php.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS numero_whatsapp (
                telefone TEXT PRIMARY KEY,
                numero TEXT,
                existe INTEGER NOT NULL,
                verificado_em TEXT NOT NULL
            )
        ');
    }

    public static function agora(): string
    {
        return date('Y-m-d H:i:s');
    }
}
