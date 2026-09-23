<?php

declare(strict_types=1);

namespace Bzapper\Tests;

use Bzapper\AuthenticationException;
use Bzapper\Client;
use Bzapper\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

/**
 * `exportContacts` devolve CSV, não JSON (BRIEF §6) — fica fora dos casos gerados, então
 * a cobertura é aqui: caminho + query, o texto voltando intacto (vírgulas e aspas
 * incluídas) e nenhuma tentativa de decodificar JSON no caminho de sucesso.
 */
final class ExportContactsTest extends TestCase
{
    /** CSV de verdade: campo com vírgula, campo com aspas escapadas e campo vazio. */
    private const CSV = "phone,name,email,tags\r\n"
        . "+5511999990000,\"Ana, a VIP\",ana@example.com,\"vip;b2b\"\r\n"
        . "+5511888880000,\"Bruno \"\"Br\"\" Silva\",,\r\n";

    private function client(array $opts = []): Client
    {
        return new Client('bz_live_test', MockServer::url(), $opts + ['sleep' => static function (float $s): void {}]);
    }

    /** @param array<string,mixed> $extra */
    private static function csvResponse(array $extra = []): array
    {
        return $extra + [
            'status' => 200,
            'headers' => ['Content-Disposition' => 'attachment; filename="contatos.csv"'],
            'content_type' => 'text/csv; charset=utf-8',
            'body' => self::CSV,
        ];
    }

    public function testReturnsRawCsvTextIntact(): void
    {
        MockServer::script([self::csvResponse()]);

        $csv = $this->client()->exportContacts();

        // Byte a byte: nada de reencodar, reordenar ou "arrumar" aspas/CRLF.
        $this->assertSame(self::CSV, $csv);
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            array_filter(explode("\r\n", $csv), static fn (string $l): bool => $l !== ''),
        );
        $this->assertSame(['phone', 'name', 'email', 'tags'], $rows[0]);
        $this->assertSame('Ana, a VIP', $rows[1][1], 'campo com vírgula preservado');
        $this->assertSame('Bruno "Br" Silva', $rows[2][1], 'aspas escapadas preservadas');
        $this->assertSame('', $rows[2][2], 'campo vazio preservado');
    }

    public function testSendsExactPathAndQueryAndAsksForCsv(): void
    {
        MockServer::script([self::csvResponse()]);

        $this->client()->exportContacts([
            'search' => 'ana silva',
            'tags' => ['vip', 'b2b'],
            'tags_match' => 'all',
            'groups' => ['clientes'],
            'status' => 'active',
            'city' => 'São Paulo',
            'has_email' => true,
            'created_after' => new \DateTimeImmutable('2026-09-21 09:00:00', new \DateTimeZone('America/Sao_Paulo')),
            'sort' => 'name',
            'limit' => '500', // numérico em string vira int, como em listContacts
            'offset' => 10,   // a exportação não pagina: ignorado, não vai no fio
        ]);

        $got = MockServer::received();
        $this->assertCount(1, $got);
        $this->assertSame('GET', $got[0]['method']);
        $this->assertSame(
            '/contacts/export?search=ana%20silva&tags=vip%2Cb2b&tags_match=all&groups=clientes'
            . '&status=active&city=S%C3%A3o%20Paulo&has_email=true'
            . '&created_after=2026-09-21T12%3A00%3A00Z&sort=name&limit=500',
            $got[0]['uri'],
        );
        $this->assertSame('', $got[0]['body'], 'GET não manda corpo');
        // Pedimos CSV explicitamente — o caminho de sucesso não passa pelo JSON.
        $this->assertStringContainsString('text/csv', $got[0]['headers']['accept']);
        $this->assertArrayNotHasKey('idempotency-key', $got[0]['headers'], 'leitura não é escrita');
        $this->assertMatchesRegularExpression('/^bzapper-php\/' . preg_quote(Client::VERSION, '/') . '$/', $got[0]['headers']['x-bzapper-client']);
    }

    /**
     * O CSV não é JSON: se a SDK tentasse decodificar, isto viraria INVALID_RESPONSE.
     * Um CSV de uma coluna cujo valor é um número TAMBÉM não pode ser convertido em número.
     */
    public function testSuccessBodyIsNeverParsedAsJson(): void
    {
        MockServer::script([
            self::csvResponse(),
            self::csvResponse(['body' => "total\r\n42\r\n"]),
            self::csvResponse(['body' => 'phone']), // sem quebra de linha final
            self::csvResponse(['body' => '']),      // exportação vazia
        ]);
        $bz = $this->client();

        $this->assertSame(self::CSV, $bz->exportContacts());
        $this->assertSame("total\r\n42\r\n", $bz->exportContacts(['limit' => 1]));
        $this->assertSame('phone', $bz->exportContacts(['search' => 'ninguém']));
        $this->assertSame('', $bz->exportContacts(['status' => 'blocked']));
    }

    /** Corpo de ERRO continua sendo JSON e continua virando exceção tipada. */
    public function testErrorResponseStillBecomesTypedException(): void
    {
        MockServer::script([[
            'status' => 401,
            'headers' => [],
            'body' => ['code' => 'invalid_api_key', 'message' => 'chave inválida', 'locale' => 'pt-BR'],
        ]]);

        try {
            $this->client()->exportContacts();
            $this->fail('esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame('invalid_api_key', $e->getErrorCode());
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    /** CSV grande é leitura repetível: 503 volta a tentar e o texto final é o do sucesso. */
    public function testRetriesOnUnavailableAndKeepsRequestId(): void
    {
        MockServer::script([
            ['status' => 503, 'headers' => ['Retry-After' => '0'], 'body' => ['code' => 'unavailable', 'message' => 'x']],
            self::csvResponse(),
        ]);

        $this->assertSame(self::CSV, $this->client(['timeout' => 5])->exportContacts(['tags' => ['vip']]));
        $got = MockServer::received();
        $this->assertCount(2, $got);
        $this->assertSame($got[0]['headers']['x-request-id'], $got[1]['headers']['x-request-id']);
    }
}
