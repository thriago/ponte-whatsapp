<?php
require_once __DIR__ . '/Db.php';

// src/LidMap.php — par LID <-> telefone, guardado LOCALMENTE na ponte.
// O LID (LinkedID, o endereçamento novo da Meta — ver Translator.php) é
// opaco; quando o evento chega com `SenderAlt` (o telefone de verdade), a
// ponte GRAVA o par. Se um evento seguinte chegar só com o LID (sem
// `SenderAlt`), resolve pelo mapa local — sem precisar recusar a mensagem
// de novo.
//
// ⚠️ O LID nunca sai daqui: o sistema de atendimento recebe sempre o
// telefone. O mapa existe só pra reduzir quantas vezes a ponte precisa
// recusar por falta de SenderAlt, não pra criar um caminho novo de dado —
// quem casa o contato com o cadastro do cliente é o telefone, sempre.
//
// Sem par guardado E sem SenderAlt no evento: continua recusando, com log
// explícito (Translator.php) — o mapa REDUZ o caso, não elimina a garantia
// de "nunca inventa contato".
final class LidMap
{
    /** Grava ou ATUALIZA o par — se a pessoa trocar de número mantendo o
     *  mesmo LID, a próxima vez que os dois vierem juntos o mapa acompanha,
     *  em vez de ficar preso no número velho. */
    public static function gravar(string $lid, string $telefone): void
    {
        $agora = Db::agora();
        Db::get()->prepare('
            INSERT INTO lid_telefone (lid, telefone, visto_em) VALUES (:lid, :tel, :agora)
            ON CONFLICT(lid) DO UPDATE SET telefone = excluded.telefone, visto_em = excluded.visto_em
        ')->execute([':lid' => $lid, ':tel' => $telefone, ':agora' => $agora]);
    }

    /** @return ?string o telefone guardado pra este LID, ou null se nunca visto. */
    public static function resolver(string $lid): ?string
    {
        $stmt = Db::get()->prepare('SELECT telefone FROM lid_telefone WHERE lid = ?');
        $stmt->execute([$lid]);
        $r = $stmt->fetchColumn();
        return $r !== false ? (string) $r : null;
    }
}
