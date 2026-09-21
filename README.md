# bZapper PHP SDK

SDK oficial do **bZapper** em PHP — gateway de WhatsApp multi-tenant: envie
mensagens, gerencie números (instâncias), gere API keys e acompanhe o uso, via
uma API HTTP REST.

- Sem dependências externas (usa cURL e JSON nativos).
- PHP **8.1+**, tipado, `declare(strict_types=1)`.
- Autoload **PSR-4** (`Bzapper\`) — publicável no Packagist.

## Instalação

```bash
composer require bzapper/bzapper
```

## Hello world

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bzapper\Client;

$bz = new Client('bz_live_...');

$msg = $bz->sendText('+5511999999999', 'Olá do bZapper!');
echo $msg['message_id'];
```

O `baseUrl` tem default de produção (`https://api.bzapper.com.br`) e é **opcional** —
informe apenas em dev/self-host: `new Client('bz_live_...', 'http://localhost:8080')`. A API
key (`bz_live_...`) é gerada no painel ou via `createKey()`.

### Opções do construtor

```php
$bz = new Client('bz_live_...', null, [
    'locale'  => 'pt-BR', // enviado em Accept-Language
    'timeout' => 30,      // segundos (default 30)
]);
```

Toda requisição envia: `Authorization: Bearer <apiKey>`,
`Content-Type: application/json` e, se `locale` informado, `Accept-Language`.

Os métodos retornam o corpo JSON da API como **array associativo**.

## Campos comuns de envio (SendBase)

Todos os `send*` aceitam um último argumento `$opts` (array) com os campos
comuns: `instance_id`, `pool_id`, `quoted_message_id`, `quoted_participant`
(autor da mensagem citada — só em grupo, quando ela não está no histórico),
`client_reference` e `mentions` (array de JIDs ou telefones). Omita
`instance_id`/`pool_id` para deixar a rotação escolher o número.

`idempotency_key` (até 255 caracteres) vai no header `Idempotency-Key`, não no
corpo: repetir o envio com a mesma chave em 24h devolve a MESMA resposta sem
reenviar (409 `idempotency_in_progress` se a 1ª ainda roda; 422
`idempotency_key_reused` se o corpo mudou) — retry seguro após timeout.

```php
$bz->sendText('+5511999999999', 'Resposta', [
    'instance_id'       => 'uuid-do-numero',
    'quoted_message_id' => 'wa-id-citado',
    'client_reference'  => 'pedido-123',
    'mentions'          => ['5511888887777@s.whatsapp.net'],
    'idempotency_key'   => 'pedido-123-resposta',
]);
```

## Exemplos de cada tipo de mensagem

```php
// Texto
$bz->sendText('+5511999999999', 'Olá!');

// Imagem (url OU base64 — nunca os dois)
$bz->sendImage('+5511999999999', ['url' => 'https://exemplo.com/foto.jpg', 'caption' => 'Legenda']);

// Vídeo
$bz->sendVideo('+5511999999999', ['url' => 'https://exemplo.com/video.mp4']);

// Documento
$bz->sendDocument('+5511999999999', ['url' => 'https://exemplo.com/doc.pdf', 'filename' => 'doc.pdf']);

// Áudio comum
$bz->sendAudio('+5511999999999', ['url' => 'https://exemplo.com/audio.ogg']);

// Áudio como nota de voz (ptt = true)
$bz->sendAudio('+5511999999999', ['url' => 'https://exemplo.com/audio.ogg', 'ptt' => true]);

// Sticker
$bz->sendSticker('+5511999999999', ['url' => 'https://exemplo.com/sticker.webp']);

// Mídia por base64
$bz->sendImage('+5511999999999', ['base64' => base64_encode(file_get_contents('foto.jpg')), 'mimetype' => 'image/jpeg']);

// Localização
$bz->sendLocation('+5511999999999', -23.5613, -46.6565, ['name' => 'Av. Paulista', 'address' => 'São Paulo, SP']);

// Contato (vCard)
$bz->sendContact('+5511999999999', ['contact_name' => 'Suporte', 'contact_vcard' => "BEGIN:VCARD\n..."]);

// Enquete
$bz->sendPoll('+5511999999999', 'Qual seu plano?', ['Free', 'Pro', 'Enterprise'], 1);

// Reação
$bz->sendReaction('+5511999999999', 'wa-message-id', '👍');

// Botões
$bz->sendButtons('+5511999999999', 'Confirma?', [
    ['id' => 'yes', 'title' => 'Sim'],
    ['id' => 'no',  'title' => 'Não'],
], ['footer' => 'Rodapé opcional']);

// Lista
$bz->sendList('+5511999999999', 'Cardápio:', [
    ['title' => 'Bebidas', 'rows' => [
        ['id' => 'coke', 'title' => 'Refrigerante', 'description' => 'Lata 350ml'],
        ['id' => 'water', 'title' => 'Água'],
    ]],
], ['button_text' => 'Ver opções', 'footer' => 'Entrega rápida']);
```

> **Caveat (pétreo):** botões e listas **não são confiáveis** no WhatsApp
> (pior em grupos). A API **sempre** envia também um **menu de texto numerado**
> equivalente como fallback. Não dependa de payloads interativos.

## Instâncias (números)

```php
$bz->listInstances();
$bz->createInstance('+5511999999999', ['nickname' => 'Atendimento', 'proxy_url' => 'http://user:pass@proxy:8080']);
$bz->getInstance('uuid');

// Conectar: method = 'qr' (padrão) ou 'code'
$res = $bz->connectInstance('uuid', 'qr');
echo $res['qr_code'] ?? $res['pair_code'] ?? '';

$bz->disconnectInstance('uuid');
```

## Grupos, presença e conversas

Operações avançadas. Nelas o `instance_id` é **explícito** (primeiro argumento):
vai na **query** na maioria, no **body** onde a API exige; o `jid` é parte do path.

```php
$inst = 'uuid-do-numero';
$grupo = '12036316XXXXXXXXX@g.us';

// Presença — FUNCIONA EM GRUPOS! Mande o JID do grupo em $to.
$bz->presenceChat($inst, $grupo, 'typing');     // typing | recording | paused
$bz->presenceChat($inst, '+5511999999999', 'recording');

// Conversas
$bz->listConversations($inst);
$bz->conversationHistory($inst, $grupo, ['before' => '2026-06-01T00:00:00Z', 'limit' => 50]);

// Estado do chat (arquivar / fixar / marcar lido). $on = true|false
$bz->archiveChat($inst, $grupo, true);
$bz->pinChat($inst, $grupo, true);
$bz->markChat($inst, $grupo, true);

// Grupos
$bz->listGroups($inst);
$novo = $bz->createGroup($inst, 'Time de Vendas', ['5511999999999@s.whatsapp.net']);
$bz->getGroup($inst, $grupo);
$bz->previewGroupInvite($inst, 'CODIGO_DO_CONVITE'); // nome/tamanho SEM entrar
$bz->joinGroup($inst, 'CODIGO_DO_CONVITE');
$bz->updateGroupParticipants($inst, $grupo, 'add', ['5511888887777@s.whatsapp.net']); // add|remove|promote|demote
$bz->groupInvite($inst, $grupo); // link de convite
$bz->leaveGroup($inst, $grupo);

// Contatos — quais telefones têm WhatsApp
$bz->contactsCheck($inst, ['+5511999999999', '+5511888887777']);

// Perfil da instância
$bz->setProfile($inst, ['display_name' => 'Atendimento', 'status_message' => 'Online 24/7']);
```

> **Dica:** `presenceChat` aceita JID de grupo em `$to`, então você pode mostrar
> "digitando…" também em grupos, não só em conversas 1‑a‑1.

## API keys (self-serve)

```php
$bz->listKeys();

$created = $bz->createKey('CI bot', 'agent'); // role: "admin" | "agent"
echo $created['api_key']; // chave CRUA — mostrada UMA única vez, guarde já

$bz->revokeKey('uuid');
```

## Webhooks

Webhooks entregam eventos (mensagens recebidas, status de instância, mudanças em
grupos…) no seu endpoint HTTPS. O SDK tem duas partes: **gerenciar** os webhooks
(via `Client`) e **receber** as entregas com verificação de assinatura (via
`Bzapper\Webhooks`).

### Gerenciar

```php
// Criar (omita 'secret' para a API gerar um forte). O segredo volta UMA vez.
$wh = $bz->createWebhook('https://meusite.com/bzapper/webhook', [
    'event_types'   => ['message.received', 'instance.connected'], // vazio/omitido = todos
    'number_filter' => 'uuid-do-numero', // opcional: restringe a um número
]);
echo $wh['secret']; // GUARDE já — mostrado só agora; use-o em new Webhooks(...)

$bz->listWebhooks(); // { data: [...] }

// Atualizar / pausar. secret = "regenerate" rotaciona o segredo.
$bz->updateWebhook($wh['id'], ['active' => false]);
$rot = $bz->updateWebhook($wh['id'], ['secret' => 'regenerate']);
echo $rot['secret']; // novo segredo, mostrado só agora

$bz->testWebhook($wh['id'], 'message.received'); // dispara evento de teste
$bz->webhookDeliveries($wh['id'], 20);           // entregas recentes (limit opcional)

$bz->deleteWebhook($wh['id']);
```

> **Regra:** cada tipo de evento pertence a **um único webhook** (a API responde
> 409 em conflito).

### Receber (verificar assinatura + rotear)

A API assina toda entrega com `X-Bzapper-Signature: sha256=<hex>`, onde o hex é
`HMAC-SHA256(secret, corpo_cru)`. **Sempre** verifique usando o **corpo cru** (a
string exata recebida — nunca re-serialize o JSON); a comparação é timing-safe.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bzapper\Webhooks;
use Bzapper\WebhookSignatureException;

$hooks = new Webhooks('whsec_...'); // o 'secret' devolvido por createWebhook

$hooks
    ->on('message.received', function (array $event): void {
        // evento é um array associativo idiomático
        echo ($event['sender']['name'] ?? '?'), ': ', ($event['payload']['body'] ?? ''), "\n";
    })
    ->on('instance.connected', function (array $event): void {
        echo 'Número conectado: ', $event['instance_id'], "\n";
    })
    ->onAny(function (array $event): void {
        // roda para TODO evento — bom para log/idempotência
        error_log('bzapper event ' . $event['event_id'] . ' ' . $event['event_type']);
    });

// No seu endpoint HTTP — pegue o corpo CRU e o header de assinatura:
$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_BZAPPER_SIGNATURE'] ?? null;

try {
    $event = $hooks->handle($raw, $sig); // verifica, parseia e despacha
    http_response_code(200);             // 2xx = recebido; senão a API reentrega
} catch (WebhookSignatureException $e) {
    http_response_code(400);             // assinatura inválida — NÃO processe
}
```

O envelope do evento tem: `event_id`, `event_type`, `timestamp`, `instance_id`,
`client_reference?`, `group?{jid,name}`, `sender?{jid,lid,name}`, `mentions?[]` e
`payload{}`. Tipos de evento: `message.{received,sent,delivered,read,failed}`,
`instance.{connected,disconnected,banned,logged_out,warming,status}`,
`group.{joined,participant_added,participant_removed,participant_promoted,participant_demoted,subject_changed,description_changed}`
(veja `Webhooks::EVENT_TYPES`).

> **Idempotência:** a API pode **reentregar**. Use `$event['event_id']` (estável)
> para guardar os ids já processados (Redis/DB) e ignorar duplicatas.

Só precisa verificar a assinatura sem despachar? Use os estáticos
`Webhooks::verify($secret, $raw, $sig)` (bool) e
`Webhooks::constructEvent($secret, $raw, $sig)` (evento, ou lança).

## bZapper Connect (parceiros)

Com o **bZapper Connect**, o seu software deixa cada cliente seu assinar o
bZapper Pro e conectar o WhatsApp **sem sair do seu produto** (componente
embutido). O seu **backend** se autentica com o secret de parceiro
(`bz_partner_...`, enviado como `Authorization: Bearer`) usando
`Bzapper\PartnerClient` — esse secret **nunca** pode chegar ao navegador.

Fluxo:

1. o backend cria uma sessão (`createConnectSession`) e devolve o `session_token` ao front;
2. o front abre o componente com o token; ao concluir, ele emite um `code` de uso único (10 min);
3. o backend troca o `code` pela API key do cliente (`exchangeCode` → `api_key`, `bz_live_...`);
4. com essa key, o backend usa o `Client` normal em nome do cliente.

```php
use Bzapper\PartnerClient;

$partner = new PartnerClient('bz_partner_...'); // baseUrl opcional, como no Client

$partner->me();                                             // identidade do parceiro
$partner->createConnectSession('cliente-42', $customer, 'pt-BR'); // { session_token, expires_at, connection }
$partner->exchangeCode('cc_...');                           // conexão + api_key (mostrada UMA vez)
$partner->listConnections('cliente-42', 'active');          // { data: [...] } — filtros opcionais
$partner->getConnection('uuid');                            // status, conta, números
$partner->rotateConnectionKey('uuid');                      // nova api_key; a anterior para na hora
$partner->revokeConnection('uuid');                         // encerra (NÃO cancela o plano do cliente)
```

Status da conexão (`PartnerClient::STATUS_*`): `pending_account`,
`pending_payment`, `pending_number`, `active`, `suspended` (Pro do cliente sem
pagamento) e `revoked`.

Do lado do **cliente** (conta bZapper), os apps conectados aparecem via
`Client`: `$bz->listConnectedApps()` (`{ data: [...] }`, com `partner_name` e
`partner_logo_url`) e `$bz->revokeConnectedApp('uuid')` (admin; a key do parceiro
para de funcionar na hora).

### Exemplo completo de backend (PHP puro)

```php
<?php
// connect.php — roteie /connect/session, /connect/exchange e /connect/webhook para cá.
require __DIR__ . '/vendor/autoload.php';

use Bzapper\BzapperException;
use Bzapper\Client;
use Bzapper\PartnerClient;
use Bzapper\Webhooks;
use Bzapper\WebhookSignatureException;

$partner = new PartnerClient(getenv('BZAPPER_PARTNER_SECRET'));

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}

// Seu banco: guarde a key e o status por cliente (aqui, só as assinaturas).
function save_customer_key(string $externalId, string $connectionId, string $apiKey): void { /* ... */ }
function save_connection_status(string $externalId, string $status): void { /* ... */ }
function load_customer_key(string $externalId): ?string { /* ... */ return null; }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    // 1) O front pede uma sessão para o usuário LOGADO no seu produto.
    case '/connect/session':
        $user = ['id' => 'cliente-42', 'name' => 'Ana Souza', 'email' => 'ana@exemplo.com']; // da sua sessão
        try {
            $session = $partner->createConnectSession($user['id'], [
                'name'    => $user['name'],
                'email'   => $user['email'],
                'phone'   => '+5511988887777', // opcional: pré-preenche o número
                'company' => 'Boxy Pharma',    // opcional: vira o nome da conta/projeto
                'country' => 'BR',             // opcional: define a moeda
            ], 'pt-BR');
            // Entregue SÓ o token ao front (BzapperConnect.open({ session })).
            json_out(201, ['session' => $session['session_token'], 'expires_at' => $session['expires_at']]);
        } catch (BzapperException $e) {
            json_out($e->getStatusCode() ?: 502, ['error' => $e->getErrorCode()]);
        }
        break;

    // 2) O componente emitiu `bzapper:complete` com o code; o front o manda para cá.
    case '/connect/exchange':
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            $conn = $partner->exchangeCode((string) ($input['code'] ?? ''));
            save_customer_key($conn['external_id'], $conn['id'], $conn['api_key']); // mostrada UMA vez
            json_out(200, ['status' => $conn['status']]); // nunca devolva a key ao front
        } catch (BzapperException $e) {
            json_out(400, ['error' => $e->getErrorCode()]);
        }
        break;

    // 3) Webhook do parceiro: eventos connect.* + eventos dos projetos conectados.
    case '/connect/webhook':
        $hooks = new Webhooks(getenv('BZAPPER_PARTNER_WEBHOOK_SECRET'));
        $hooks
            ->on(Webhooks::EVENT_CONNECT_COMPLETED, function (array $e) use ($partner): void {
                // Fallback: o cliente concluiu mas a troca do code não aconteceu
                // (aba fechada…). O evento NÃO traz a key — gere uma nova.
                if (load_customer_key($e['connection']['external_id']) === null) {
                    $conn = $partner->rotateConnectionKey($e['connection']['id']);
                    save_customer_key($conn['external_id'], $conn['id'], $conn['api_key']);
                }
                save_connection_status($e['connection']['external_id'], 'active');
            })
            ->on(Webhooks::EVENT_CONNECT_SUSPENDED, function (array $e): void {
                save_connection_status($e['connection']['external_id'], 'suspended'); // avise o cliente
            })
            ->on(Webhooks::EVENT_CONNECT_RESUMED, function (array $e): void {
                save_connection_status($e['connection']['external_id'], 'active');
            })
            ->on(Webhooks::EVENT_CONNECT_REVOKED, function (array $e): void {
                save_connection_status($e['connection']['external_id'], 'revoked'); // descarte a key
            })
            ->on('message.received', function (array $e): void {
                // eventos operacionais chegam com o cliente identificado em $e['connection']
            });
        try {
            $hooks->handle(file_get_contents('php://input'), $_SERVER['HTTP_X_BZAPPER_SIGNATURE'] ?? null);
            http_response_code(200); // 2xx = recebido; senão a API reentrega (use event_id p/ idempotência)
        } catch (WebhookSignatureException $e) {
            http_response_code(400); // assinatura inválida — NÃO processe
        }
        break;
}

// 4) Em qualquer lugar do seu backend: envie em nome do cliente com a key dele.
function notify_customer(string $externalId, string $to, string $text): void
{
    $key = load_customer_key($externalId);
    if ($key === null) {
        return; // cliente ainda não conectou
    }
    try {
        (new Client($key))->sendText($to, $text);
    } catch (BzapperException $e) {
        if ($e->getErrorCode() === 'connect_suspended') {        // 402
            save_connection_status($externalId, 'suspended');    // Pro sem pagamento: peça para regularizar;
            return;                                              // a key volta sozinha quando pago
        }
        if ($e->getErrorCode() === 'connect_revoked') {          // 401
            save_connection_status($externalId, 'revoked');      // conexão encerrada: ofereça reconectar
            return;
        }
        throw $e;
    }
}
```

O webhook de parceiro usa o **mesmo** esquema de assinatura
(`X-Bzapper-Signature: sha256=<hex>`), com o secret de webhook do parceiro. O
envelope é o normal + `connection{id, external_id, account_id, project_id, status}`
(em `$event['connection']`; `null` em webhooks comuns). Eventos:
`connect.{completed,suspended,resumed,revoked}` (`Webhooks::CONNECT_EVENT_TYPES`)
e, para conexões ativas, os eventos de `Webhooks::EVENT_TYPES` dos projetos.

Códigos de erro do Connect: **402 `connect_suspended`** (a key do cliente está
suspensa porque o Pro não foi pago; volta sozinha) e **401 `connect_revoked`**
(conexão encerrada). Constantes: `PartnerClient::ERROR_CONNECT_SUSPENDED` e
`PartnerClient::ERROR_CONNECT_REVOKED`.

## Uso

```php
$usage = $bz->getUsage(['from' => '2026-01-01T00:00:00Z', 'to' => '2026-02-01T00:00:00Z']);
echo $usage['sent'], '/', $usage['total'];
```

## Tratamento de erro

Qualquer resposta não-2xx lança `Bzapper\BzapperException`. **Use sempre o
código neutro estável** (`getErrorCode()`) na sua lógica — nunca dê parse na
mensagem (que é traduzida conforme o locale, só para humanos). Obs.: o nativo
`getCode()` do PHP é `int` e final, então o status HTTP fica lá e em
`getStatusCode()`; o código neutro fica em `getErrorCode()`.

```php
use Bzapper\BzapperException;

try {
    $bz->sendText('+5511999999999', 'Oi');
} catch (BzapperException $e) {
    $e->getErrorCode();   // ex.: "not_connected", "rate_limited", "unauthorized" (use ESTE)
    $e->getStatusCode();  // ex.: 409, 429, 401
    $e->getMessage();     // texto traduzido (só para humanos)
    $e->getLocale();      // ex.: "pt-BR"

    if ($e->getErrorCode() === 'rate_limited') {
        // backoff e retry...
    }
}
```

## Exemplo rodável

Veja [`examples/quickstart.php`](examples/quickstart.php):

```bash
BZAPPER_BASE_URL=http://localhost:8080 \
BZAPPER_API_KEY=bz_live_... \
BZAPPER_TO=+5511999999999 \
php examples/quickstart.php
```

## Licença

MIT © Berni Software.
