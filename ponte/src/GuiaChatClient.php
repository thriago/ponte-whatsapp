<?php
// src/GuiaChatClient.php — fala com a API do canal Integrador do GuiaChat.
// A referência da API fica publicada na própria instalação, em /doc/api.
//
// Regra deste arquivo: seguir a referência AO PÉ DA LETRA. Campo que este
// código mande ou espere e que não esteja documentado lá é bug, nunca
// decisão silenciosa — é o que mantém a ponte trocável sem surpresa.

final class GuiaChatClient
{
    public function __construct(
        private string $baseUrl, // ex: https://tenant.exemplo.com.br (SEM /api/v1/...)
        private string $token    // gc_int_...
    ) {
    }

    private function url(string $caminho): string
    {
        return rtrim($this->baseUrl, '/') . $caminho;
    }

    /** @return array{0:int,1:?array} [http_code, corpo decodificado] */
    public function enviarMensagemTexto(string $idExterno, string $de, string $texto, ?string $nome = null, ?string $documento = null): array
    {
        $corpo = ['id_externo' => $idExterno, 'de' => $de, 'tipo' => 'texto', 'texto' => $texto];
        $contato = array_filter(['nome' => $nome, 'documento' => $documento], fn($v) => $v !== null && $v !== '');
        if ($contato) $corpo['contato'] = $contato;

        [$code, $body] = Http::json('POST', $this->url('/api/v1/canal/mensagem'), [
            'Authorization: Bearer ' . $this->token,
        ], $corpo);
        return [$code, $body];
    }

    /**
     * @param 'imagem'|'audio'|'video'|'documento' $tipo
     * @return array{0:int,1:?array}
     */
    public function enviarMensagemComArquivo(string $idExterno, string $de, string $tipo, string $arquivoPath, string $mime, string $nomeArquivo, ?string $legenda = null, ?string $nome = null, ?string $documento = null): array
    {
        $dados = ['id_externo' => $idExterno, 'de' => $de, 'tipo' => $tipo];
        if ($legenda !== null && $legenda !== '') $dados['texto'] = $legenda;
        $contato = array_filter(['nome' => $nome, 'documento' => $documento], fn($v) => $v !== null && $v !== '');
        if ($contato) $dados['contato'] = $contato;

        [$code, $body] = Http::multipartUpload($this->url('/api/v1/canal/mensagem'), $this->token, $dados, $arquivoPath, $mime, $nomeArquivo);
        return [$code, $body];
    }

    /** @return array{0:int,1:?array} */
    public function pendentes(int $limite = 50): array
    {
        [$code, $body] = Http::json('GET', $this->url('/api/v1/canal/pendentes?limite=' . $limite), [
            'Authorization: Bearer ' . $this->token,
        ]);
        return [$code, $body];
    }

    /** @param int[] $ids @return array{0:int,1:?array} */
    public function confirmar(array $ids): array
    {
        [$code, $body] = Http::json('POST', $this->url('/api/v1/canal/confirmar'), [
            'Authorization: Bearer ' . $this->token,
        ], ['ids' => array_values(array_map('intval', $ids))]);
        return [$code, $body];
    }

    /**
     * Confirma dizendo o que aconteceu com cada entrega (`itens` do POST
     * /confirmar): `enviado` com o id da mensagem no WhatsApp, ou `falhou`
     * com o motivo, que aparece pro agente.
     *
     * Manda `ids` junto, com TODOS os ids. Uma instalação mais antiga, que
     * ainda não conhece `itens`, usa só o `ids` e tira tudo da fila do mesmo
     * jeito; só não fica sabendo do motivo da falha. O que não pode é uma
     * entrega que falhou de vez ficar voltando da fila.
     *
     * @param array<int,array{id:int,status:string,id_externo?:?string,erro?:?string}> $itens
     * @return array{0:int,1:?array}
     */
    public function confirmarResultados(array $itens): array
    {
        $limpos = [];
        foreach ($itens as $it) {
            $item = ['id' => (int) $it['id'], 'status' => $it['status']];
            if (!empty($it['id_externo'])) $item['id_externo'] = (string) $it['id_externo'];
            if (!empty($it['erro']))       $item['erro'] = mb_substr((string) $it['erro'], 0, 300);
            $limpos[] = $item;
        }
        [$code, $body] = Http::json('POST', $this->url('/api/v1/canal/confirmar'), [
            'Authorization: Bearer ' . $this->token,
        ], [
            'ids'   => array_map(fn($i) => $i['id'], $limpos),
            'itens' => $limpos,
        ]);
        return [$code, $body];
    }

    /**
     * Repassa entregue/lido de uma resposta que a ponte enviou. `$idExterno`
     * é o id da mensagem no WhatsApp, o mesmo mandado em `itens[].id_externo`.
     *
     * @param 'entregue'|'lido' $status
     * @return array{0:int,1:?array}
     */
    public function status(string $idExterno, string $status): array
    {
        [$code, $body] = Http::json('POST', $this->url('/api/v1/canal/status'), [
            'Authorization: Bearer ' . $this->token,
        ], ['id_externo' => $idExterno, 'status' => $status], 15);
        return [$code, $body];
    }

    /** Baixa uma mídia de SAÍDA (payload.midia.url) com o Bearer do canal. */
    public function baixarMidia(string $url): array
    {
        [$code, $bytes, $tipo] = Http::getBinary($url, ['Authorization: Bearer ' . $this->token]);
        return [$code, $bytes, $tipo];
    }

    /**
     * "Testar conexão" da tela Integrar — consulta de verdade, não só valida
     * formato. Reaproveita GET /pendentes com limite=1 (não tem efeito
     * colateral: leitura não confirma nada — regra 1 do contrato).
     *
     * @return array{ok:bool,mensagem:string}
     */
    public function testarConexao(): array
    {
        [$code, $body] = $this->pendentes(1);
        if ($code === 200 && is_array($body) && !empty($body['ok'])) {
            return ['ok' => true, 'mensagem' => 'Conexão OK — token válido e canal disponível.'];
        }
        $codigo = $body['erro']['codigo'] ?? null;
        $msg    = $body['erro']['mensagem'] ?? null;
        return match (true) {
            $code === 0        => ['ok' => false, 'mensagem' => 'Não consegui alcançar essa URL. Confira o endereço do GuiaChat.'],
            $code === 401      => ['ok' => false, 'mensagem' => 'Token inválido, expirado ou desativado (' . ($codigo ?: '401') . ').'],
            $code === 403      => ['ok' => false, 'mensagem' => 'Esse token não é de um canal Integrador (' . ($codigo ?: '403') . ').'],
            $code === 409      => ['ok' => false, 'mensagem' => 'O canal ligado a esse token não é mais do tipo Integrador.'],
            default            => ['ok' => false, 'mensagem' => "HTTP {$code}" . ($msg ? " — {$msg}" : ' — resposta inesperada.')],
        };
    }
}
