<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * 429 — limite de taxa/plano (ex.: `rate_limited`). getRetryAfter() traz os segundos do header Retry-After. A SDK já tenta de novo sozinha (maxRetries) antes de lançar.
 */
class RateLimitException extends BzapperException
{
}
