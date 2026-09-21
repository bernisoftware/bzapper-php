<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Cliente oficial do bZapper — gateway de WhatsApp multi-tenant.
 *
 * ```php
 * $bz = new \Bzapper\Client('bz_live_...'); // aponta para produção
 * $msg = $bz->sendText('+5511999999999', 'Olá do bZapper!');
 * echo $msg['message_id'];
 * ```
 *
 * Sem dependências externas: usa a extensão cURL nativa.
 *
 * @phpstan-type SendBase array{
 *     instance_id?: string,
 *     pool_id?: string,
 *     quoted_message_id?: string,
 *     quoted_participant?: string,
 *     client_reference?: string,
 *     mentions?: list<string>,
 *     sticky?: bool,
 *     scheduled_at?: string,
 *     tags?: list<string>,
 *     groups?: list<string>,
 *     force?: bool,
 *     idempotency_key?: string
 * }
 *
 * `quoted_participant`: autor (telefone ou JID) da mensagem citada/reagida — só
 * é preciso em grupo quando ela não está no histórico do bZapper.
 * `mentions`: JIDs ou telefones ("5511…", "+55 11 9…").
 * `tags`/`groups`: chaves de tag/grupo de contato aplicadas ao destinatário no CRM.
 * `force`: envia mesmo a um contato suprimido/opt-out.
 * `idempotency_key`: vai no header `Idempotency-Key` (até 255 caracteres), NUNCA
 * no corpo. Repetir o envio com a mesma chave em 24h devolve a MESMA resposta
 * sem reenviar (409 idempotency_in_progress / 422 idempotency_key_reused).
 *
 * @phpstan-type RequestOptions array{idempotency_key?: string, timeout?: int|float}
 *
 * `RequestOptions` (último argumento dos métodos adicionados no padrão r2): `idempotency_key`
 * substitui a Idempotency-Key automática de uma escrita; `timeout` (segundos) vale só
 * para esta chamada.
 *
 * @phpstan-type ListContactsParams array{
 *     search?: string, tags?: list<string>|string, tags_match?: 'any'|'all',
 *     groups?: list<string>|string, project_id?: string, instance_id?: string,
 *     status?: 'active'|'pending_validation'|'opted_out'|'blocked'|'unreachable',
 *     city?: string, state?: string, country?: string, zip?: string, document?: string,
 *     has_email?: bool, last_activity_after?: string|\DateTimeInterface,
 *     last_activity_before?: string|\DateTimeInterface, created_after?: string|\DateTimeInterface,
 *     created_before?: string|\DateTimeInterface, sort?: string, limit?: int, offset?: int
 * }
 *
 * @phpstan-type ContactAddress array{
 *     street?: string|null, number?: string|null, complement?: string|null, district?: string|null,
 *     city?: string|null, state?: string|null, zip?: string|null, country?: string|null
 * }
 * @phpstan-type ContactInput array{
 *     phone?: string, name?: string|null, email?: string|null, document?: string|null,
 *     document_type?: string|null, address?: ContactAddress|null
 * }
 * @phpstan-type BrandProfile array{
 *     about?: string, display_name?: string, logo_url?: string, website?: string,
 *     email?: string, phone?: string, address?: string, description?: string
 * }
 */
final class Client
{
    use HttpTransport;

    /** Versão do SDK (usada no User-Agent). */
    public const VERSION = '0.7.0';

    /** Valor de `X-Bzapper-Client` / `User-Agent` enviado em toda requisição. */
    public const CLIENT_ID = 'bzapper-php/' . self::VERSION;

    /** URL base padrão da API (produção). Sobrescreva só em dev/self-host. */
    public const DEFAULT_BASE_URL = 'https://api.bzapper.com.br';

    /**
     * @param string      $apiKey  API key do tenant (ex.: "bz_live_..."). Único obrigatório.
     * @param string|null $baseUrl URL base da API. Opcional — default produção
     *                             (https://api.bzapper.com.br); informe só em dev
     *                             ("http://localhost:8080") ou self-host.
     * @param array{locale?: string, timeout?: int|float, max_retries?: int, project_id?: string, sleep?: callable(float): void} $opts
     *                        locale: BCP-47 enviado em Accept-Language (ex.: "pt-BR").
     *                        timeout: timeout por tentativa em segundos (default 30).
     *                        max_retries: novas tentativas além da primeira em erro de
     *                        rede/429/502/503/504 (default 2; 0 desliga).
     *                        project_id: enviado como X-Project-Id (escopo de projeto).
     *                        sleep: função de espera entre tentativas (testes).
     */
    public function __construct(string $apiKey, ?string $baseUrl = null, array $opts = [])
    {
        // Compatibilidade: a assinatura antiga era (baseUrl, apiKey). Se o 1º
        // argumento parecer uma URL, tratamos como o formato legado.
        if ($baseUrl !== null && \str_starts_with($apiKey, 'http')) {
            [$apiKey, $baseUrl] = [$baseUrl, $apiKey];
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey não pode ser vazio.');
        }
        $this->initTransport($apiKey, $baseUrl, $opts);
    }

    // ---------------------------------------------------------------------
    // Mensagens
    // ---------------------------------------------------------------------

    /**
     * Envia mensagem de texto. POST /messages/text
     *
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendText(string $to, string $body, array $opts = []): array
    {
        return $this->send('/messages/text', $this->base($to, $opts) + ['body' => $body], $opts);
    }

    /**
     * Envia um código OTP (texto de contexto + código sozinho num balão). Conta
     * como 1 envio. Sem 'body' em $opts, a API gera o texto no idioma da conta.
     * POST /messages/otp
     *
     * @param SendBase $opts pode incluir 'body' e 'expiry_minutes'
     * @return array<string,mixed>
     */
    public function sendOtp(string $to, string $code, array $opts = []): array
    {
        $payload = $this->base($to, $opts) + ['code' => $code];
        if (isset($opts['body'])) {
            $payload['body'] = $opts['body'];
        }
        if (isset($opts['expiry_minutes'])) {
            $payload['expiry_minutes'] = $opts['expiry_minutes'];
        }
        return $this->send('/messages/otp', $payload, $opts);
    }

    /**
     * Envia imagem (url ou base64). POST /messages/image
     *
     * @param array{url?: string, base64?: string, caption?: string, filename?: string, mimetype?: string, ptt?: bool} $media
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendImage(string $to, array $media, array $opts = []): array
    {
        return $this->send('/messages/image', $this->base($to, $opts) + ['media' => $media], $opts);
    }

    /**
     * Envia vídeo. POST /messages/video
     *
     * @param array{url?: string, base64?: string, caption?: string, filename?: string, mimetype?: string, ptt?: bool} $media
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendVideo(string $to, array $media, array $opts = []): array
    {
        return $this->send('/messages/video', $this->base($to, $opts) + ['media' => $media], $opts);
    }

    /**
     * Envia documento. POST /messages/document
     *
     * @param array{url?: string, base64?: string, caption?: string, filename?: string, mimetype?: string, ptt?: bool} $media
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendDocument(string $to, array $media, array $opts = []): array
    {
        return $this->send('/messages/document', $this->base($to, $opts) + ['media' => $media], $opts);
    }

    /**
     * Envia áudio. Use `media['ptt'] = true` para nota de voz. POST /messages/audio
     *
     * @param array{url?: string, base64?: string, caption?: string, filename?: string, mimetype?: string, ptt?: bool} $media
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendAudio(string $to, array $media, array $opts = []): array
    {
        return $this->send('/messages/audio', $this->base($to, $opts) + ['media' => $media], $opts);
    }

    /**
     * Envia sticker. POST /messages/sticker
     *
     * @param array{url?: string, base64?: string, caption?: string, filename?: string, mimetype?: string, ptt?: bool} $media
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendSticker(string $to, array $media, array $opts = []): array
    {
        return $this->send('/messages/sticker', $this->base($to, $opts) + ['media' => $media], $opts);
    }

    /**
     * Envia localização. POST /messages/location
     *
     * @param array{name?: string, address?: string} $opts Aceita também campos de SendBase.
     * @return array<string,mixed>
     */
    public function sendLocation(string $to, float $latitude, float $longitude, array $opts = []): array
    {
        $payload = $this->base($to, $opts) + ['latitude' => $latitude, 'longitude' => $longitude];
        foreach (['name', 'address'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        return $this->send('/messages/location', $payload, $opts);
    }

    /**
     * Envia contato (vCard). POST /messages/contact
     *
     * @param array{contact_name?: string, contact_vcard?: string} $opts Aceita também campos de SendBase.
     * @return array<string,mixed>
     */
    public function sendContact(string $to, array $opts = []): array
    {
        $payload = $this->base($to, $opts);
        foreach (['contact_name', 'contact_vcard'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        return $this->send('/messages/contact', $payload, $opts);
    }

    /**
     * Envia enquete. POST /messages/poll
     *
     * @param list<string> $options
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendPoll(string $to, string $name, array $options, int $selectableCount = 1, array $opts = []): array
    {
        $payload = $this->base($to, $opts) + [
            'name' => $name,
            'options' => array_values($options),
            'selectable_count' => $selectableCount,
        ];
        return $this->send('/messages/poll', $payload, $opts);
    }

    /**
     * Reage a uma mensagem. POST /messages/reaction
     *
     * @param SendBase $opts
     * @return array<string,mixed>
     */
    public function sendReaction(string $to, string $quotedMessageId, string $emoji, array $opts = []): array
    {
        $payload = $this->base($to, $opts) + [
            'quoted_message_id' => $quotedMessageId,
            'emoji' => $emoji,
        ];
        return $this->send('/messages/reaction', $payload, $opts);
    }

    /**
     * Envia botões. POST /messages/buttons
     *
     * Caveat: botões não são confiáveis no WhatsApp; a API SEMPRE envia também
     * um menu de texto numerado equivalente como fallback.
     *
     * @param list<array{id?: string, title: string}> $buttons
     * @param array{footer?: string} $opts Aceita também campos de SendBase.
     * @return array<string,mixed>
     */
    public function sendButtons(string $to, string $body, array $buttons, array $opts = []): array
    {
        $payload = $this->base($to, $opts) + ['body' => $body, 'buttons' => array_values($buttons)];
        if (isset($opts['footer'])) {
            $payload['footer'] = $opts['footer'];
        }
        return $this->send('/messages/buttons', $payload, $opts);
    }

    /**
     * Envia lista. POST /messages/list
     *
     * Caveat: como os botões, pode cair para menu de texto numerado.
     *
     * @param list<array{title?: string, rows: list<array{id?: string, title: string, description?: string}>}> $sections
     * @param array{footer?: string, button_text?: string} $opts Aceita também campos de SendBase.
     * @return array<string,mixed>
     */
    public function sendList(string $to, string $body, array $sections, array $opts = []): array
    {
        $payload = $this->base($to, $opts) + ['body' => $body, 'sections' => array_values($sections)];
        foreach (['footer', 'button_text'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        return $this->send('/messages/list', $payload, $opts);
    }

    // ---------------------------------------------------------------------
    // Envio agendado (scheduled_at em qualquer envio)
    // ---------------------------------------------------------------------

    /**
     * Lista os agendamentos pendentes/recentes. GET /messages/scheduled?limit=
     *
     * @param array{limit?: int} $params
     * @return array<string,mixed>
     */
    public function listScheduled(array $params = []): array
    {
        return $this->get('/messages/scheduled', self::pick($params, ['limit']));
    }

    /**
     * Cancela um agendamento ainda pendente. DELETE /messages/scheduled/{id}
     *
     * @return array<string,mixed>
     */
    public function cancelScheduled(string $scheduledId): array
    {
        return $this->delete('/messages/scheduled/' . self::seg($scheduledId));
    }

    // ---------------------------------------------------------------------
    // Campanhas (Pro + add-on de campanhas)
    // ---------------------------------------------------------------------

    /**
     * Cria uma campanha com variações de template. POST /campaigns
     * Exige o plano Pro e o add-on de Campanhas. O corpo de cada variação aceita
     * {variaveis} e spintax {a|b|c}.
     *
     * @param array<int,array<string,mixed>> $variations
     * @param array<string,mixed> $opts  name, pool_id, pacing_profile, start_at
     * @return array<string,mixed>
     */
    public function createCampaign(array $variations, array $opts = []): array
    {
        $body = ['variations' => array_values($variations)];
        foreach (['name', 'pool_id', 'pacing_profile', 'start_at'] as $k) {
            if (isset($opts[$k])) {
                $body[$k] = $opts[$k];
            }
        }
        return $this->post('/campaigns', $body);
    }

    /**
     * Lista as campanhas do projeto. GET /campaigns?limit=
     *
     * @param array{limit?: int} $params
     * @return array<string,mixed>
     */
    public function listCampaigns(array $params = []): array
    {
        return $this->get('/campaigns', self::pick($params, ['limit']));
    }

    /**
     * Campanha + estatísticas. GET /campaigns/{id}
     *
     * @return array<string,mixed>
     */
    public function getCampaign(string $id): array
    {
        return $this->get('/campaigns/' . self::seg($id));
    }

    /**
     * Edita uma campanha ainda não iniciada. PATCH /campaigns/{id}
     * Só permitido enquanto a campanha está draft/scheduled — 409 depois de iniciada.
     * Se `variations` for enviado, substitui as variações existentes.
     *
     * @param array{name?: string, pool_id?: string, pacing_profile?: string, start_at?: string, variations?: array<int,array<string,mixed>>} $body
     * @return array<string,mixed> A campanha atualizada.
     */
    public function updateCampaign(string $id, array $body): array
    {
        $payload = [];
        foreach (['name', 'pool_id', 'pacing_profile', 'start_at'] as $k) {
            if (isset($body[$k])) {
                $payload[$k] = $body[$k];
            }
        }
        if (isset($body['variations']) && is_array($body['variations'])) {
            $payload['variations'] = array_values($body['variations']);
        }
        return $this->patch('/campaigns/' . self::seg($id), $payload);
    }

    /**
     * Estima a duração do envio (ao vivo, sem criar campanha). GET /campaigns/estimate
     * Alimenta o painel em tempo real do construtor: dado um número de destinatários
     * e o perfil de ritmo, devolve os números elegíveis e a duração estimada.
     *
     * @param int|null    $recipients Número de destinatários.
     * @param string|null $pacing     "conservative" ou "normal".
     * @param string|null $poolId     Restringe a estimativa a um pool.
     * @return array<string,mixed> { recipients, numbers_available, estimated_seconds, estimated_human }
     */
    public function estimateCampaign(?int $recipients = null, ?string $pacing = null, ?string $poolId = null): array
    {
        $query = [];
        if ($recipients !== null) {
            $query['recipients'] = $recipients;
        }
        if ($pacing !== null) {
            $query['pacing'] = $pacing;
        }
        if ($poolId !== null) {
            $query['pool_id'] = $poolId;
        }
        return $this->get('/campaigns/estimate', $query);
    }

    /**
     * Adiciona (ou substitui) destinatários. POST /campaigns/{id}/recipients
     *
     * Combine as formas conforme necessário:
     *  - `recipients`: lista de {phone, payload} (payload = variáveis por contato).
     *  - `contacts`: mapa telefone → payload.
     *  - `contact_ids`: lista de ids de contatos escolhidos (só os ativos entram).
     *  - `contact_filter`: adiciona todo contato ATIVO que casa com o filtro
     *    (assoc: search, tags, tags_all, groups, city, state, country, has_email).
     *  - `replace` (bool): substitui toda a lista em vez de acrescentar (limpa antes);
     *    só permitido enquanto a campanha está draft/scheduled.
     *
     * Para contact_ids/contact_filter os telefones são resolvidos no SERVIDOR e
     * restritos a contatos ativos (bloqueados/opt-out/inalcançáveis nunca entram);
     * a supressão é reverificada.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function addCampaignRecipients(string $id, array $body): array
    {
        return $this->post('/campaigns/' . self::seg($id) . '/recipients', $body);
    }

    /**
     * Lista destinatários. GET /campaigns/{id}/recipients
     * Cada item inclui contact_name, status (pending|claimed|sent|failed|suppressed),
     * delivery (''|sent|delivered|read, estado real pelos recibos do WhatsApp),
     * message_id e last_error.
     *
     * @param array{limit?: int} $params
     * @return array<string,mixed>
     */
    public function listCampaignRecipients(string $id, array $params = []): array
    {
        return $this->get('/campaigns/' . self::seg($id) . '/recipients', self::pick($params, ['limit']));
    }

    /**
     * Inicia (ou agenda) a campanha. POST /campaigns/{id}/start
     *
     * @return array<string,mixed>
     */
    public function startCampaign(string $id): array
    {
        return $this->post('/campaigns/' . self::seg($id) . '/start');
    }

    /**
     * Pausa a campanha. POST /campaigns/{id}/pause
     *
     * @return array<string,mixed>
     */
    public function pauseCampaign(string $id): array
    {
        return $this->post('/campaigns/' . self::seg($id) . '/pause');
    }

    /**
     * Retoma a campanha. POST /campaigns/{id}/resume
     *
     * @return array<string,mixed>
     */
    public function resumeCampaign(string $id): array
    {
        return $this->post('/campaigns/' . self::seg($id) . '/resume');
    }

    /**
     * Cancela a campanha. POST /campaigns/{id}/cancel
     *
     * @return array<string,mixed>
     */
    public function cancelCampaign(string $id): array
    {
        return $this->post('/campaigns/' . self::seg($id) . '/cancel');
    }

    /**
     * Simula a campanha sem disparar. POST /campaigns/{id}/dry-run
     *
     * @return array<string,mixed>
     */
    public function dryRunCampaign(string $id): array
    {
        return $this->post('/campaigns/' . self::seg($id) . '/dry-run');
    }

    // ---------------------------------------------------------------------
    // Instâncias (números)
    // ---------------------------------------------------------------------

    /**
     * Lista instâncias (números) do tenant. GET /instances
     *
     * @param array{project_id?: string, archived?: string|bool} $params project_id: id do
     *                        projeto, ou "all" para todos os números da conta. Omitido usa o
     *                        projeto ativo (X-Project-Id). archived: "1" lista os arquivados.
     * @return array<string,mixed>
     */
    public function listInstances(array $params = []): array
    {
        return $this->get('/instances', self::pick($params, ['project_id', 'archived']));
    }

    /**
     * Cria uma instância (número). POST /instances
     *
     * @param array{nickname?: string, proxy_url?: string} $opts
     * @return array<string,mixed>
     */
    public function createInstance(string $phone, array $opts = []): array
    {
        $payload = ['phone' => $phone];
        foreach (['nickname', 'proxy_url'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        return $this->post('/instances', $payload);
    }

    /**
     * Detalha uma instância. GET /instances/{id}
     *
     * @return array<string,mixed>
     */
    public function getInstance(string $id): array
    {
        return $this->get('/instances/' . self::seg($id));
    }

    /**
     * Conecta a instância por QR ou código de pareamento.
     * POST /instances/{id}/connect?method=qr|code
     *
     * @param string $method "qr" (padrão) ou "code".
     * @return array<string,mixed> { status, qr_code?, pair_code? }
     */
    public function connectInstance(string $id, string $method = 'qr'): array
    {
        return $this->post(
            '/instances/' . self::seg($id) . '/connect',
            null,
            ['method' => $method]
        );
    }

    /**
     * Desconecta (reconectável). POST /instances/{id}/disconnect
     *
     * @return array<string,mixed>
     */
    public function disconnectInstance(string $id): array
    {
        return $this->post('/instances/' . self::seg($id) . '/disconnect');
    }

    /**
     * Apaga a credencial do dispositivo pareado, forçando um novo pareamento
     * limpo. Use quando o connectInstance não emite QR ou o pareamento travou:
     * o logout comum só desreferencia e deixa o dispositivo antigo para trás.
     *
     * Destrutivo e irreversível — o número fica offline e precisa escanear o QR
     * de novo. É idempotente e pode ser repetido com segurança.
     *
     * POST /instances/{id}/clear-session
     *
     * @return array<string,mixed>
     */
    public function clearInstanceSession(string $id): array
    {
        return $this->post('/instances/' . self::seg($id) . '/clear-session');
    }

    // ---------------------------------------------------------------------
    // API keys (self-serve)
    // ---------------------------------------------------------------------

    /**
     * Lista as API keys do tenant (sem a chave crua). GET /keys
     *
     * @return array<string,mixed>
     */
    public function listKeys(): array
    {
        return $this->get('/keys');
    }

    /**
     * Gera uma API key do tenant (a chave crua é mostrada uma única vez). POST /keys
     *
     * @param string $role "admin" ou "agent".
     * @return array<string,mixed> { api_key, key }
     */
    public function createKey(string $name, string $role): array
    {
        return $this->post('/keys', ['name' => $name, 'role' => $role]);
    }

    /**
     * Revoga uma API key do tenant. DELETE /keys/{id}
     *
     * @return array<string,mixed>
     */
    public function revokeKey(string $id): array
    {
        return $this->delete('/keys/' . self::seg($id));
    }

    // ---------------------------------------------------------------------
    // Uso
    // ---------------------------------------------------------------------

    /**
     * Resumo de uso do tenant. GET /usage
     *
     * @param array{from?: string, to?: string} $opts Datas RFC3339.
     * @return array<string,mixed>
     */
    public function getUsage(array $opts = []): array
    {
        $query = [];
        foreach (['from', 'to'] as $k) {
            if (isset($opts[$k])) {
                $query[$k] = $opts[$k];
            }
        }
        return $this->get('/usage', $query);
    }

    // ---------------------------------------------------------------------
    // Presença (funciona em grupos!)
    // ---------------------------------------------------------------------

    /**
     * Atualiza presença num chat (inclusive grupos). POST /presence/chat
     *
     * @param string $to    JID/E.164 do chat. Pode ser JID de grupo.
     * @param string $state "typing", "recording" ou "paused".
     * @return array<string,mixed>
     */
    public function presenceChat(string $instanceId, string $to, string $state): array
    {
        return $this->post('/presence/chat', [
            'instance_id' => $instanceId,
            'to' => $to,
            'state' => $state,
        ]);
    }

    // ---------------------------------------------------------------------
    // Conversas
    // ---------------------------------------------------------------------

    /**
     * Lista conversas da instância. GET /conversations?instance_id=
     *
     * @return array<string,mixed>
     */
    public function listConversations(string $instanceId): array
    {
        return $this->get('/conversations', ['instance_id' => $instanceId]);
    }

    /**
     * Histórico de uma conversa. GET /conversations/{jid}/messages?instance_id=&before=&limit=
     *
     * @param array{before?: string, limit?: int} $opts before RFC3339; limit ≤ 200.
     * @return array<string,mixed>
     */
    public function conversationHistory(string $instanceId, string $jid, array $opts = []): array
    {
        $query = ['instance_id' => $instanceId];
        if (isset($opts['before'])) {
            $query['before'] = $opts['before'];
        }
        if (isset($opts['limit'])) {
            $query['limit'] = (int) $opts['limit'];
        }
        return $this->get('/conversations/' . self::seg($jid, 'jid') . '/messages', $query);
    }

    /**
     * Arquiva/desarquiva um chat. POST /chats/{jid}/archive body { instance_id, on }
     *
     * @return array<string,mixed>
     */
    public function archiveChat(string $instanceId, string $jid, bool $on = true): array
    {
        return $this->post(
            '/chats/' . self::seg($jid, 'jid') . '/archive',
            ['instance_id' => $instanceId, 'on' => $on]
        );
    }

    /**
     * Fixa/desafixa um chat. POST /chats/{jid}/pin body { instance_id, on }
     *
     * @return array<string,mixed>
     */
    public function pinChat(string $instanceId, string $jid, bool $on = true): array
    {
        return $this->post(
            '/chats/' . self::seg($jid, 'jid') . '/pin',
            ['instance_id' => $instanceId, 'on' => $on]
        );
    }

    /**
     * Marca como lido/não-lido. POST /chats/{jid}/read body { instance_id, on }
     *
     * @return array<string,mixed>
     */
    public function markChat(string $instanceId, string $jid, bool $on = true): array
    {
        return $this->post(
            '/chats/' . self::seg($jid, 'jid') . '/read',
            ['instance_id' => $instanceId, 'on' => $on]
        );
    }

    // ---------------------------------------------------------------------
    // Grupos
    // ---------------------------------------------------------------------

    /**
     * Lista grupos da instância. GET /groups?instance_id=
     *
     * @return array<string,mixed>
     */
    public function listGroups(string $instanceId): array
    {
        return $this->get('/groups', ['instance_id' => $instanceId]);
    }

    /**
     * Cria um grupo. POST /groups?instance_id= body { name, participants[] }
     *
     * @param list<string> $participants
     * @return array<string,mixed>
     */
    public function createGroup(string $instanceId, string $name, array $participants): array
    {
        return $this->post(
            '/groups',
            ['name' => $name, 'participants' => array_values($participants)],
            ['instance_id' => $instanceId]
        );
    }

    /**
     * Detalha um grupo. GET /groups/{jid}?instance_id=
     *
     * @return array<string,mixed>
     */
    public function getGroup(string $instanceId, string $jid): array
    {
        return $this->get('/groups/' . self::seg($jid, 'jid'), ['instance_id' => $instanceId]);
    }

    /**
     * Mostra o grupo de um convite (nome, descrição, tamanho) SEM entrar — para
     * confirmar antes de colocar o número num grupo de terceiros.
     * POST /groups/join/preview?instance_id= body { code }
     *
     * @return array<string,mixed>
     */
    public function previewGroupInvite(string $instanceId, string $code): array
    {
        return $this->post('/groups/join/preview', ['code' => $code], ['instance_id' => $instanceId]);
    }

    /**
     * Entra num grupo por código de convite. POST /groups/join?instance_id= body { code }
     *
     * @return array<string,mixed>
     */
    public function joinGroup(string $instanceId, string $code): array
    {
        return $this->post('/groups/join', ['code' => $code], ['instance_id' => $instanceId]);
    }

    /**
     * Adiciona/remove/promove/rebaixa participantes.
     * POST /groups/{jid}/participants?instance_id= body { action, participants[] }
     *
     * @param string $action "add", "remove", "promote" ou "demote".
     * @param list<string> $participants
     * @return array<string,mixed>
     */
    public function updateGroupParticipants(string $instanceId, string $jid, string $action, array $participants): array
    {
        return $this->post(
            '/groups/' . self::seg($jid, 'jid') . '/participants',
            ['action' => $action, 'participants' => array_values($participants)],
            ['instance_id' => $instanceId]
        );
    }

    /**
     * Sai de um grupo. POST /groups/{jid}/leave?instance_id=
     *
     * @return array<string,mixed>
     */
    public function leaveGroup(string $instanceId, string $jid): array
    {
        return $this->post(
            '/groups/' . self::seg($jid, 'jid') . '/leave',
            null,
            ['instance_id' => $instanceId]
        );
    }

    /**
     * Obtém o link de convite do grupo (operação `groupInviteLink`).
     * GET /groups/{jid}/invite?instance_id=&reset=
     *
     * @param bool|null $reset true revoga o link atual e gera um novo.
     * @return array<string,mixed>
     */
    public function groupInvite(string $instanceId, string $jid, ?bool $reset = null): array
    {
        return $this->get(
            '/groups/' . self::seg($jid, 'jid') . '/invite',
            ['instance_id' => $instanceId, 'reset' => $reset]
        );
    }

    // ---------------------------------------------------------------------
    // Contatos
    // ---------------------------------------------------------------------

    /**
     * Verifica quais telefones têm WhatsApp. POST /contacts/check body { instance_id, phones[] }
     *
     * @param list<string> $phones
     * @return array<string,mixed>
     */
    public function contactsCheck(string $instanceId, array $phones): array
    {
        return $this->post('/contacts/check', [
            'instance_id' => $instanceId,
            'phones' => array_values($phones),
        ]);
    }

    /**
     * Atualiza o perfil da instância.
     * PATCH /instances/{id}/profile body { display_name?, status_message?, picture? }
     *
     * @param array{display_name?: string, status_message?: string, picture?: string} $profile
     * @return array<string,mixed>
     */
    public function setProfile(string $id, array $profile): array
    {
        $payload = [];
        foreach (['display_name', 'status_message', 'picture'] as $k) {
            if (isset($profile[$k])) {
                $payload[$k] = $profile[$k];
            }
        }
        return $this->patch('/instances/' . self::seg($id) . '/profile', $payload);
    }

    // ---------------------------------------------------------------------
    // Contatos (base capturada das conversas — compartilhada na conta)
    // ---------------------------------------------------------------------

    /**
     * Lista a base de contatos da conta (filtro opcional por projeto). GET /contacts
     *
     * @param ListContactsParams $params
     *                        project_id: id do projeto ou "current" (o da sua key).
     *                        instance_id: filtra por um número (instância) com que
     *                        o contato interagiu (vínculo mantido automaticamente
     *                        pela API). tags/groups: listas (vão como CSV);
     *                        tags_match "any"|"all"; datas RFC3339 ou \DateTimeInterface.
     * @return array<string,mixed> { data, total, limit, offset }
     */
    public function listContacts(array $params = []): array
    {
        $query = self::pick($params, [
            'search', 'tags', 'tags_match', 'groups', 'project_id', 'instance_id', 'status',
            'city', 'state', 'country', 'zip', 'document', 'has_email',
            'last_activity_after', 'last_activity_before', 'created_after', 'created_before',
            'sort', 'limit', 'offset',
        ]);
        if (isset($query['limit'])) {
            $query['limit'] = (int) $query['limit'];
        }
        return $this->get('/contacts', $query);
    }

    // ---------------------------------------------------------------------
    // Projetos (números, inbox, keys e stats são isolados por projeto)
    // ---------------------------------------------------------------------

    /**
     * Lista os projetos da conta. GET /projects
     *
     * @return array<string,mixed>
     */
    public function listProjects(): array
    {
        return $this->get('/projects');
    }

    /**
     * Cria um projeto (admin). POST /projects
     *
     * @param string|null $apiMode "UNOFFICIAL" (padrão) ou "OFFICIAL" (WhatsApp Cloud API).
     *                             Imutável depois de criado.
     * @return array<string,mixed>
     */
    public function createProject(string $name, ?string $apiMode = null): array
    {
        $payload = ['name' => $name];
        if ($apiMode !== null) {
            $payload['api_mode'] = $apiMode;
        }
        return $this->post('/projects', $payload);
    }

    // ---------------------------------------------------------------------
    // Identidade dos números (kit de marca — do projeto)
    // ---------------------------------------------------------------------

    /**
     * Lê a identidade dos números do projeto. GET /brand
     *
     * @return array<string,mixed>
     */
    public function getBrand(): array
    {
        return $this->get('/brand');
    }

    /**
     * Atualiza a identidade dos números do projeto. PUT /brand
     *
     * @param array{about?: string, display_name?: string, logo_url?: string, website?: string, email?: string, phone?: string, address?: string, description?: string} $profile
     * @return array<string,mixed>
     */
    public function setBrand(array $profile): array
    {
        return $this->put('/brand', $profile);
    }

    /**
     * Aplica o "Sobre" a todos os números conectados do projeto. POST /brand/apply
     *
     * @return array<string,mixed>
     */
    public function applyBrand(): array
    {
        return $this->post('/brand/apply');
    }

    // ---------------------------------------------------------------------
    // Conta: usuários e consumo (admin)
    // ---------------------------------------------------------------------

    /**
     * Lista os usuários da conta. GET /users
     *
     * @return array<string,mixed>
     */
    public function listUsers(): array
    {
        return $this->get('/users');
    }

    /**
     * Convida um usuário (admin). POST /users
     *
     * @param string|null $role "admin" ou "agent".
     * @return array<string,mixed>
     */
    public function inviteUser(string $email, ?string $name = null, ?string $role = null): array
    {
        $payload = ['email' => $email];
        if ($name !== null) {
            $payload['name'] = $name;
        }
        if ($role !== null) {
            $payload['role'] = $role;
        }
        return $this->post('/users', $payload);
    }

    /**
     * Troca o papel de um usuário (admin). PATCH /users/{id}
     *
     * @param string $role "admin" ou "agent".
     * @return array<string,mixed>
     */
    public function updateUserRole(string $id, string $role): array
    {
        return $this->patch('/users/' . self::seg($id), ['role' => $role]);
    }

    /**
     * Remove um usuário da conta (admin). DELETE /users/{id}
     *
     * @return array<string,mixed>
     */
    public function removeUser(string $id): array
    {
        return $this->delete('/users/' . self::seg($id));
    }

    /**
     * Consumo agregado da conta + por projeto (admin). GET /account/usage
     *
     * @param array{from?: string, to?: string} $params Datas RFC3339.
     * @return array<string,mixed>
     */
    public function getAccountUsage(array $params = []): array
    {
        $query = [];
        foreach (['from', 'to'] as $k) {
            if (isset($params[$k])) {
                $query[$k] = $params[$k];
            }
        }
        return $this->get('/account/usage', $query);
    }

    // ---------------------------------------------------------------------
    // Webhooks (gestão; para RECEBER+processar eventos use Bzapper\Webhooks)
    // ---------------------------------------------------------------------

    /**
     * Lista os avisos de AÇÃO NECESSÁRIA na sua integração. GET /advisories
     *
     * Um aviso significa que uma mudança nossa exige atualizar o SEU código (SDK
     * a atualizar, payload ou endpoint que mudou). Nunca é changelog: você só
     * recebe o que afeta a sua conta, cruzado com a versão de SDK que roda e os
     * recursos que de fato usa. O campo `action` diz o que fazer.
     *
     * @return array<string,mixed>
     */
    public function listAdvisories(): array
    {
        return $this->get('/advisories');
    }

    /**
     * Marca um aviso como tratado. POST /advisories/{id}/read
     *
     * @return array<string,mixed>
     */
    public function markAdvisoryRead(string $advisoryId): array
    {
        return $this->post('/advisories/' . self::seg($advisoryId) . '/read');
    }

    /**
     * Lista os webhooks do projeto. GET /webhooks
     *
     * @return array<string,mixed> { data: list<array<string,mixed>> }
     */
    public function listWebhooks(): array
    {
        return $this->get('/webhooks');
    }

    /**
     * Cria um webhook. POST /webhooks
     *
     * O `secret` (gerado pela API se omitido) é retornado UMA única vez no campo
     * `secret` da resposta — guarde-o para usar com Bzapper\Webhooks.
     *
     * @param string $url URL HTTPS que receberá as entregas.
     * @param array{secret?: string, event_types?: list<string>, number_filter?: string} $opts
     *                        event_types: eventos assinados (vazio/omitido = todos;
     *                        cada evento pertence a um único webhook, 409 em conflito).
     *                        number_filter: instance_id para restringir a um número.
     * @return array<string,mixed>
     */
    public function createWebhook(string $url, array $opts = []): array
    {
        $payload = ['url' => $url];
        if (isset($opts['secret'])) {
            $payload['secret'] = $opts['secret'];
        }
        if (isset($opts['event_types']) && is_array($opts['event_types'])) {
            $payload['event_types'] = array_values($opts['event_types']);
        }
        if (isset($opts['number_filter'])) {
            $payload['number_filter'] = $opts['number_filter'];
        }
        return $this->post('/webhooks', $payload);
    }

    /**
     * Atualiza/pausa um webhook. PATCH /webhooks/{id}
     *
     * Use `secret = "regenerate"` para rotacionar o segredo (novo valor volta na
     * resposta uma única vez). `active = false` pausa as entregas.
     *
     * @param array{url?: string, secret?: string, event_types?: list<string>, number_filter?: string, active?: bool} $opts
     * @return array<string,mixed>
     */
    public function updateWebhook(string $id, array $opts = []): array
    {
        $payload = [];
        foreach (['url', 'secret', 'number_filter'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        if (isset($opts['event_types']) && is_array($opts['event_types'])) {
            $payload['event_types'] = array_values($opts['event_types']);
        }
        if (isset($opts['active'])) {
            $payload['active'] = (bool) $opts['active'];
        }
        return $this->patch('/webhooks/' . self::seg($id), $payload);
    }

    /**
     * Remove um webhook. DELETE /webhooks/{id}
     *
     * @return array<string,mixed>
     */
    public function deleteWebhook(string $id): array
    {
        return $this->delete('/webhooks/' . self::seg($id));
    }

    /**
     * Dispara um evento de teste e retorna o status HTTP do endpoint.
     * POST /webhooks/{id}/test
     *
     * @param string|null $eventType Tipo do evento simulado (ex.: "message.received").
     * @return array<string,mixed>
     */
    public function testWebhook(string $id, ?string $eventType = null): array
    {
        $payload = [];
        if ($eventType !== null) {
            $payload['event_type'] = $eventType;
        }
        return $this->post('/webhooks/' . self::seg($id) . '/test', $payload);
    }

    /**
     * Tentativas recentes de entrega de um webhook. GET /webhooks/{id}/deliveries?limit=
     *
     * @return array<string,mixed>
     */
    public function webhookDeliveries(string $id, ?int $limit = null): array
    {
        $query = [];
        if ($limit !== null) {
            $query['limit'] = $limit;
        }
        return $this->get('/webhooks/' . self::seg($id) . '/deliveries', $query);
    }

    // ---------------------------------------------------------------------
    // Apps conectados (bZapper Connect — softwares parceiros usando esta conta)
    // ---------------------------------------------------------------------

    /**
     * Lista os apps parceiros conectados a esta conta (com nome/logo do parceiro).
     * GET /me/connections
     *
     * @return array<string,mixed> { data: list<array<string,mixed>> } — cada item é
     *                             uma PartnerConnection com partner_name/partner_logo_url.
     */
    public function listConnectedApps(): array
    {
        return $this->get('/me/connections');
    }

    /**
     * Desconecta um app parceiro (admin). A key do parceiro para de funcionar na
     * hora. Não cancela o plano da conta. DELETE /me/connections/{id}
     *
     * @return array<string,mixed> Vazio (a API responde 204).
     */
    public function revokeConnectedApp(string $id): array
    {
        return $this->delete('/me/connections/' . self::seg($id));
    }

    // =====================================================================
    // Operações adicionadas no padrão Berni r2. Convenção de todas:
    //  - parâmetros de caminho posicionais (vazio, "." ou ".." → InvalidArgumentException);
    //  - `$body` / `$params` = o JSON/query como vai no fio (só as chaves que você passar;
    //    em PATCH, chave ausente = não mexe, `null` = limpa quando a API aceita);
    //  - `$options` por chamada: `idempotency_key` (escritas) e `timeout` (segundos);
    //  - retorno: o JSON da API como array associativo, ou `null` quando não há corpo (204).
    // =====================================================================

    // ---------------------------------------------------------------------
    // Identidade, perfil e conta
    // ---------------------------------------------------------------------

    /**
     * Identidade autenticada (+ perfil quando é sessão de usuário). GET /me
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getMe(array $options = []): ?array
    {
        return $this->call('GET', '/me', [], null, $options);
    }

    /**
     * Atualiza o perfil do usuário. PATCH /me
     *
     * @param array{name?: string, phone?: string, job_title?: string, locale?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function updateProfile(array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/me', [], $body, $options);
    }

    /**
     * Renomeia a conta (nome da empresa) — admin. PATCH /account
     *
     * @param array{name: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function updateAccount(array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/account', [], $body, $options);
    }

    /**
     * Health check da API (sem autenticação obrigatória). GET /healthz
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null { status, version }
     */
    public function getHealth(array $options = []): ?array
    {
        return $this->call('GET', '/healthz', [], null, $options);
    }

    // ---------------------------------------------------------------------
    // Marca (logo) e projetos
    // ---------------------------------------------------------------------

    /**
     * Envia o logo da marca do projeto (multipart, campo `file`). POST /brand/logo
     *
     * @param string $content     Bytes do arquivo (ex.: file_get_contents($caminho)).
     * @param string $filename    Nome do arquivo (ex.: "logo.png").
     * @param string $contentType Tipo MIME (ex.: "image/png").
     * @param array<string,scalar> $fields Campos extras do formulário.
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function uploadBrandLogo(string $content, string $filename, string $contentType = 'application/octet-stream', array $fields = [], array $options = []): ?array
    {
        return $this->upload('/brand/logo', $content, $filename, $contentType, $fields, $options);
    }

    /**
     * Semáforo de status dos números por projeto. GET /projects/health
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getProjectsHealth(array $options = []): ?array
    {
        return $this->call('GET', '/projects/health', [], null, $options);
    }

    /**
     * Atualiza um projeto (admin). PATCH /projects/{id} — `api_mode` é imutável.
     *
     * @param array{name: string, logo_url?: string|null, color?: string|null} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function updateProject(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/projects/' . self::seg($id), [], $body, $options);
    }

    /**
     * Apaga um projeto (admin). DELETE /projects/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteProject(string $id, array $options = []): ?array
    {
        return $this->call('DELETE', '/projects/' . self::seg($id), [], null, $options);
    }

    /**
     * Identidade dos números de um projeto específico. GET /projects/{id}/brand
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getProjectBrand(string $id, array $options = []): ?array
    {
        return $this->call('GET', '/projects/' . self::seg($id) . '/brand', [], null, $options);
    }

    /**
     * Salva a identidade dos números de um projeto (admin). PUT /projects/{id}/brand
     *
     * @param BrandProfile $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function setProjectBrand(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PUT', '/projects/' . self::seg($id) . '/brand', [], $body, $options);
    }

    /**
     * Envia o logo de um projeto (multipart, PNG/JPEG/WebP até 5 MB) — admin.
     * POST /projects/{id}/logo
     *
     * @param array<string,scalar> $fields
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function uploadProjectLogo(string $id, string $content, string $filename, string $contentType = 'application/octet-stream', array $fields = [], array $options = []): ?array
    {
        return $this->upload('/projects/' . self::seg($id) . '/logo', $content, $filename, $contentType, $fields, $options);
    }

    // ---------------------------------------------------------------------
    // Contatos (CRM), tags, grupos de contato, supressões
    // ---------------------------------------------------------------------

    /**
     * Cria um contato (idempotente pelo telefone: se já existe, só completa campos
     * vazios). POST /contacts
     *
     * @param ContactInput $body `phone` obrigatório (+DDIdígitos).
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function createContact(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/contacts', [], $body, $options);
    }

    /**
     * Detalha um contato. GET /contacts/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getContact(string $id, array $options = []): ?array
    {
        return $this->call('GET', '/contacts/' . self::seg($id), [], null, $options);
    }

    /**
     * Atualização parcial dos campos de CRM do contato. PATCH /contacts/{id}
     *
     * @param ContactInput $body Chave ausente = não mexe; `null` = limpa.
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function updateContact(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/contacts/' . self::seg($id), [], $body, $options);
    }

    /**
     * Apaga um contato. DELETE /contacts/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteContact(string $id, array $options = []): ?array
    {
        return $this->call('DELETE', '/contacts/' . self::seg($id), [], null, $options);
    }

    /**
     * Linha do tempo do contato (mensagens + eventos). GET /contacts/{id}/history
     *
     * @param array{limit?: int} $params
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getContactHistory(string $id, array $params = [], array $options = []): ?array
    {
        return $this->call('GET', '/contacts/' . self::seg($id) . '/history', self::pick($params, ['limit']), null, $options);
    }

    /**
     * Adiciona uma nota interna ao contato. POST /contacts/{id}/notes
     *
     * @param array{body: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function addContactNote(string $id, array $body, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($id) . '/notes', [], $body, $options);
    }

    /**
     * Adiciona/remove tags do contato (chaves desconhecidas em `add` são criadas).
     * POST /contacts/{id}/tags
     *
     * @param array{add?: list<string>, remove?: list<string>} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function mutateContactTags(string $id, array $body, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($id) . '/tags', [], $body, $options);
    }

    /**
     * Adiciona/remove grupos de contato. POST /contacts/{id}/groups
     *
     * @param array{add?: list<string>, remove?: list<string>} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function mutateContactGroups(string $id, array $body, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($id) . '/groups', [], $body, $options);
    }

    /**
     * Registra o opt-out do contato (LGPD). POST /contacts/{id}/optout
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function optOutContact(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($id) . '/optout', [], null, $options);
    }

    /**
     * Suprime o contato manualmente. POST /contacts/{id}/suppress
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function suppressContact(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($id) . '/suppress', [], null, $options);
    }

    /**
     * Reativa o contato (remove a supressão/opt-out). POST /contacts/{id}/optin
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function optInContact(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($id) . '/optin', [], null, $options);
    }

    /**
     * Dicionário de tags. GET /tags
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listTags(array $options = []): ?array
    {
        return $this->call('GET', '/tags', [], null, $options);
    }

    /**
     * Cria uma tag. POST /tags
     *
     * @param array{key: string, name?: string, color?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function createTag(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/tags', [], $body, $options);
    }

    /**
     * Apaga uma tag. DELETE /tags/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteTag(string $id, array $options = []): ?array
    {
        return $this->call('DELETE', '/tags/' . self::seg($id), [], null, $options);
    }

    /**
     * Dicionário de grupos de contato. GET /contact-groups
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listContactGroups(array $options = []): ?array
    {
        return $this->call('GET', '/contact-groups', [], null, $options);
    }

    /**
     * Cria um grupo de contato. POST /contact-groups
     *
     * @param array{key: string, name?: string, color?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function createContactGroup(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/contact-groups', [], $body, $options);
    }

    /**
     * Apaga um grupo de contato. DELETE /contact-groups/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteContactGroup(string $id, array $options = []): ?array
    {
        return $this->call('DELETE', '/contact-groups/' . self::seg($id), [], null, $options);
    }

    /**
     * Lista de supressão. GET /suppressions
     *
     * @param array{limit?: int} $params
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listSuppressions(array $params = [], array $options = []): ?array
    {
        return $this->call('GET', '/suppressions', self::pick($params, ['limit']), null, $options);
    }

    /**
     * Adiciona um número à lista de supressão. POST /suppressions
     *
     * @param array{phone: string, reason?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function createSuppression(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/suppressions', [], $body, $options);
    }

    /**
     * Remove um número da lista de supressão. DELETE /suppressions?phone=
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteSuppression(string $phone, array $options = []): ?array
    {
        return $this->call('DELETE', '/suppressions', ['phone' => $phone], null, $options);
    }

    // ---------------------------------------------------------------------
    // Cobrança: plano, add-ons, faturas
    // ---------------------------------------------------------------------

    /**
     * Limites efetivos da conta (plano + add-ons + uso). GET /me/entitlements
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getMyEntitlements(array $options = []): ?array
    {
        return $this->call('GET', '/me/entitlements', [], null, $options);
    }

    /**
     * Coloca o Pro no carrinho (pendente até pagar). POST /me/plan/upgrade
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function upgradePlan(array $options = []): ?array
    {
        return $this->call('POST', '/me/plan/upgrade', [], null, $options);
    }

    /**
     * Cancela o Pro no fim do ciclo atual. POST /me/plan/cancel
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function cancelPlan(array $options = []): ?array
    {
        return $this->call('POST', '/me/plan/cancel', [], null, $options);
    }

    /**
     * Desfaz um cancelamento agendado do Pro. POST /me/plan/uncancel
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function uncancelPlan(array $options = []): ?array
    {
        return $this->call('POST', '/me/plan/uncancel', [], null, $options);
    }

    /**
     * Estado do plano/assinatura (null no Free). GET /me/subscription
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getMySubscription(array $options = []): ?array
    {
        return $this->call('GET', '/me/subscription', [], null, $options);
    }

    /**
     * Soma (+) ou tira (−) add-ons do carrinho (exige Pro — 409 `not_pro`). POST /me/addons
     *
     * @param array{kind: 'number'|'project'|'storage_gb'|'retention_block'|'campaigns'|'schedule_year', delta: int} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function changeAddon(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/me/addons', [], $body, $options);
    }

    /**
     * Estado do carrinho (Pro/add-ons pendentes + total proporcional). GET /me/addons/cart
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getAddonCart(array $options = []): ?array
    {
        return $this->call('GET', '/me/addons/cart', [], null, $options);
    }

    /**
     * Esvazia o carrinho. DELETE /me/addons/cart
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function clearAddonCart(array $options = []): ?array
    {
        return $this->call('DELETE', '/me/addons/cart', [], null, $options);
    }

    /**
     * Paga o carrinho: cria a fatura e devolve o `client_secret` do Stripe.
     * POST /me/addons/cart/checkout
     *
     * @param array{save_card?: bool} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function checkoutAddonCart(array $body = [], array $options = []): ?array
    {
        return $this->call('POST', '/me/addons/cart/checkout', [], $body, $options);
    }

    /**
     * Faturas da conta (últimas 24). GET /me/invoices
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listMyInvoices(array $options = []): ?array
    {
        return $this->call('GET', '/me/invoices', [], null, $options);
    }

    /**
     * Reabre o pagamento de uma fatura em aberto. POST /me/invoices/{id}/pay
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null { client_secret }
     */
    public function payInvoice(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/me/invoices/' . self::seg($id) . '/pay', [], null, $options);
    }

    /**
     * Chave publicável do Stripe para o checkout no front. GET /billing/config
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getBillingConfig(array $options = []): ?array
    {
        return $this->call('GET', '/billing/config', [], null, $options);
    }

    /**
     * Tabela de preços pública por moeda. GET /pricing
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getPricing(array $options = []): ?array
    {
        return $this->call('GET', '/pricing', [], null, $options);
    }

    // ---------------------------------------------------------------------
    // Avançado: editar/apagar/encaminhar, lido, privacidade, chats, etiquetas,
    // bloqueio e chamadas
    // ---------------------------------------------------------------------

    /**
     * Edita o texto de uma mensagem enviada. PATCH /messages/{id}
     *
     * @param array{text: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function editMessage(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/messages/' . self::seg($id), [], $body, $options);
    }

    /**
     * Apaga uma mensagem (para todos). DELETE /messages/{id}?for_everyone=
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function revokeMessage(string $id, ?bool $forEveryone = null, array $options = []): ?array
    {
        return $this->call('DELETE', '/messages/' . self::seg($id), ['for_everyone' => $forEveryone], null, $options);
    }

    /**
     * Encaminha uma mensagem (experimental). POST /messages/forward
     *
     * @param array{instance_id: string, to: string, from_chat: string, wa_message_id: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function forwardMessage(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/messages/forward', [], $body, $options);
    }

    /**
     * Marca mensagens como lidas. POST /messages/{id}/read
     *
     * @param array{instance_id: string, chat: string, wa_message_ids?: list<string>, sender?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function markRead(string $id, array $body, array $options = []): ?array
    {
        return $this->call('POST', '/messages/' . self::seg($id) . '/read', [], $body, $options);
    }

    /**
     * Ajusta uma configuração de privacidade do número. PATCH /instances/{id}/privacy
     *
     * @param array{setting: string, value: string} $body ex.: setting "last", value "contacts".
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function setPrivacy(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/instances/' . self::seg($id) . '/privacy', [], $body, $options);
    }

    /**
     * Silencia/dessilencia um chat. POST /chats/{jid}/mute body { instance_id, on }
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function muteChat(string $instanceId, string $jid, bool $on = true, array $options = []): ?array
    {
        return $this->call('POST', '/chats/' . self::seg($jid, 'jid') . '/mute', [], ['instance_id' => $instanceId, 'on' => $on], $options);
    }

    /**
     * Aplica/remove uma etiqueta num chat (experimental).
     * POST /chats/{jid}/labels body { instance_id, label_id, apply }
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function applyChatLabel(string $instanceId, string $jid, string $labelId, bool $apply = true, array $options = []): ?array
    {
        return $this->call(
            'POST',
            '/chats/' . self::seg($jid, 'jid') . '/labels',
            [],
            ['instance_id' => $instanceId, 'label_id' => $labelId, 'apply' => $apply],
            $options
        );
    }

    /**
     * Etiquetas do número (experimental). GET /labels?instance_id=
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listLabels(string $instanceId, array $options = []): ?array
    {
        return $this->call('GET', '/labels', ['instance_id' => $instanceId], null, $options);
    }

    /**
     * Cria uma etiqueta (experimental). POST /labels
     *
     * @param array{instance_id: string, name: string, color?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function createLabel(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/labels', [], $body, $options);
    }

    /**
     * Apaga uma etiqueta (experimental). DELETE /labels/{id}?instance_id=
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteLabel(string $instanceId, string $id, array $options = []): ?array
    {
        return $this->call('DELETE', '/labels/' . self::seg($id), ['instance_id' => $instanceId], null, $options);
    }

    /**
     * Bloqueia um contato. POST /contacts/{jid}/block body { instance_id }
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function blockContact(string $instanceId, string $jid, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($jid, 'jid') . '/block', [], ['instance_id' => $instanceId], $options);
    }

    /**
     * Desbloqueia um contato. POST /contacts/{jid}/unblock body { instance_id }
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function unblockContact(string $instanceId, string $jid, array $options = []): ?array
    {
        return $this->call('POST', '/contacts/' . self::seg($jid, 'jid') . '/unblock', [], ['instance_id' => $instanceId], $options);
    }

    /**
     * Contatos bloqueados. GET /blocklist?instance_id=
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getBlocklist(string $instanceId, array $options = []): ?array
    {
        return $this->call('GET', '/blocklist', ['instance_id' => $instanceId], null, $options);
    }

    /**
     * Rejeita uma chamada. POST /calls/reject
     *
     * @param array{instance_id: string, call_from: string, call_id: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function rejectCall(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/calls/reject', [], $body, $options);
    }

    /**
     * Inicia uma chamada (experimental). POST /calls/offer
     *
     * @param array{instance_id: string, to: string, video?: bool} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function offerCall(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/calls/offer', [], $body, $options);
    }

    // ---------------------------------------------------------------------
    // Números (instâncias): ciclo de vida, proxy, filtros de entrada, API oficial
    // ---------------------------------------------------------------------

    /**
     * Remove uma instância (encerra a sessão). DELETE /instances/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function deleteInstance(string $id, array $options = []): ?array
    {
        return $this->call('DELETE', '/instances/' . self::seg($id), [], null, $options);
    }

    /**
     * Logout (exige novo QR depois). POST /instances/{id}/logout
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function logoutInstance(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/instances/' . self::seg($id) . '/logout', [], null, $options);
    }

    /**
     * Arquiva (desativa) um número mantendo o histórico. POST /instances/{id}/archive
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function archiveInstance(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/instances/' . self::seg($id) . '/archive', [], null, $options);
    }

    /**
     * Reativa um número arquivado (volta desconectado). POST /instances/{id}/unarchive
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function unarchiveInstance(string $id, array $options = []): ?array
    {
        return $this->call('POST', '/instances/' . self::seg($id) . '/unarchive', [], null, $options);
    }

    /**
     * Define o proxy da instância (isolamento de rede/IP). PATCH /instances/{id}/proxy
     *
     * @param array{proxy_url: string} $body `""` remove o proxy.
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function setInstanceProxy(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/instances/' . self::seg($id) . '/proxy', [], $body, $options);
    }

    /**
     * Filtros de entrada (broadcast/status/grupos). PATCH /instances/{id}/inbound-filters
     *
     * @param array{ignore_broadcast?: bool, ignore_status?: bool, ignore_groups?: bool, group_allowlist?: list<string>, group_denylist?: list<string>} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function setInboundFilters(string $id, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/instances/' . self::seg($id) . '/inbound-filters', [], $body, $options);
    }

    /**
     * Conta WhatsApp Business (Cloud API) conectada ao projeto. GET /official/account
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getOfficialAccount(array $options = []): ?array
    {
        return $this->call('GET', '/official/account', [], null, $options);
    }

    /**
     * Conecta uma conta WhatsApp Business ao projeto (credenciais manuais; o token é
     * guardado cifrado e nunca devolvido). POST /official/account
     *
     * @param array{waba_id: string, phone_number_id: string, access_token: string, display_number?: string, verified_name?: string, status?: 'PENDENTE'|'AGUARDANDO_PAGAMENTO'|'ATIVA'} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function connectOfficialAccount(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/official/account', [], $body, $options);
    }

    /**
     * Desconecta a conta WhatsApp Business do projeto. DELETE /official/account
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function disconnectOfficialAccount(array $options = []): ?array
    {
        return $this->call('DELETE', '/official/account', [], null, $options);
    }

    // ---------------------------------------------------------------------
    // Grupos: configurações e pedidos de entrada
    // ---------------------------------------------------------------------

    /**
     * Atualiza nome/descrição/configurações do grupo. PATCH /groups/{jid}?instance_id=
     *
     * @param array{name?: string, topic?: string, announce?: bool, locked?: bool} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function updateGroup(string $instanceId, string $jid, array $body, array $options = []): ?array
    {
        return $this->call('PATCH', '/groups/' . self::seg($jid, 'jid'), ['instance_id' => $instanceId], $body, $options);
    }

    /**
     * Pedidos de entrada pendentes. GET /groups/{jid}/join-requests?instance_id=
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listJoinRequests(string $instanceId, string $jid, array $options = []): ?array
    {
        return $this->call('GET', '/groups/' . self::seg($jid, 'jid') . '/join-requests', ['instance_id' => $instanceId], null, $options);
    }

    /**
     * Aprova/rejeita pedidos de entrada. POST /groups/{jid}/join-requests?instance_id=
     *
     * @param array{participants: list<string>, approve: bool} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function updateJoinRequests(string $instanceId, string $jid, array $body, array $options = []): ?array
    {
        return $this->call('POST', '/groups/' . self::seg($jid, 'jid') . '/join-requests', ['instance_id' => $instanceId], $body, $options);
    }

    // ---------------------------------------------------------------------
    // Campanhas: elegibilidade e mídia
    // ---------------------------------------------------------------------

    /**
     * Elegibilidade de cada número para campanha (conexão + aquecimento).
     * GET /campaigns/eligibility?pool_id=
     *
     * @param array{pool_id?: string} $params
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getCampaignEligibility(array $params = [], array $options = []): ?array
    {
        return $this->call('GET', '/campaigns/eligibility', self::pick($params, ['pool_id']), null, $options);
    }

    /**
     * Envia a imagem de cabeçalho da campanha (multipart, campo `file`). POST /campaigns/media
     *
     * @param array<string,scalar> $fields
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function uploadCampaignMedia(string $content, string $filename, string $contentType = 'application/octet-stream', array $fields = [], array $options = []): ?array
    {
        return $this->upload('/campaigns/media', $content, $filename, $contentType, $fields, $options);
    }

    // ---------------------------------------------------------------------
    // Webhooks: evento de teste no projeto
    // ---------------------------------------------------------------------

    /**
     * Emite um evento de exemplo no stream e nos webhooks do projeto (como o
     * `stripe trigger`). POST /webhooks/trigger
     *
     * @param array{event_type?: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function triggerWebhookEvent(array $body = [], array $options = []): ?array
    {
        return $this->call('POST', '/webhooks/trigger', [], $body, $options);
    }

    // ---------------------------------------------------------------------
    // Pools (grupos de números para rotação)
    // ---------------------------------------------------------------------

    /**
     * Pools do tenant. GET /pools
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function listPools(array $options = []): ?array
    {
        return $this->call('GET', '/pools', [], null, $options);
    }

    /**
     * Cria um pool de números. POST /pools
     *
     * @param array{name?: string, strategy?: 'round_robin'|'least_used'|'health_weighted', is_default?: bool} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function createPool(array $body, array $options = []): ?array
    {
        return $this->call('POST', '/pools', [], $body, $options);
    }

    /**
     * Detalha um pool (com membros). GET /pools/{id}
     *
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function getPool(string $id, array $options = []): ?array
    {
        return $this->call('GET', '/pools/' . self::seg($id), [], null, $options);
    }

    /**
     * Adiciona um número ao pool. POST /pools/{id}/numbers
     *
     * @param array{instance_id: string} $body
     * @param RequestOptions $options
     * @return array<string,mixed>|null
     */
    public function addPoolNumber(string $id, array $body, array $options = []): ?array
    {
        return $this->call('POST', '/pools/' . self::seg($id) . '/numbers', [], $body, $options);
    }

    // ---------------------------------------------------------------------
    // Internos
    // ---------------------------------------------------------------------

    /**
     * Upload multipart (campo `file`) com as mesmas garantias de idempotência/retry.
     *
     * @param array<string,scalar> $fields
     * @param array{idempotency_key?: string, timeout?: int|float} $options
     * @return array<string,mixed>|null
     */
    private function upload(string $path, string $content, string $filename, string $contentType, array $fields, array $options): ?array
    {
        if ($filename === '') {
            throw new \InvalidArgumentException('filename não pode ser vazio.');
        }
        return $this->call('POST', $path, [], null, $options, [
            'fields' => $fields,
            'file' => ['name' => 'file', 'filename' => $filename, 'content' => $content, 'content_type' => $contentType],
        ]);
    }

    /**
     * Copia de `$src` só as chaves listadas que foram informadas.
     *
     * @param array<string,mixed> $src
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function pick(array $src, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $src)) {
                $out[$k] = $src[$k];
            }
        }
        return $out;
    }

    /**
     * POST de envio: `$opts['idempotency_key']` vira o header `Idempotency-Key`.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function send(string $path, array $payload, array $opts): array
    {
        $options = [];
        if (isset($opts['idempotency_key']) && $opts['idempotency_key'] !== '') {
            $options['idempotency_key'] = (string) $opts['idempotency_key'];
        }
        return $this->post($path, $payload, [], $options);
    }

    /**
     * Monta o corpo base comum a todos os envios (SendBase).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function base(string $to, array $opts): array
    {
        $payload = ['to' => $to];
        foreach (['instance_id', 'pool_id', 'quoted_message_id', 'quoted_participant', 'client_reference', 'scheduled_at'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        foreach (['mentions', 'tags', 'groups'] as $k) {
            if (isset($opts[$k]) && is_array($opts[$k])) {
                $payload[$k] = array_values($opts[$k]);
            }
        }
        // force: envia mesmo a contato suprimido/opt-out (use com critério).
        if (isset($opts['force'])) {
            $payload['force'] = (bool) $opts['force'];
        }
        // Afinidade de conversa: sem instance_id/pool_id, reusa o número que já
        // fala com `to`. Padrão true; envie false para forçar rotação.
        if (isset($opts['sticky'])) {
            $payload['sticky'] = (bool) $opts['sticky'];
        }
        return $payload;
    }
}
