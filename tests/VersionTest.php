<?php

declare(strict_types=1);

namespace Bzapper\Tests;

use Bzapper\Client;
use Bzapper\Tests\Support\Cases;
use PHPUnit\Framework\TestCase;

/**
 * Versão: no PHP o manifesto (composer.json) NÃO leva versão — o Packagist a tira da tag git.
 * A fonte é `Client::VERSION`; o `scripts/release-sdks.sh` a bumpa por regex e o publish.yml
 * trava tag == VERSION. No monorepo, `clients/release.json` é a versão de TODAS as SDKs.
 */
final class VersionTest extends TestCase
{
    public function testVersionIsSemverAndClientIdFollowsIt(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Client::VERSION);
        $this->assertSame('bzapper-php/' . Client::VERSION, Client::CLIENT_ID);
    }

    public function testVersionLineMatchesReleaseScriptRegex(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Client.php');

        // mesmo padrão do scripts/release-sdks.sh (sub com count=1)
        $this->assertSame(1, preg_match_all("/public const VERSION = '([^']+)'/", $source, $m));
        $this->assertSame(Client::VERSION, $m[1][0]);
    }

    public function testManifestTakesVersionFromTag(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('bzapper/bzapper', $manifest['name']);
        $this->assertArrayNotHasKey('version', $manifest, 'a versão vem da tag git (vX.Y.Z == Client::VERSION)');
        $this->assertSame('Bzapper\\', array_key_first($manifest['autoload']['psr-4']));
        $this->assertSame([], array_values(array_diff(array_keys($manifest['require']), ['php', 'ext-curl', 'ext-json'])), 'zero dependência de runtime');
    }

    public function testVersionEqualsMonorepoReleaseManifest(): void
    {
        $release = dirname(__DIR__, 2) . '/release.json';
        if (!is_file($release)) {
            $this->markTestSkipped('fora do monorepo: clients/release.json não existe.');
        }
        $data = json_decode((string) file_get_contents($release), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($data['version'], Client::VERSION, 'Client::VERSION != clients/release.json');
    }

    public function testReleaseTagMatchesVersionWhenGiven(): void
    {
        $tag = getenv('BZAPPER_RELEASE_TAG');
        if (!is_string($tag) || $tag === '') {
            $this->markTestSkipped('BZAPPER_RELEASE_TAG não definido (só no publish.yml).');
        }
        $this->assertSame('v' . Client::VERSION, $tag);
    }

    public function testVendoredCasesMatchSource(): void
    {
        if (!is_file(Cases::sourcePath())) {
            $this->markTestSkipped('fora do monorepo: só existe a cópia tests/conformance/cases.json.');
        }
        $this->assertFileEquals(
            Cases::sourcePath(),
            Cases::vendoredPath(),
            'tests/conformance/cases.json desatualizado: rode clients/conformance/generate.py',
        );
    }
}
