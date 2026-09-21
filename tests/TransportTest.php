<?php

declare(strict_types=1);

namespace Bzapper\Tests;

use Bzapper\BzapperException;
use Bzapper\Client;
use Bzapper\NetworkException;
use Bzapper\PartnerClient;
use Bzapper\RateLimitException;
use Bzapper\Tests\Support\MockServer;
use PHPUnit\Framework\TestCase;

/** Unitários do transporte (BRIEF §3–5) que a conformidade não cobre sozinha. */
final class TransportTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private function client(array $opts = [], ?string $baseUrl = null): Client
    {
        $this->sleeps = [];

        return new Client('bz_live_test', $baseUrl ?? MockServer::url(), $opts + [
            'sleep' => function (float $s): void {
                $this->sleeps[] = $s;
            },
        ]);
    }

    public function testNetworkErrorWhenServerIsDown(): void
    {
        $port = MockServer::freePort(); // porta livre = ninguém escutando
        $bz = $this->client(['timeout' => 2], 'http://127.0.0.1:' . $port);
        try {
            $bz->getMe();
            $this->fail('esperava NetworkException');
        } catch (NetworkException $e) {
            $this->assertInstanceOf(BzapperException::class, $e);
            $this->assertSame(0, $e->getStatusCode());
            $this->assertSame(0, $e->getCode());
            $this->assertSame('NETWORK_ERROR', $e->getErrorCode());
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $e->getRequestId());
            $this->assertStringContainsString((string) $e->getRequestId(), $e->getMessage());
        }
        $this->assertCount(2, $this->sleeps, 'max_retries padrão = 2 novas tentativas');
    }

    public function testUserIdempotencyKeyIsUsedVerbatimOnEveryAttempt(): void
    {
        MockServer::script([
            ['status' => 503, 'headers' => ['Retry-After' => '0'], 'body' => ['code' => 'unavailable', 'message' => 'x']],
            ['status' => 201, 'headers' => [], 'body' => ['id' => 't1']],
        ]);
        $result = $this->client()->createTag(['key' => 'vip'], ['idempotency_key' => 'minha-chave-42']);
        $this->assertSame(['id' => 't1'], $result);
        $got = MockServer::received();
        $this->assertCount(2, $got);
        $this->assertSame('minha-chave-42', $got[0]['headers']['idempotency-key']);
        $this->assertSame('minha-chave-42', $got[1]['headers']['idempotency-key']);
        $this->assertSame($got[0]['headers']['x-request-id'], $got[1]['headers']['x-request-id']);
    }

    public function testLegacySendIdempotencyOptionStillWorks(): void
    {
        MockServer::script([['status' => 202, 'headers' => [], 'body' => ['message_id' => 'm1']]]);
        $this->client()->sendText('+5511999999999', 'oi', ['idempotency_key' => 'pedido-1']);
        $got = MockServer::received();
        $this->assertSame('pedido-1', $got[0]['headers']['idempotency-key']);
        $this->assertSame(['to' => '+5511999999999', 'body' => 'oi'], json_decode($got[0]['body'], true));
    }

    public function testAutomaticIdempotencyKeyOnlyOnWrites(): void
    {
        MockServer::script([
            ['status' => 200, 'headers' => [], 'body' => ['data' => []]],
            ['status' => 204, 'headers' => [], 'body' => null],
        ]);
        $bz = $this->client();
        $bz->listTags();
        $this->assertNull($bz->deleteTag('t1'));
        $got = MockServer::received();
        $this->assertArrayNotHasKey('idempotency-key', $got[0]['headers']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $got[1]['headers']['idempotency-key']);
    }

    public function testMaxRetriesZeroDisablesRetries(): void
    {
        MockServer::script([['status' => 429, 'headers' => ['Retry-After' => '7'], 'body' => ['code' => 'rate_limited', 'message' => 'devagar']]]);
        try {
            $this->client(['max_retries' => 0])->listPools();
            $this->fail('esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(7, $e->getRetryAfter());
            $this->assertSame('rate_limited', $e->getErrorCode());
            $this->assertSame('devagar', $e->getMessage());
            $this->assertSame(['code' => 'rate_limited', 'message' => 'devagar'], $e->getBody());
        }
        $this->assertCount(1, MockServer::received());
        $this->assertSame([], $this->sleeps);
    }

    public function testRetryAfterIsCappedAt60Seconds(): void
    {
        MockServer::script([
            ['status' => 429, 'headers' => ['Retry-After' => '3600'], 'body' => ['code' => 'rate_limited']],
            ['status' => 200, 'headers' => [], 'body' => ['ok' => true]],
        ]);
        $this->assertSame(['ok' => true], $this->client()->getHealth());
        $this->assertSame([60.0], $this->sleeps);
    }

    public function testLocaleAndProjectHeaders(): void
    {
        MockServer::script([['status' => 200, 'headers' => [], 'body' => ['ok' => true]]]);
        $this->client(['locale' => 'en', 'project_id' => 'p-1'])->getMe();
        $h = MockServer::received()[0]['headers'];
        $this->assertSame('en', $h['accept-language']);
        $this->assertSame('p-1', $h['x-project-id']);
    }

    public function testEmptyBodyIsSentAsObjectAndDatesAsUtcZ(): void
    {
        MockServer::script([
            ['status' => 202, 'headers' => [], 'body' => ['ok' => true]],
            ['status' => 200, 'headers' => [], 'body' => ['data' => []]],
        ]);
        $bz = $this->client();
        $bz->triggerWebhookEvent();
        $bz->listContacts([
            'created_after' => new \DateTimeImmutable('2026-09-21 09:00:00', new \DateTimeZone('America/Sao_Paulo')),
            'has_email' => false,
            'tags' => ['vip', 'b2b'],
        ]);
        $got = MockServer::received();
        $this->assertSame('{}', $got[0]['body']);
        $this->assertSame('/contacts?tags=vip%2Cb2b&has_email=false&created_after=2026-09-21T12%3A00%3A00Z', $got[1]['uri']);
    }

    public function testPathParamsAreValidatedBeforeAnyRequest(): void
    {
        MockServer::script([]);
        foreach (['', '.', '..'] as $bad) {
            try {
                $this->client()->deleteContact($bad);
                $this->fail('esperava InvalidArgumentException para "' . $bad . '"');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], MockServer::received());
    }

    public function testUnknownRequestOptionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->getMe(['idempotencyKey' => 'x']);
    }

    public function testEmptyApiKeyIsArgumentError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('');
    }

    public function testConstructionMakesNoRequestAndLegacyArgOrderStillWorks(): void
    {
        MockServer::script([['status' => 200, 'headers' => [], 'body' => ['ok' => true]]]);
        $bz = new Client(MockServer::url(), 'bz_live_legacy'); // assinatura antiga (baseUrl, apiKey)
        $partner = new PartnerClient('bz_partner_x', MockServer::url());
        $this->assertSame([], MockServer::received());
        $bz->getBrand();
        $this->assertSame('Bearer bz_live_legacy', MockServer::received()[0]['headers']['authorization']);
        $this->assertInstanceOf(PartnerClient::class, $partner);
    }

    public function testMultipartUpload(): void
    {
        MockServer::script([['status' => 200, 'headers' => [], 'body' => ['url' => 'https://cdn/x.png']]]);
        $this->client()->uploadCampaignMedia("\x89PNG\r\n", 'banner.png', 'image/png', ['alt' => 'Banner']);
        $got = MockServer::received()[0];
        $this->assertStringStartsWith('multipart/form-data; boundary=', $got['headers']['content-type']);
        $this->assertStringContainsString('name="file"; filename="banner.png"', $got['body']);
        $this->assertStringContainsString("Content-Type: image/png\r\n\r\n\x89PNG\r\n", $got['body']);
        $this->assertStringContainsString("name=\"alt\"\r\n\r\nBanner", $got['body']);
        $this->assertNotSame('', $got['headers']['idempotency-key'] ?? '');
    }
}
