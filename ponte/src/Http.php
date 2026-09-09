<?php
// src/Http.php — helpers cURL genéricos, usados pelo WuzapiClient e pelo
// GuiaChatClient. Sem dependência externa (sem composer) de propósito: é um
// projeto pequeno, e cada dependência a menos é uma licença a menos pra
// conferir antes de distribuir.

final class Http
{
    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:array<mixed>|null,2:string} [http_code, corpo decodificado (ou null se não for JSON), corpo bruto]
     */
    public static function json(string $method, string $url, array $headers, ?array $body = null, int $timeout = 30): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            return [0, null, $err];
        }
        $decoded = json_decode((string) $resp, true);
        return [$code, is_array($decoded) ? $decoded : null, (string) $resp];
    }

    /**
     * Multipart com um campo `dados` (JSON) + um arquivo. Usado pra mandar
     * mídia de entrada pro GuiaChat (upload direto, sem hospedar URL).
     *
     * @return array{0:int,1:array<mixed>|null,2:string}
     */
    public static function multipartUpload(string $url, string $bearer, array $dadosArr, string $arquivoPath, string $mime, string $nomeArquivo): array
    {
        $ch = curl_init($url);
        $post = [
            'dados'   => json_encode($dadosArr, JSON_UNESCAPED_UNICODE),
            'arquivo' => new CURLFile($arquivoPath, $mime ?: 'application/octet-stream', $nomeArquivo),
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            return [0, null, $err];
        }
        $decoded = json_decode((string) $resp, true);
        return [$code, is_array($decoded) ? $decoded : null, (string) $resp];
    }

    /**
     * GET binário com Bearer — baixa mídia (do GuiaChat, ou de uma midia.url
     * externa). Devolve os bytes + content-type reportado.
     *
     * @return array{0:int,1:?string,2:string} [http_code, bytes|null, content_type]
     */
    public static function getBinary(string $url, array $headers, int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tipo = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($data === false) {
            return [0, null, ''];
        }
        return [$code, $data, $tipo];
    }
}
