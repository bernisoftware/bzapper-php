<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * 409 — conflito de estado (ex.: `idempotency_in_progress`, `connection_not_active`).
 */
class ConflictException extends BzapperException
{
}
