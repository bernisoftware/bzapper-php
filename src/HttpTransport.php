<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Encanamento HTTP compartilhado por {@see Client} e {@see PartnerClient}:
 * cabeçalhos, cURL, decodificação do JSON e conversão de erros em
 * {@see BzapperException}.
 *
 * @internal Não faz parte da superfície pública do SDK — use as classes de cliente.
 */
trait HttpTransport
{
    private string $baseUrl;
    private string $apiKey;
    private ?string $locale;
    private int $timeout;

    /**
     * @param array{locale?: string, timeout?: int} $opts
     */
    private function initTransport(string $token, ?string $baseUrl, array $opts): void
    {
        $this->baseUrl = rtrim($baseUrl ?? Client::DEFAULT_BASE_URL, '/');
        $this->apiKey = $token;
        $this->locale = isset($opts['locale']) ? (string) $opts['locale'] : null;
        $this->timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 30;
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function post(string $path, ?array $body = null, array $query = []): array
    {
        return $this->request('POST', $path, $body, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function put(string $path, ?array $body = null, array $query = []): array
    {
        return $this->request('PUT', $path, $body, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function patch(string $path, ?array $body = null, array $query = []): array
    {
        return $this->request('PATCH', $path, $body, $query);
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, null, $query);
    }

    /**
     * Executa uma requisição HTTP e devolve o corpo JSON decodificado.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @param list<string> $extraHeaders Cabeçalhos extras ("Nome: valor"), ex.: Idempotency-Key.
     * @return array<string,mixed>
     *
     * @throws BzapperException Em qualquer resposta não-2xx ou falha de transporte.
     */
    private function request(string $method, string $path, ?array $body, array $query, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: bzapper-php/' . Client::VERSION,
            // Identifica SDK e versão para a API — é por ele que avisamos você
            // quando a versão que roda tem correção que exige atualizar o código.
            'X-Bzapper-Client: bzapper-php/' . Client::VERSION,
        ];
        if ($this->locale !== null && $this->locale !== '') {
            $headers[] = 'Accept-Language: ' . $this->locale;
        }
        foreach ($extraHeaders as $h) {
            $headers[] = $h;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new BzapperException('network_error', 'Falha ao inicializar cURL.', 0);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new BzapperException(
                    'invalid_request',
                    'Falha ao serializar o corpo da requisição: ' . json_last_error_msg(),
                    0
                );
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new BzapperException(
                'network_error',
                'Falha de transporte: ' . ($err !== '' ? $err : 'erro cURL ' . $errno),
                0
            );
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        /** @var string $raw */
        $rawBody = $raw;

        // Corpos vazios (ex.: 204) → array vazio.
        $decoded = [];
        if ($rawBody !== '') {
            $parsed = json_decode($rawBody, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            } elseif (json_last_error() !== JSON_ERROR_NONE && $status >= 200 && $status < 300) {
                throw new BzapperException(
                    'invalid_response',
                    'Resposta não-JSON da API: ' . json_last_error_msg(),
                    $status,
                    null,
                    $rawBody
                );
            }
        }

        if ($status < 200 || $status >= 300) {
            $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'http_error';
            $message = is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : ('Requisição falhou com status HTTP ' . $status . '.');
            $locale = is_string($decoded['locale'] ?? null) ? $decoded['locale'] : null;
            throw new BzapperException($code, $message, $status, $locale, $rawBody);
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}
