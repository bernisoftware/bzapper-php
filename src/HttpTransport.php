<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Encanamento HTTP compartilhado por {@see Client} e {@see PartnerClient}
 * (padrão Berni Software r2): cabeçalhos, cURL, X-Request-Id e Idempotency-Key por
 * chamada lógica, novas tentativas com Retry-After, decodificação do JSON e conversão
 * de erros em {@see BzapperException} (e subclasses).
 *
 * @internal Não faz parte da superfície pública do SDK — use as classes de cliente.
 */
trait HttpTransport
{
    private string $baseUrl;
    private string $apiKey;
    private ?string $locale;
    /** Segundos por tentativa. */
    private float $timeout;
    private int $maxRetries = 2;
    private ?string $projectId = null;
    /** @var \Closure(float): void */
    private \Closure $sleep;
    private ?\CurlHandle $curl = null;

    /**
     * @param array{locale?: string, timeout?: int|float, max_retries?: int, project_id?: string, sleep?: callable(float): void} $opts
     */
    private function initTransport(string $token, ?string $baseUrl, array $opts): void
    {
        $this->baseUrl = rtrim($baseUrl ?? Client::DEFAULT_BASE_URL, '/');
        $this->apiKey = $token;
        $this->locale = isset($opts['locale']) && $opts['locale'] !== '' ? (string) $opts['locale'] : null;
        $this->timeout = isset($opts['timeout']) ? (float) $opts['timeout'] : 30.0;
        if ($this->timeout <= 0) {
            throw new \InvalidArgumentException('timeout precisa ser maior que zero.');
        }
        if (isset($opts['max_retries'])) {
            if (!is_int($opts['max_retries']) || $opts['max_retries'] < 0) {
                throw new \InvalidArgumentException('max_retries precisa ser um inteiro >= 0.');
            }
            $this->maxRetries = $opts['max_retries'];
        }
        $this->projectId = isset($opts['project_id']) && $opts['project_id'] !== '' ? (string) $opts['project_id'] : null;
        if (isset($opts['sleep'])) {
            if (!is_callable($opts['sleep'])) {
                throw new \InvalidArgumentException('sleep precisa ser callable(float $segundos).');
            }
            $this->sleep = \Closure::fromCallable($opts['sleep']);
        } else {
            $this->sleep = static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int) round($seconds * 1_000_000));
                }
            };
        }
    }

    // ------------------------------------------------------------------
    // Atalhos usados pelos métodos legados (retornam array; corpo vazio → []).
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $query
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = [], array $options = []): array
    {
        return $this->call('GET', $path, $query, null, $options) ?? [];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed> $query
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string,mixed>
     */
    private function post(string $path, ?array $body = null, array $query = [], array $options = []): array
    {
        return $this->call('POST', $path, $query, $body, $options) ?? [];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed> $query
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string,mixed>
     */
    private function put(string $path, ?array $body = null, array $query = [], array $options = []): array
    {
        return $this->call('PUT', $path, $query, $body, $options) ?? [];
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,mixed> $query
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string,mixed>
     */
    private function patch(string $path, ?array $body = null, array $query = [], array $options = []): array
    {
        return $this->call('PATCH', $path, $query, $body, $options) ?? [];
    }

    /**
     * @param array<string,mixed> $query
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string,mixed>
     */
    private function delete(string $path, array $query = [], array $options = []): array
    {
        return $this->call('DELETE', $path, $query, null, $options) ?? [];
    }

    /**
     * GET que devolve o corpo CRU, sem passar pelo JSON (rotas `text/csv` como
     * `GET /contacts/export`). Mesmos cabeçalhos, mesmas novas tentativas e mesmos
     * erros; só a decodificação muda — a regra "2xx não-JSON = INVALID_RESPONSE"
     * não vale aqui. Corpo vazio → string vazia.
     *
     * @param array<string,mixed> $query
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     */
    private function getText(string $path, array $query = [], array $options = []): string
    {
        $raw = $this->call('GET', $path, $query, null, $options, null, true);

        return is_string($raw) ? $raw : '';
    }

    /**
     * Codifica UM parâmetro de caminho (percent-encoding por segmento). Vazio, "." ou
     * ".." → InvalidArgumentException ANTES de qualquer requisição.
     */
    private static function seg(string $value, string $name = 'id'): string
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new \InvalidArgumentException(sprintf(
                'Parâmetro de caminho "%s" inválido: %s.',
                $name,
                $value === '' ? 'vazio' : '"' . $value . '"'
            ));
        }
        return rawurlencode($value);
    }

    /**
     * Executa UMA chamada lógica (com as novas tentativas) e devolve o corpo JSON
     * decodificado (`null` para corpo vazio/204).
     *
     * @param string $path Caminho já codificado.
     * @param array<string,mixed> $query Valores `null` são omitidos.
     * @param array<string,mixed>|null $body `null` = sem corpo.
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @param array{fields: array<string,scalar>, file: array{name: string, filename: string, content: string, content_type: string}}|null $multipart
     * @param bool $rawText `true` (só em rotas de texto, ex.: CSV): devolve o corpo cru
     *                      em string, sem tentar JSON. Use pelo atalho {@see self::getText()}.
     * @return array<mixed>|string|null
     *
     * @throws BzapperException Em qualquer resposta não-2xx ou falha de transporte.
     * @throws \InvalidArgumentException Opção/argumento inválido (antes de qualquer requisição).
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null, array $options = [], ?array $multipart = null, bool $rawText = false): array|string|null
    {
        self::checkOptions($options);

        $url = $this->baseUrl . $path;
        $qs = self::buildQuery($query);
        if ($qs !== '') {
            $url .= '?' . $qs;
        }

        $payload = null;
        $contentType = null;
        if ($multipart !== null) {
            $boundary = '----bzapper' . bin2hex(random_bytes(12));
            $payload = self::encodeMultipart($boundary, $multipart);
            $contentType = 'multipart/form-data; boundary=' . $boundary;
        } elseif ($body !== null) {
            $payload = self::encodeJson($body);
            $contentType = 'application/json';
        }
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : $this->timeout;

        // Gerados UMA vez por chamada lógica e repetidos em toda nova tentativa: é isso
        // que deixa a API devolver a resposta original (Idempotent-Replayed) em vez de
        // repetir a escrita.
        $requestId = bin2hex(random_bytes(16));
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            // Rota de texto (CSV): pedimos CSV e mantemos JSON como alternativa — o
            // corpo de ERRO continua sendo JSON.
            'Accept: ' . ($rawText ? 'text/csv, application/json;q=0.1' : 'application/json'),
            // Identifica SDK e versão para a API — é por ele que avisamos você
            // quando a versão que roda tem correção que exige atualizar o código.
            'X-Bzapper-Client: ' . Client::CLIENT_ID,
            'User-Agent: ' . Client::CLIENT_ID,
            'X-Request-Id: ' . $requestId,
            'Expect:', // sem "100-continue" em corpos grandes
        ];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers[] = 'Idempotency-Key: ' . ($options['idempotency_key'] ?? self::uuid4());
        }
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        } elseif ($method !== 'GET' && $method !== 'DELETE') {
            $headers[] = 'Content-Length: 0';
        }
        if ($this->locale !== null) {
            $headers[] = 'Accept-Language: ' . $this->locale;
        }
        if ($this->projectId !== null) {
            $headers[] = 'X-Project-Id: ' . $this->projectId;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $responseHeaders, $raw] = $this->sendOnce($method, $url, $headers, $payload, $timeout, $requestId);
            } catch (NetworkException $e) {
                if ($attempt < $this->maxRetries) {
                    ($this->sleep)(self::backoff($attempt));
                    continue;
                }
                throw $e;
            }

            if ($status >= 200 && $status < 300) {
                return $rawText ? $raw : self::decodeSuccess($status, $responseHeaders, $raw, $requestId);
            }
            if (in_array($status, [429, 502, 503, 504], true) && $attempt < $this->maxRetries) {
                $retryAfter = BzapperException::parseRetryAfter($responseHeaders['retry-after'] ?? null);
                ($this->sleep)($retryAfter !== null ? min(60.0, $retryAfter) : self::backoff($attempt));
                continue;
            }

            throw BzapperException::fromResponse($status, $responseHeaders, $raw, $requestId);
        }
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: array<string,string>, 2: string}
     */
    private function sendOnce(string $method, string $url, array $headers, ?string $payload, float $timeout, string $requestId): array
    {
        if ($this->curl === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new NetworkException('NETWORK_ERROR: não foi possível iniciar o cURL.', $requestId);
            }
            $this->curl = $handle;
        } else {
            curl_reset($this->curl); // reaproveita a conexão (keep-alive) sem herdar opções
        }

        $responseHeaders = [];
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = []; // nova resposta (ex.: depois de um 100 Continue)
                } elseif (($pos = strpos($trimmed, ':')) !== false) {
                    $responseHeaders[strtolower(trim(substr($trimmed, 0, $pos)))] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PATH_AS_IS')) {
            $opts[CURLOPT_PATH_AS_IS] = true; // o caminho vai exatamente como codificado
        }
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($this->curl, $opts);

        $raw = curl_exec($this->curl);
        if (!is_string($raw)) {
            $errno = curl_errno($this->curl);
            $error = curl_error($this->curl);
            throw new NetworkException(sprintf(
                'NETWORK_ERROR: %s %s falhou (cURL %d: %s) [request_id=%s]',
                $method,
                strtok($url, '?'),
                $errno,
                $error !== '' ? $error : 'erro desconhecido',
                $requestId
            ), $requestId);
        }

        return [(int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), $responseHeaders, $raw];
    }

    /**
     * 2xx: corpo vazio → null; JSON → decodificado; qualquer outra coisa (HTML de proxy,
     * texto) → INVALID_RESPONSE — nunca um retorno vazio calado.
     *
     * @param array<string,string> $headers
     * @return array<mixed>|null
     */
    private static function decodeSuccess(int $status, array $headers, string $raw, string $sentRequestId): ?array
    {
        if (trim($raw) === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $decoded = $e;
        }
        if ($decoded === null || is_array($decoded)) {
            return $decoded;
        }
        $requestId = ($headers['x-request-id'] ?? '') !== '' ? $headers['x-request-id'] : $sentRequestId;
        throw new BzapperException(
            'INVALID_RESPONSE',
            sprintf('INVALID_RESPONSE (HTTP %d): resposta de sucesso não é JSON [request_id=%s]', $status, $requestId),
            $status,
            null,
            $raw,
            $decoded instanceof \Throwable ? $decoded : null,
            $requestId
        );
    }

    /**
     * Query string: `null` omitido; booleanos `true`/`false`; datas em ISO 8601 UTC com
     * `Z`; listas como CSV (`style: form, explode: false`).
     *
     * @param array<string,mixed> $query
     */
    private static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_map(static fn ($v): string => self::queryValue((string) $name, $v), $value));
            } else {
                $value = self::queryValue((string) $name, $value);
            }
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
        }
        return implode('&', $pairs);
    }

    private static function queryValue(string $name, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
            return $utc->format($utc->format('u') === '000000' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.u\Z');
        }
        if (is_string($value) || is_int($value) || is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        throw new \InvalidArgumentException(sprintf(
            'Parâmetro "%s": tipo %s não suportado na query.',
            $name,
            get_debug_type($value)
        ));
    }

    /** @param array<mixed> $body */
    private static function encodeJson(array $body): string
    {
        try {
            // Corpo vazio vira `{}`, nunca `[]`.
            return json_encode(
                $body === [] ? new \stdClass() : $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Corpo não serializável em JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{fields: array<string,scalar>, file: array{name: string, filename: string, content: string, content_type: string}} $mp
     */
    private static function encodeMultipart(string $boundary, array $mp): string
    {
        $out = '';
        foreach ($mp['fields'] as $name => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $out .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . self::quoteMime((string) $name) . "\"\r\n\r\n"
                . (string) $value . "\r\n";
        }
        $f = $mp['file'];
        $out .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quoteMime($f['name']) . '"; filename="' . self::quoteMime($f['filename']) . "\"\r\n"
            . 'Content-Type: ' . $f['content_type'] . "\r\n\r\n"
            . $f['content'] . "\r\n";
        return $out . '--' . $boundary . "--\r\n";
    }

    private static function quoteMime(string $s): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $s);
    }

    /** @param array<array-key,mixed> $options */
    private static function checkOptions(array $options): void
    {
        $unknown = array_diff(array_keys($options), ['idempotency_key', 'timeout']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Opção de requisição desconhecida: %s. Aceitas: idempotency_key, timeout.',
                implode(', ', $unknown)
            ));
        }
        if (isset($options['idempotency_key']) && (!is_string($options['idempotency_key']) || $options['idempotency_key'] === '')) {
            throw new \InvalidArgumentException('idempotency_key precisa ser uma string não vazia.');
        }
        if (isset($options['timeout']) && ((!is_int($options['timeout']) && !is_float($options['timeout'])) || $options['timeout'] <= 0)) {
            throw new \InvalidArgumentException('timeout precisa ser um número de segundos maior que zero.');
        }
    }

    /** Espera sem Retry-After: `min(8, 0.5 × 2^tentativa)` s + jitter de até 25%. */
    private static function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * (2 ** $attempt));
        return $base * (1 + (random_int(0, 1_000_000) / 1_000_000) * 0.25);
    }

    /** UUID v4 aleatório (com hífens). */
    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
