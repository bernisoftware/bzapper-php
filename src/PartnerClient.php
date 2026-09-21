<?php

declare(strict_types=1);

namespace Bzapper;

/**
 * Cliente de PARCEIRO do bZapper Connect — para o BACKEND do seu software.
 *
 * Com o bZapper Connect, seus clientes assinam o bZapper Pro e conectam o WhatsApp
 * sem sair do seu produto (componente embutido). O fluxo:
 *
 *  1. seu backend chama {@see createConnectSession()} e entrega o `session_token` ao front;
 *  2. o front abre o componente com o token; ao concluir, ele emite um `code` de uso único;
 *  3. seu backend troca o `code` pela API key do cliente com {@see exchangeCode()};
 *  4. com essa key (`bz_live_...`) você usa o {@see Client} normal em nome do cliente.
 *
 * ```php
 * $partner = new \Bzapper\PartnerClient('bz_partner_...'); // aponta para produção
 * $session = $partner->createConnectSession('cliente-42', [
 *     'name'  => 'Ana Souza',
 *     'email' => 'ana@exemplo.com',
 * ]);
 * echo $session['session_token'];
 * ```
 *
 * O secret de parceiro (`bz_partner_...`) é enviado como `Authorization: Bearer` e
 * NUNCA deve chegar a um navegador.
 *
 * Status de conexão: pending_account, pending_payment, pending_number, active,
 * suspended (Pro do cliente sem pagamento — a key responde 402 `connect_suspended`
 * e volta sozinha quando pago) e revoked (encerrada — a key responde 401
 * `connect_revoked`).
 *
 * @phpstan-type ConnectCustomer array{
 *     name?: string,
 *     email: string,
 *     phone?: string,
 *     company?: string,
 *     country?: string,
 *     locale?: string
 * }
 */
final class PartnerClient
{
    use HttpTransport;

    /** Status: conta do cliente ainda não criada. */
    public const STATUS_PENDING_ACCOUNT = 'pending_account';

    /** Status: aguardando o pagamento do Pro. */
    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    /** Status: aguardando conectar o número de WhatsApp. */
    public const STATUS_PENDING_NUMBER = 'pending_number';

    /** Status: conexão ativa — a key funciona. */
    public const STATUS_ACTIVE = 'active';

    /** Status: Pro sem pagamento — a key responde 402 `connect_suspended`. */
    public const STATUS_SUSPENDED = 'suspended';

    /** Status: conexão encerrada — a key responde 401 `connect_revoked`. */
    public const STATUS_REVOKED = 'revoked';

    /** Código de erro (402) quando a conexão está suspensa por falta de pagamento. */
    public const ERROR_CONNECT_SUSPENDED = 'connect_suspended';

    /** Código de erro (401) quando a conexão foi revogada. */
    public const ERROR_CONNECT_REVOKED = 'connect_revoked';

    /**
     * @param string      $partnerSecret Secret do parceiro (ex.: "bz_partner_..."). Único obrigatório.
     * @param string|null $baseUrl       URL base da API. Opcional — default produção
     *                                   (https://api.bzapper.com.br); informe só em dev
     *                                   ("http://localhost:8080") ou self-host.
     * @param array{locale?: string, timeout?: int} $opts
     *                        locale: BCP-47 enviado em Accept-Language (ex.: "pt-BR").
     *                        timeout: timeout total da requisição em segundos (default 30).
     */
    public function __construct(string $partnerSecret, ?string $baseUrl = null, array $opts = [])
    {
        if ($partnerSecret === '') {
            throw new \InvalidArgumentException('partnerSecret não pode ser vazio.');
        }
        $this->initTransport($partnerSecret, $baseUrl, $opts);
    }

    /**
     * Identidade do parceiro dono do secret. GET /partner/me
     *
     * @return array<string,mixed> { id, slug, name, logo_url, allowed_origins, webhook_url, key_scopes }
     */
    public function me(): array
    {
        return $this->get('/partner/me');
    }

    /**
     * Abre uma sessão do Connect para um cliente seu. POST /partner/connect-sessions
     *
     * Cria (ou reusa) a conexão do cliente — mesmo `external_id` = mesma conexão — e
     * devolve um `session_token` de vida curta (30 min) para abrir o componente
     * embutido. Os dados do cliente são confiáveis (você já o autenticou): a conta é
     * criada sem senha, captcha ou confirmação de e-mail — exceto se o e-mail já tiver
     * conta no bZapper, quando o componente pede um código enviado a esse e-mail.
     *
     * @param string          $externalId Id do cliente no SEU sistema (máx. 200).
     * @param ConnectCustomer $customer   email obrigatório; name OU company obrigatório.
     *                                    phone em E.164; country ISO-3166 alpha-2 (define a moeda).
     * @param string|null     $locale     BCP-47 do componente (ex.: "pt-BR").
     * @return array<string,mixed> { session_token, expires_at, connection }
     */
    public function createConnectSession(string $externalId, array $customer, ?string $locale = null): array
    {
        $cust = [];
        foreach (['name', 'email', 'phone', 'company', 'country', 'locale'] as $k) {
            if (isset($customer[$k])) {
                $cust[$k] = $customer[$k];
            }
        }
        $payload = ['external_id' => $externalId, 'customer' => $cust];
        if ($locale !== null) {
            $payload['locale'] = $locale;
        }
        return $this->post('/partner/connect-sessions', $payload);
    }

    /**
     * Troca o `code` de conclusão (emitido pelo componente, válido por 10 min, uso
     * único) pela API key do cliente. POST /partner/connect/exchange
     *
     * A resposta traz a key CRUA (`api_key`, `bz_live_...`) — guarde-a já; não é
     * mostrada de novo (use {@see rotateConnectionKey()} se perder).
     *
     * @return array<string,mixed> PartnerConnection + api_key.
     */
    public function exchangeCode(string $code): array
    {
        return $this->post('/partner/connect/exchange', ['code' => $code]);
    }

    /**
     * Lista suas conexões. GET /partner/connections?external_id=&status=
     *
     * @param string|null $externalId Filtra pelo id do cliente no seu sistema.
     * @param string|null $status     Filtra pelo status (ver constantes STATUS_*).
     * @return array<string,mixed> { data: list<array<string,mixed>> }
     */
    public function listConnections(?string $externalId = null, ?string $status = null): array
    {
        $query = [];
        if ($externalId !== null) {
            $query['external_id'] = $externalId;
        }
        if ($status !== null) {
            $query['status'] = $status;
        }
        return $this->get('/partner/connections', $query);
    }

    /**
     * Detalha uma conexão (status, conta, números). GET /partner/connections/{id}
     *
     * @return array<string,mixed>
     */
    public function getConnection(string $id): array
    {
        return $this->get('/partner/connections/' . rawurlencode($id));
    }

    /**
     * Emite uma nova API key para uma conexão concluída; a anterior para de funcionar.
     * POST /partner/connections/{id}/rotate-key
     *
     * Lança BzapperException 409 `connection_not_active` se a conexão ainda não foi
     * concluída ou foi revogada.
     *
     * @return array<string,mixed> PartnerConnection + api_key (crua, mostrada uma vez).
     */
    public function rotateConnectionKey(string $id): array
    {
        return $this->post('/partner/connections/' . rawurlencode($id) . '/rotate-key');
    }

    /**
     * Encerra uma conexão: revoga a key (NÃO cancela o plano do cliente) e dispara o
     * webhook `connect.revoked`. DELETE /partner/connections/{id}
     *
     * @return array<string,mixed> Vazio (a API responde 204).
     */
    public function revokeConnection(string $id): array
    {
        return $this->delete('/partner/connections/' . rawurlencode($id));
    }
}
