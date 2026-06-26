<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Erro tipado lançado em qualquer resposta não-2xx da API do bZapper.
 *
 * A lógica do cliente deve usar SEMPRE o {@see BzapperException::getCode()}
 * (código neutro estável) — nunca o texto de {@see BzapperException::getMessage()}
 * (traduzido conforme o locale, só para humanos).
 */
final class BzapperException extends \RuntimeException
{
    /** Código neutro estável retornado pela API (ex.: "instance_not_connected"). */
    private string $errorCode;

    /** Status HTTP da resposta (ex.: 401, 404, 429). */
    private int $statusCode;

    /** Locale da mensagem traduzida, quando informado pela API (ex.: "pt-BR"). */
    private ?string $locale;

    /** Corpo cru da resposta de erro, quando disponível. */
    private ?string $rawBody;

    public function __construct(
        string $code,
        string $message,
        int $statusCode,
        ?string $locale = null,
        ?string $rawBody = null,
        ?\Throwable $previous = null
    ) {
        // \Exception::$code é um int final; guardamos o status HTTP nele e o
        // código neutro (string) à parte, exposto via getErrorCode().
        parent::__construct($message, $statusCode, $previous);
        $this->errorCode = $code;
        $this->statusCode = $statusCode;
        $this->locale = $locale;
        $this->rawBody = $rawBody;
    }

    /**
     * Código neutro estável da API (use SEMPRE este na sua lógica),
     * ex.: "instance_not_connected", "rate_limited", "unauthorized".
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Status HTTP da resposta (ex.: 401, 404, 429). */
    public function getStatusCode(): int
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
}
