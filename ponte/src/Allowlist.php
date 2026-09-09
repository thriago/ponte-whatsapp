<?php
// src/Allowlist.php — filtro OPCIONAL de números.
//
// ALLOWLIST_NUMEROS (.env) restringe com quem a ponte fala, nos DOIS sentidos:
// entrada (mensagem de outro número é descartada e registrada) e saída
// (mensagem pra outro número nunca chega ao wuzapi).
//
// ⚠️ VAZIA = ATENDE TODO MUNDO. É o padrão e é o uso normal: o número existe
// pra falar com os seus clientes. Preencher a lista liga o modo HOMOLOGAÇÃO —
// serve pra provar a instalação falando só com o seu próprio celular antes de
// abrir pro público.
//
// Isto não é fronteira de segurança, é conveniência de teste. Quem protege o
// acesso é a senha da interface e a ACL de IP.
final class Allowlist
{
    /** @return string[] cada entrada é um número em dígitos puros (uma ou duas
     *  variantes por número configurado, ver variantesNonoDigito). */
    public static function carregar(): array
    {
        $bruta = self::configuracaoBruta();
        if ($bruta === '') return [];

        // ⚠️ NUNCA usar o número como CHAVE de array aqui — PHP converte
        // chave que parece número inteiro pra int de verdade
        // (`$arr["5511..."] = 1` vira `$arr[5511...]`), e `permitido()`
        // compara com `in_array(..., true)` (estrito): a string "5511..."
        // nunca é === ao int 5511... O sintoma é o número liberado sendo
        // rejeitado. Por isso: lista simples (push) + array_unique, com todo
        // elemento forçado a string.
        $lista = [];
        foreach (explode(',', $bruta) as $n) {
            $digitos = preg_replace('/\D+/', '', trim($n));
            if ($digitos === '') continue;
            foreach (self::variantesNonoDigito($digitos) as $v) {
                $lista[] = (string) $v;
            }
        }
        return array_values(array_unique($lista));
    }

    public static function configuracaoBruta(): string
    {
        return trim((string) (getenv('ALLOWLIST_NUMEROS') ?: ''));
    }

    public static function vazia(): bool
    {
        return self::configuracaoBruta() === '';
    }

    /**
     * Gera as DUAS formas de um número BR quando o "nono dígito" é ambíguo
     * (`5511999998888` — com 9 — e `551199998888` — sem 9 — são a MESMA
     * pessoa pro WhatsApp em muitos casos). Só mexe quando o número tem a cara
     * de BR (começa com 55, DDD de 2 dígitos, assinante de 8 ou 9 dígitos —
     * total 12 ou 13 dígitos). Fora desse formato, devolve só o original,
     * sem tentar adivinhar a regra de outro país.
     *
     * @return string[] 1 ou 2 variantes
     */
    private static function variantesNonoDigito(string $digitos): array
    {
        $variantes = [$digitos];
        if (!str_starts_with($digitos, '55')) return $variantes;
        if (!in_array(strlen($digitos), [12, 13], true)) return $variantes;

        $ddd       = substr($digitos, 2, 2);
        $assinante = substr($digitos, 4);

        if (strlen($assinante) === 9 && $assinante[0] === '9') {
            $variantes[] = '55' . $ddd . substr($assinante, 1); // sem o 9
        } elseif (strlen($assinante) === 8) {
            $variantes[] = '55' . $ddd . '9' . $assinante; // com o 9
        }
        return $variantes;
    }

    /**
     * Aceita telefone cru, JID (`551192...@s.whatsapp.net`,
     * `551192...:76@s.whatsapp.net` — o `:76` é aparelho, nunca telefone,
     * mesmo corte de `Translator::digitosDoJid()`) ou já só dígitos.
     * Reforço defensivo: quem chama isto hoje já manda dígitos limpos (o
     * Translator já resolveu), mas se um dia um JID cru chegar direto aqui,
     * o número do aparelho grudado no telefone não pode voltar a quebrar a
     * comparação.
     */
    public static function permitido(string $numeroOuJid): bool
    {
        // Lista vazia = sem filtro. Atende todo mundo.
        if (self::vazia()) return true;

        $antesDoArroba = explode('@', $numeroOuJid)[0] ?? '';
        $semAparelho   = explode(':', $antesDoArroba, 2)[0];
        $digitos = preg_replace('/\D+/', '', $semAparelho);
        if ($digitos === '') return false;
        return in_array($digitos, self::carregar(), true);
    }

    /** Texto curto pra exibir na interface. */
    public static function resumoParaUi(): string
    {
        if (self::vazia()) {
            return 'Atendendo todos os números. Para restringir durante um teste, preencha ALLOWLIST_NUMEROS no .env.';
        }
        return 'MODO HOMOLOGAÇÃO — falando só com: ' . self::configuracaoBruta()
            . '. Esvazie ALLOWLIST_NUMEROS no .env para atender todos.';
    }

    /** Linha do log de boot. Diz em que modo subiu, pra não haver surpresa. */
    public static function linhaDeBoot(): string
    {
        if (self::vazia()) {
            return 'ALLOWLIST_NUMEROS vazia — ATENDENDO TODOS OS NÚMEROS (modo normal).';
        }
        return 'ALLOWLIST_NUMEROS preenchida — MODO HOMOLOGAÇÃO: só fala com '
            . self::configuracaoBruta()
            . ' (variantes de nono dígito aceitas automaticamente: ' . implode(',', self::carregar()) . ').'
            . ' Esvazie o .env para atender todos.';
    }
}
