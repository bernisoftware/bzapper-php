<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Cliente oficial do bZapper — gateway de WhatsApp multi-tenant.
 *
 * ```php
 * $bz = new \Bzapper\Client('https://api.bzapper.com.br', 'bz_live_...');
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
 *     client_reference?: string,
 *     mentions?: list<string>,
 *     sticky?: bool
 * }
 */
final class Client
{
    private string $baseUrl;
    private string $apiKey;
    private ?string $locale;
    private int $timeout;

    /** Versão do SDK (usada no User-Agent). */
    public const VERSION = '0.2.0';

    /**
     * @param string $baseUrl URL base da API (ex.: "http://localhost:8080" em dev).
     * @param string $apiKey  API key do tenant (ex.: "bz_live_...").
     * @param array{locale?: string, timeout?: int} $opts
     *                        locale: BCP-47 enviado em Accept-Language (ex.: "pt-BR").
     *                        timeout: timeout total da requisição em segundos (default 30).
     */
    public function __construct(string $baseUrl, string $apiKey, array $opts = [])
    {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('baseUrl não pode ser vazio.');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey não pode ser vazio.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->locale = isset($opts['locale']) ? (string) $opts['locale'] : null;
        $this->timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 30;
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
        return $this->post('/messages/text', $this->base($to, $opts) + ['body' => $body]);
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
        return $this->post('/messages/otp', $payload);
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
        return $this->post('/messages/image', $this->base($to, $opts) + ['media' => $media]);
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
        return $this->post('/messages/video', $this->base($to, $opts) + ['media' => $media]);
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
        return $this->post('/messages/document', $this->base($to, $opts) + ['media' => $media]);
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
        return $this->post('/messages/audio', $this->base($to, $opts) + ['media' => $media]);
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
        return $this->post('/messages/sticker', $this->base($to, $opts) + ['media' => $media]);
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
        return $this->post('/messages/location', $payload);
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
        return $this->post('/messages/contact', $payload);
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
        return $this->post('/messages/poll', $payload);
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
        return $this->post('/messages/reaction', $payload);
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
        return $this->post('/messages/buttons', $payload);
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
        return $this->post('/messages/list', $payload);
    }

    // ---------------------------------------------------------------------
    // Instâncias (números)
    // ---------------------------------------------------------------------

    /**
     * Lista instâncias (números) do tenant. GET /instances
     *
     * @return array<string,mixed>
     */
    public function listInstances(): array
    {
        return $this->get('/instances');
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
        return $this->get('/instances/' . rawurlencode($id));
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
            '/instances/' . rawurlencode($id) . '/connect',
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
        return $this->post('/instances/' . rawurlencode($id) . '/disconnect');
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
        return $this->delete('/keys/' . rawurlencode($id));
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
        return $this->get('/conversations/' . rawurlencode($jid) . '/messages', $query);
    }

    /**
     * Arquiva/desarquiva um chat. POST /chats/{jid}/archive body { instance_id, on }
     *
     * @return array<string,mixed>
     */
    public function archiveChat(string $instanceId, string $jid, bool $on = true): array
    {
        return $this->post(
            '/chats/' . rawurlencode($jid) . '/archive',
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
            '/chats/' . rawurlencode($jid) . '/pin',
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
            '/chats/' . rawurlencode($jid) . '/read',
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
        return $this->get('/groups/' . rawurlencode($jid), ['instance_id' => $instanceId]);
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
            '/groups/' . rawurlencode($jid) . '/participants',
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
            '/groups/' . rawurlencode($jid) . '/leave',
            null,
            ['instance_id' => $instanceId]
        );
    }

    /**
     * Obtém o link de convite do grupo. GET /groups/{jid}/invite?instance_id=
     *
     * @return array<string,mixed>
     */
    public function groupInvite(string $instanceId, string $jid): array
    {
        return $this->get('/groups/' . rawurlencode($jid) . '/invite', ['instance_id' => $instanceId]);
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
        return $this->patch('/instances/' . rawurlencode($id) . '/profile', $payload);
    }

    // ---------------------------------------------------------------------
    // Contatos (base capturada das conversas — compartilhada na conta)
    // ---------------------------------------------------------------------

    /**
     * Lista a base de contatos da conta (filtro opcional por projeto). GET /contacts
     *
     * @param array{search?: string, project_id?: string, limit?: int} $params
     *                        project_id: id do projeto ou "current" (o da sua key).
     * @return array<string,mixed>
     */
    public function listContacts(array $params = []): array
    {
        $query = [];
        foreach (['search', 'project_id'] as $k) {
            if (isset($params[$k])) {
                $query[$k] = $params[$k];
            }
        }
        if (isset($params['limit'])) {
            $query['limit'] = (int) $params['limit'];
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
     * @return array<string,mixed>
     */
    public function createProject(string $name): array
    {
        return $this->post('/projects', ['name' => $name]);
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
        return $this->patch('/users/' . rawurlencode($id), ['role' => $role]);
    }

    /**
     * Remove um usuário da conta (admin). DELETE /users/{id}
     *
     * @return array<string,mixed>
     */
    public function removeUser(string $id): array
    {
        return $this->delete('/users/' . rawurlencode($id));
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
    // Internos
    // ---------------------------------------------------------------------

    /**
     * Monta o corpo base comum a todos os envios (SendBase).
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    private function base(string $to, array $opts): array
    {
        $payload = ['to' => $to];
        foreach (['instance_id', 'pool_id', 'quoted_message_id', 'client_reference'] as $k) {
            if (isset($opts[$k])) {
                $payload[$k] = $opts[$k];
            }
        }
        if (isset($opts['mentions']) && is_array($opts['mentions'])) {
            $payload['mentions'] = array_values($opts['mentions']);
        }
        // Afinidade de conversa: sem instance_id/pool_id, reusa o número que já
        // fala com `to`. Padrão true; envie false para forçar rotação.
        if (isset($opts['sticky'])) {
            $payload['sticky'] = (bool) $opts['sticky'];
        }
        return $payload;
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function post(string $path, ?array $body = null, array $query = []): array
    {
        return $this->request('POST', $path, $body, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function put(string $path, ?array $body = null, array $query = []): array
    {
        return $this->request('PUT', $path, $body, $query);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function patch(string $path, ?array $body = null, array $query = []): array
    {
        return $this->request('PATCH', $path, $body, $query);
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function delete(string $path, array $query = []): array
    {
        return $this->request('DELETE', $path, null, $query);
    }

    /**
     * Executa uma requisição HTTP e devolve o corpo JSON decodificado.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     *
     * @throws BzapperException Em qualquer resposta não-2xx ou falha de transporte.
     */
    private function request(string $method, string $path, ?array $body, array $query): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: bzapper-php/' . self::VERSION,
        ];
        if ($this->locale !== null && $this->locale !== '') {
            $headers[] = 'Accept-Language: ' . $this->locale;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new BzapperException('network_error', 'Falha ao inicializar cURL.', 0);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new BzapperException(
                    'invalid_request',
                    'Falha ao serializar o corpo da requisição: ' . json_last_error_msg(),
                    0
                );
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new BzapperException(
                'network_error',
                'Falha de transporte: ' . ($err !== '' ? $err : 'erro cURL ' . $errno),
                0
            );
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        /** @var string $raw */
        $rawBody = $raw;

        // Corpos vazios (ex.: 204) → array vazio.
        $decoded = [];
        if ($rawBody !== '') {
            $parsed = json_decode($rawBody, true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            } elseif (json_last_error() !== JSON_ERROR_NONE && $status >= 200 && $status < 300) {
                throw new BzapperException(
                    'invalid_response',
                    'Resposta não-JSON da API: ' . json_last_error_msg(),
                    $status,
                    null,
                    $rawBody
                );
            }
        }

        if ($status < 200 || $status >= 300) {
            $code = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'http_error';
            $message = is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : ('Requisição falhou com status HTTP ' . $status . '.');
            $locale = is_string($decoded['locale'] ?? null) ? $decoded['locale'] : null;
            throw new BzapperException($code, $message, $status, $locale, $rawBody);
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}
