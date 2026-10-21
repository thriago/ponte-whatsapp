<?php
require_once __DIR__ . '/Db.php';

// src/Envios.php — registro local de cada entrega do modo `puxar`.
//
// Por que existe: o wuzapi pode demorar mais que o tempo de espera da ponte
// pra responder um envio (já vimos 75 a 130 segundos com a conexão do
// WhatsApp instável). A ponte desistia, não confirmava, a entrega voltava da
// fila 60s depois e era enviada DE NOVO, só que a primeira também acabava
// saindo. Resultado: o cliente recebia a mesma mensagem dezenas de vezes.
//
// Regra: uma entrega só é entregue ao wuzapi UMA vez. Se voltar da fila, a
// ponte olha aqui e só reconfirma o que já aconteceu.
//
// Estados:
//   aguardando  vista, ainda não enviada (sessão fora, erro do wuzapi)
//   enviando    chamada ao wuzapi em andamento
//   enviado     wuzapi respondeu que mandou (wa_id preenchido)
//   incerto     a chamada estourou o tempo; pode ter saído. Não reenvia.
//   falhou      decisão definitiva de não enviar (erro preenchido)
final class Envios
{
    /** @return ?array<string,mixed> */
    public static function buscar(int $sessaoId, int $entregaId): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM envios WHERE sessao_id = ? AND entrega_id = ?');
        $stmt->execute([$sessaoId, $entregaId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Primeira vez que a entrega aparece: guarda quando foi vista. */
    public static function registrarVista(int $sessaoId, int $entregaId): array
    {
        $agora = Db::agora();
        Db::get()->prepare("
            INSERT OR IGNORE INTO envios (sessao_id, entrega_id, estado, visto_em, atualizado_em)
            VALUES (?, ?, 'aguardando', ?, ?)
        ")->execute([$sessaoId, $entregaId, $agora, $agora]);
        return self::buscar($sessaoId, $entregaId) ?? [];
    }

    public static function marcar(int $sessaoId, int $entregaId, string $estado, ?string $waId = null, ?string $erro = null): void
    {
        Db::get()->prepare('
            UPDATE envios SET estado = ?, wa_id = COALESCE(?, wa_id), erro = ?, atualizado_em = ?
             WHERE sessao_id = ? AND entrega_id = ?
        ')->execute([$estado, $waId, $erro, Db::agora(), $sessaoId, $entregaId]);
    }

    /** Entrega desta sessão que gerou a mensagem `$waId` no WhatsApp. */
    public static function porWaId(int $sessaoId, string $waId): ?array
    {
        $stmt = Db::get()->prepare('SELECT * FROM envios WHERE sessao_id = ? AND wa_id = ?');
        $stmt->execute([$sessaoId, $waId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Limpeza: o registro só precisa viver enquanto a entrega pode voltar da fila. */
    public static function limparAntigos(int $dias = 7): void
    {
        // Corte calculado no PHP, no mesmo relógio do Db::agora() que gravou a linha.
        Db::get()->prepare('DELETE FROM envios WHERE atualizado_em < ?')
            ->execute([date('Y-m-d H:i:s', time() - $dias * 86400)]);
    }
}
