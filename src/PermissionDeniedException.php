<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * 403 — a chave não tem permissão (ex.: `admin_required`, `insufficient_scope`). Em erro de escopo, getRequiredScope() diz o escopo que faltou.
 */
class PermissionDeniedException extends BzapperException
{
}
