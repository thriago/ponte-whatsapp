<?php
require_once __DIR__ . '/Db.php';

// src/NumeroWhatsapp.php — descobre como o WhatsApp conhece um número antes
// de enviar.
//
// O problema: no Brasil, quem tem WhatsApp desde antes do nono dígito
// continua registrado SEM ele (558198052094), mas o sistema de atendimento e o
// cadastro do cliente costumam ter o número COM o 9 (5581998052094). Mandar
// pro número com 9 não dá erro nenhum: o wuzapi responde "enviado", o
// WhatsApp aceita, e a mensagem simplesmente nunca chega. A API oficial da
// Meta resolve isso sozinha; aqui a ponte precisa perguntar.
//
// Como: POST /user/check no wuzapi, que consulta o próprio WhatsApp e devolve
// o número registrado ("5581998052094" -> "558198052094@s.whatsapp.net"), ou
// diz que o número não tem WhatsApp. A resposta fica guardada por um tempo
// pra não perguntar a cada mensagem.
//
// Se a consulta falhar (wuzapi fora, timeout), a ponte segue com o número
// original: melhor tentar como antes do que travar o envio.
final class NumeroWhatsapp
{
    /** Quanto tempo vale a resposta guardada. */
    private const VALIDADE_EXISTE_S     = 7 * 86400;
    private const VALIDADE_NAO_EXISTE_S = 6 * 3600;

    /**
     * @return array{existe:bool,numero:string,consultou:bool}
     *   numero = como enviar (só dígitos). existe=false só quando o WhatsApp
     *   respondeu que o número não tem conta.
     */
    public static function resolver(WuzapiClient $wz, string $sessaoToken, string $telefone): array
    {
        $telefone = preg_replace('/\D+/', '', $telefone) ?? '';
        if ($telefone === '') {
            return ['existe' => false, 'numero' => '', 'consultou' => false];
        }

        $guardado = self::guardado($telefone);
        if ($guardado !== null) return $guardado;

        $r = $wz->checarNumero($sessaoToken, $telefone);
        if ($r === null) {
            return ['existe' => true, 'numero' => $telefone, 'consultou' => false];
        }

        $numero = $r['existe'] && $r['jid'] !== null
            ? explode(':', explode('@', $r['jid'])[0], 2)[0]
            : null;
        Db::get()->prepare('
            INSERT INTO numero_whatsapp (telefone, numero, existe, verificado_em) VALUES (?, ?, ?, ?)
            ON CONFLICT(telefone) DO UPDATE SET numero = excluded.numero, existe = excluded.existe, verificado_em = excluded.verificado_em
        ')->execute([$telefone, $numero, $r['existe'] ? 1 : 0, Db::agora()]);

        return ['existe' => $r['existe'], 'numero' => $numero ?? $telefone, 'consultou' => true];
    }

    /** @return ?array{existe:bool,numero:string,consultou:bool} */
    private static function guardado(string $telefone): ?array
    {
        $stmt = Db::get()->prepare('SELECT numero, existe, verificado_em FROM numero_whatsapp WHERE telefone = ?');
        $stmt->execute([$telefone]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;

        $idade = time() - (int) strtotime((string) $r['verificado_em']);
        $validade = $r['existe'] ? self::VALIDADE_EXISTE_S : self::VALIDADE_NAO_EXISTE_S;
        if ($idade > $validade) return null;

        return [
            'existe'    => (bool) $r['existe'],
            'numero'    => (string) ($r['numero'] ?: $telefone),
            'consultou' => false,
        ];
    }
}
