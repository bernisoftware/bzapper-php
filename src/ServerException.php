<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * 5xx — erro do servidor. 502/503/504 são tentados de novo automaticamente; 500 volta na hora.
 */
class ServerException extends BzapperException
{
}
