<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Erro lançado em qualquer resposta não-2xx da API do bZapper (e em falha de rede).
 *
 * A lógica do cliente deve usar SEMPRE o {@see BzapperException::getErrorCode()}
 * (código neutro estável, ex.: "instance_not_connected") — nunca o texto de
 * {@see BzapperException::getMessage()} (traduzido conforme o locale, só para humanos).
 *
 * Subclasses por status (todas herdam desta — um `catch (BzapperException $e)` pega tudo):
 * {@see AuthenticationException} (401), {@see PermissionDeniedException} (403),
 * {@see NotFoundException} (404), {@see ConflictException} (409),
 * {@see ValidationException} (400/422), {@see RateLimitException} (429),
 * {@see ServerException} (5xx) e {@see NetworkException} (conexão/timeout, status 0).
 * Qualquer outro status lança esta classe base.
 *
 * `getCode()` (o inteiro nativo do PHP) é o status HTTP, igual a {@see getStatusCode()}.
 */
class BzapperException extends \RuntimeException
{
    /** Código neutro estável retornado pela API (ex.: "instance_not_connected"). */
    private string $errorCode;

    /** Status HTTP da resposta (ex.: 401, 404, 429); 0 em erro de rede. */
    private int $statusCode;

    /** Locale da mensagem traduzida, quando informado pela API (ex.: "pt-BR"). */
    private ?string $locale;

    /** Corpo cru da resposta de erro, quando disponível. */
    private ?string $rawBody;

    /** X-Request-Id da resposta (ou o que a SDK enviou) — informe ao suporte. */
    private ?string $requestId;

    /** Segundos do header Retry-After (só em 429). */
    private ?int $retryAfter;

    /** Escopo exigido (header X-Required-Scope, só em 403 de escopo). */
    private ?string $requiredScope;

    /** Corpo do erro decodificado (detalhe estruturado de alguns erros). */
    private mixed $body;

    public function __construct(
        string $code,
        string $message,
        int $statusCode,
        ?string $locale = null,
        ?string $rawBody = null,
        ?\Throwable $previous = null,
        ?string $requestId = null,
        ?int $retryAfter = null,
        ?string $requiredScope = null,
        mixed $body = null
    ) {
        // \Exception::$code é um int final; guardamos o status HTTP nele e o
        // código neutro (string) à parte, exposto via getErrorCode().
        parent::__construct($message, $statusCode, $previous);
        $this->errorCode = $code;
        $this->statusCode = $statusCode;
        $this->locale = $locale;
        $this->rawBody = $rawBody;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
        $this->requiredScope = $requiredScope;
        $this->body = $body;
    }

    /**
     * Código neutro estável da API (use SEMPRE este na sua lógica),
     * ex.: "instance_not_connected", "rate_limited", "unauthorized".
     * Sem corpo JSON: "HTTP_<status>"; falha de rede: "NETWORK_ERROR";
     * sucesso com corpo não-JSON: "INVALID_RESPONSE".
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Status HTTP da resposta (ex.: 401, 404, 429); 0 em erro de rede. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Alias de {@see getStatusCode()} (mesmo nome das outras SDKs Berni). */
    public function getStatus(): int
    {
        return $this->statusCode;
    }

    /** Locale da mensagem traduzida, quando informado. */
    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /** Corpo cru da resposta de erro, quando disponível. */
    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    /**
     * Id da requisição: header `X-Request-Id` da resposta, senão o `X-Request-Id`
     * que a SDK enviou (a API o registra) — informe ao suporte.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** Segundos para tentar de novo (header `Retry-After`, só em 429). */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** Escopo que a chave precisaria ter (header `X-Required-Scope`, só em 403 de escopo). */
    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }

    /**
     * Corpo do erro decodificado (`{code, message, locale, ...}`), com o detalhe
     * estruturado que alguns erros trazem; `null` se o corpo não era JSON.
     */
    public function getBody(): mixed
    {
        return $this->body;
    }

    /**
     * @internal Constrói a exceção certa a partir de uma resposta de erro.
     *
     * @param array<string,string> $headers nomes em minúsculas
     */
    public static function fromResponse(int $status, array $headers, string $raw, ?string $sentRequestId = null): self
    {
        $decoded = null;
        if (trim($raw) !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null; // corpo não-JSON (ex.: HTML de proxy) → HTTP_<status>
            }
        }
        $body = is_array($decoded) ? $decoded : [];

        $code = self::nonEmpty($body['code'] ?? null)
            ?? self::nonEmpty($body['error'] ?? null)
            ?? 'HTTP_' . $status;
        $message = self::nonEmpty($body['message'] ?? null) ?? $code;
        $locale = self::nonEmpty($body['locale'] ?? null);
        $requestId = self::nonEmpty($headers['x-request-id'] ?? null) ?? self::nonEmpty($sentRequestId);

        $retryAfter = null;
        if ($status === 429) {
            $seconds = self::parseRetryAfter($headers['retry-after'] ?? null);
            $retryAfter = $seconds === null ? null : (int) ceil($seconds);
        }
        $requiredScope = $status === 403 ? self::nonEmpty($headers['x-required-scope'] ?? null) : null;

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 400, $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 && $status <= 599 => ServerException::class,
            default => self::class,
        };

        return new $class(
            $code,
            $message,
            $status,
            $locale,
            $raw,
            null,
            $requestId,
            $retryAfter,
            $requiredScope,
            $decoded,
        );
    }

    /** @internal Segundos do header `Retry-After` (número ou data HTTP); `null` se ausente/ilegível. */
    public static function parseRetryAfter(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : (float) max(0, $timestamp - time());
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
