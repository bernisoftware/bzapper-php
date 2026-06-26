<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Lançada quando a assinatura de um webhook está ausente ou não confere.
 */
final class WebhookSignatureException extends \Exception
{
}

/**
 * Receptor de webhooks do bZapper.
 *
 * Recebe o corpo CRU da requisição, **verifica a assinatura HMAC-SHA256**, parseia
 * o envelope num evento (array associativo) e roteia para handlers registrados por
 * tipo de evento. Sem dependências externas.
 *
 * A API assina toda entrega com `X-Bzapper-Signature: sha256=<hex>`, onde o hex é
 * `HMAC_SHA256(secret, corpo_cru)`. Também envia `X-Bzapper-Event-Id` e
 * `X-Bzapper-Event-Type`.
 *
 * ```php
 * use Bzapper\Webhooks;
 *
 * $hooks = new Webhooks('whsec_...'); // o secret do webhook (de createWebhook)
 *
 * $hooks->on('message.received', function (array $event): void {
 *     echo $event['sender']['name'] ?? '', ': ', $event['payload']['body'] ?? '';
 * });
 *
 * // No seu endpoint HTTP (framework-agnóstico):
 * $raw = file_get_contents('php://input');
 * $sig = $_SERVER['HTTP_X_BZAPPER_SIGNATURE'] ?? null;
 * $hooks->handle($raw, $sig); // verifica, parseia e despacha; lança em assinatura inválida
 * ```
 *
 * Idempotência: cada evento traz um `event_id` estável — guarde os ids já
 * processados (Redis/DB) e ignore duplicatas; a API pode reentregar.
 */
final class Webhooks
{
    /** Header com a assinatura (`sha256=<hex>`). */
    public const SIGNATURE_HEADER = 'X-Bzapper-Signature';

    /** Header com o id estável do evento (idempotência). */
    public const EVENT_ID_HEADER = 'X-Bzapper-Event-Id';

    /** Header com o tipo do evento. */
    public const EVENT_TYPE_HEADER = 'X-Bzapper-Event-Type';

    /**
     * Todos os tipos de evento que a API pode entregar (referência/autocomplete).
     *
     * @var list<string>
     */
    public const EVENT_TYPES = [
        'message.received', 'message.sent', 'message.delivered', 'message.read', 'message.failed',
        'instance.connected', 'instance.disconnected', 'instance.banned', 'instance.logged_out',
        'instance.warming', 'instance.status',
        'group.joined', 'group.participant_added', 'group.participant_removed',
        'group.participant_promoted', 'group.participant_demoted',
        'group.subject_changed', 'group.description_changed',
    ];

    private string $secret;

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    /** @var list<callable> */
    private array $any = [];

    /**
     * @param string $secret O segredo de assinatura do webhook (retornado uma única
     *                        vez por createWebhook).
     */
    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Webhooks: `secret` é obrigatório.');
        }
        $this->secret = $secret;
    }

    /**
     * Verifica se `$signature` confere com o HMAC do corpo **cru**.
     *
     * Timing-safe (hash_equals). Passe os bytes exatos recebidos — nunca o JSON
     * re-serializado. Retorna false se a assinatura vier vazia/nula.
     */
    public static function verify(string $secret, string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Verifica a assinatura e parseia o corpo no evento (array associativo do
     * envelope, com as chaves garantidas).
     *
     * @return array<string,mixed>
     *
     * @throws WebhookSignatureException Se a assinatura estiver ausente ou inválida.
     */
    public static function constructEvent(string $secret, string $rawBody, ?string $signature): array
    {
        if (!self::verify($secret, $rawBody, $signature)) {
            throw new WebhookSignatureException('Assinatura de webhook inválida.');
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new WebhookSignatureException(
                'Corpo de webhook inválido (não é JSON de objeto): ' . json_last_error_msg()
            );
        }
        return self::normalize($decoded);
    }

    /**
     * Registra um handler para um tipo de evento. Encadeável.
     *
     * ```php
     * $hooks->on('message.received', fn (array $e) => ...);
     * ```
     */
    public function on(string $eventType, callable $handler): self
    {
        $this->handlers[$eventType][] = $handler;
        return $this;
    }

    /**
     * Registra um handler que roda para **todo** evento. Encadeável.
     */
    public function onAny(callable $handler): self
    {
        $this->any[] = $handler;
        return $this;
    }

    /**
     * Verifica + parseia uma entrega no evento tipado (sem despachar).
     *
     * @return array<string,mixed>
     *
     * @throws WebhookSignatureException Se a assinatura for inválida.
     */
    public function constructEventFor(string $rawBody, ?string $signature): array
    {
        return self::constructEvent($this->secret, $rawBody, $signature);
    }

    /**
     * Verifica, parseia e despacha uma entrega para os handlers do tipo + onAny.
     *
     * Retorna o evento parseado (use `$event['event_id']` para idempotência: guarde
     * os ids já tratados e ignore reentregas). Lança WebhookSignatureException se a
     * assinatura for inválida — NÃO processe nesse caso.
     *
     * @return array<string,mixed>
     *
     * @throws WebhookSignatureException
     */
    public function handle(string $rawBody, ?string $signature): array
    {
        $event = self::constructEvent($this->secret, $rawBody, $signature);
        $type = is_string($event['event_type']) ? $event['event_type'] : '';
        foreach ($this->handlers[$type] ?? [] as $h) {
            $h($event);
        }
        foreach ($this->any as $h) {
            $h($event);
        }
        return $event;
    }

    /**
     * Garante as chaves do envelope (mesma forma em todos os SDKs) sem perder os
     * campos crus já presentes.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private static function normalize(array $d): array
    {
        $d['event_id'] = isset($d['event_id']) && is_string($d['event_id']) ? $d['event_id'] : '';
        $d['event_type'] = isset($d['event_type']) && is_string($d['event_type']) ? $d['event_type'] : '';
        $d['timestamp'] = $d['timestamp'] ?? null;
        $d['instance_id'] = $d['instance_id'] ?? null;
        $d['client_reference'] = $d['client_reference'] ?? null;
        $d['group'] = isset($d['group']) && is_array($d['group']) ? $d['group'] : null;
        $d['sender'] = isset($d['sender']) && is_array($d['sender']) ? $d['sender'] : null;
        $d['mentions'] = isset($d['mentions']) && is_array($d['mentions']) ? array_values($d['mentions']) : [];
        $d['payload'] = isset($d['payload']) && is_array($d['payload']) ? $d['payload'] : [];
        return $d;
    }
}
