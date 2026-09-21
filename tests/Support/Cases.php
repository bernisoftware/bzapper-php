<?php

declare(strict_types=1);

namespace Bzapper\Tests\Support;

/**
 * Localiza e carrega `cases.json` (conformidade).
 *
 * Ordem: `$BZAPPER_CONFORMANCE_CASES` → `clients/conformance/cases.json` (monorepo, a fonte) →
 * `tests/conformance/cases.json` (cópia que viaja com o espelho público, onde a fonte não existe).
 * No monorepo, `VersionTest` trava que a cópia seja idêntica à fonte.
 */
final class Cases
{
    /** @var array<string, mixed>|null */
    private static ?array $assoc = null;

    private static ?\stdClass $objects = null;

    public static function path(): string
    {
        $env = getenv('BZAPPER_CONFORMANCE_CASES');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $source = self::sourcePath();

        return is_file($source) ? $source : self::vendoredPath();
    }

    public static function sourcePath(): string
    {
        return dirname(__DIR__, 3) . '/conformance/cases.json';
    }

    public static function vendoredPath(): string
    {
        return dirname(__DIR__) . '/conformance/cases.json';
    }

    /** @return array<string, mixed> */
    public static function assoc(): array
    {
        return self::$assoc ??= json_decode(self::raw(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Mesmo conteúdo como objetos — preserva a diferença entre `{}` e `[]`. */
    public static function objects(): \stdClass
    {
        return self::$objects ??= json_decode(self::raw(), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function raw(): string
    {
        $raw = @file_get_contents(self::path());
        if ($raw === false) {
            throw new \RuntimeException('cases.json não encontrado em ' . self::path());
        }

        return $raw;
    }
}
