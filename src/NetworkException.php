<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Falha de conexão ou timeout (já esgotadas as novas tentativas): status 0, código
 * `NETWORK_ERROR`. getRequestId() é o `X-Request-Id` que a SDK enviou — procure-o
 * nos logs da API, se a requisição chegou.
 */
class NetworkException extends BzapperException
{
    public function __construct(string $message, ?string $requestId = null, ?\Throwable $previous = null)
    {
        parent::__construct('NETWORK_ERROR', $message, 0, null, null, $previous, $requestId);
    }
}
