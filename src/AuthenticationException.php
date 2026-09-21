<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * 401 — API key ausente, inválida ou revogada (ex.: `unauthorized`, `connect_revoked`).
 */
class AuthenticationException extends BzapperException
{
}
