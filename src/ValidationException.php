<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * 400 ou 422 — requisição inválida (ex.: `invalid_request`, `invalid_body`, `idempotency_key_reused`).
 */
class ValidationException extends BzapperException
{
}
