<?php
// src/EventLog.php — grava e lê a Tela 4 (Últimos eventos): o que entrou, o
// que saiu, o que falhou, com o código HTTP. É o que responde "mandei e não
// chegou" sem precisar de terminal.

final class EventLog
{
    /** @param 'entrada'|'saida'|'sistema' $direcao */
    public static function registrar(?int $sessaoId, string $direcao, string $tipo, ?int $httpCode, string $detalhe): void
    {
        $stmt = Db::get()->prepare('
            INSERT INTO eventos (sessao_id, direcao, tipo, http_code, detalhe, criado_em)
            VALUES (:sid, :dir, :tipo, :code, :det, :agora)
        ');
        $stmt->execute([
            ':sid'   => $sessaoId,
            ':dir'   => $direcao,
            ':tipo'  => $tipo,
            ':code'  => $httpCode,
            // 8000 e não 4000: o payload bruto de uma mensagem de mídia
            // (imageMessage/videoMessage carrega bastante metadado) era
            // cortado bem no meio do bloco "Info", e quem fosse investigar
            // um campo novo por aqui não tinha nem o "Info" inteiro.
            ':det'   => mb_substr($detalhe, 0, 8000),
            ':agora' => Db::agora(),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function ultimos(int $limite = 100): array
    {
        $stmt = Db::get()->prepare('
            SELECT e.*, s.nome AS sessao_nome
            FROM eventos e
            LEFT JOIN sessoes s ON s.id = e.sessao_id
            ORDER BY e.id DESC
            LIMIT :lim
        ');
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
