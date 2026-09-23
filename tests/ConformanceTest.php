<?php

declare(strict_types=1);

namespace Bzapper\Tests;

use Bzapper\AuthenticationException;
use Bzapper\BzapperException;
use Bzapper\Client;
use Bzapper\ConflictException;
use Bzapper\NetworkException;
use Bzapper\NotFoundException;
use Bzapper\PartnerClient;
use Bzapper\PermissionDeniedException;
use Bzapper\RateLimitException;
use Bzapper\ServerException;
use Bzapper\Tests\Support\Cases;
use Bzapper\Tests\Support\MockServer;
use Bzapper\ValidationException;
use Bzapper\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformidade (BRIEF §7): cada caso de `cases.json` roda contra o servidor HTTP local, que
 * responde o roteiro do caso; depois conferimos o que chegou e o que a SDK devolveu/lançou.
 */
final class ConformanceTest extends TestCase
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Em `expect.error.request_id`: "o X-Request-Id que a SDK enviou". */
    private const SENT = '$sent';

    /**
     * Ops cuja entrada na tabela aponta para um método que JÁ existia na v0.6.2 com retorno
     * `array` (não anulável). Para eles, "sem corpo" (204 ou 200 vazio) volta como `[]` —
     * não dá para mudar para `null` sem quebrar a assinatura publicada. EXCEÇÃO ESTREITA: só
     * nestas ops, `expect.result === null` aceita `[]`. Os métodos novos devolvem `null`.
     */
    private const LEGACY_ARRAY_OPS = [
        'sendText', 'sendOTP', 'sendImage', 'sendVideo', 'sendDocument', 'sendAudio', 'sendSticker',
        'sendLocation', 'sendContact', 'sendPoll', 'sendReaction', 'sendButtons', 'sendList',
        'listScheduled', 'cancelScheduled', 'createCampaign', 'listCampaigns', 'getCampaign',
        'updateCampaign', 'estimateCampaign', 'addCampaignRecipients', 'listCampaignRecipients',
        'startCampaign', 'pauseCampaign', 'resumeCampaign', 'cancelCampaign', 'dryRunCampaign',
        'listInstances', 'createInstance', 'getInstance', 'connectInstance', 'disconnectInstance',
        'clearInstanceSession', 'listMyKeys', 'createMyKey', 'revokeMyKey', 'getUsage', 'presenceChat',
        'listConversations', 'conversationHistory', 'archiveChat', 'pinChat', 'markChat', 'listGroups',
        'createGroup', 'getGroup', 'previewGroupInvite', 'joinGroup', 'updateGroupParticipants',
        'leaveGroup', 'groupInviteLink', 'contactsCheck', 'setProfile', 'listContacts', 'listProjects',
        'createProject', 'getBrand', 'setBrand', 'applyBrand', 'listUsers', 'inviteUser',
        'updateUserRole', 'removeUser', 'getAccountUsage', 'listAdvisories', 'markAdvisoryRead',
        'listWebhooks', 'createWebhook', 'updateWebhook', 'deleteWebhook', 'testWebhook',
        'listWebhookDeliveries', 'listConnectedApps', 'revokeConnectedApp', 'getPartnerMe',
        'createConnectSession', 'exchangeConnectCode', 'listPartnerConnections', 'getPartnerConnection',
        'revokePartnerConnection', 'rotatePartnerConnectionKey',
    ];

    /**
     * Tabela op → chamada idiomática da SDK. Op de `ops` fora daqui FALHA a suíte:
     * endpoint novo sem método quebra o build.
     *
     * Cada entrada recebe: cliente, cliente de parceiro, path, query, body (o JSON do caso;
     * objeto vazio = \stdClass) e as opções por chamada do caso (`idempotency_key`).
     *
     * @return array<string, \Closure(Client, PartnerClient, array<string,mixed>, array<string,mixed>, array<string,mixed>, array<string,mixed>): mixed>
     */
    public static function operations(): array
    {
        $rest = static fn (array $a, string ...$keys): array => array_diff_key($a, array_flip($keys));
        $file = static fn (array $b): array => [
            (string) base64_decode($b['file']['content_base64'], true),
            $b['file']['filename'],
            $b['file']['content_type'],
            is_array($b['fields'] ?? null) ? $b['fields'] : [],
        ];
        $media = static fn (string $m) => static fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->{$m}($b['to'], $b['media'], $rest($b, 'to', 'media') + $o);

        return [
            // --- mensagens (métodos da v0.6.2; opts de envio aceitam idempotency_key)
            'sendText' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendText($b['to'], $b['body'], $rest($b, 'to', 'body') + $o),
            'sendOTP' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendOtp($b['to'], $b['code'], $rest($b, 'to', 'code') + $o),
            'sendImage' => $media('sendImage'),
            'sendVideo' => $media('sendVideo'),
            'sendDocument' => $media('sendDocument'),
            'sendAudio' => $media('sendAudio'),
            'sendSticker' => $media('sendSticker'),
            'sendLocation' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendLocation($b['to'], $b['latitude'], $b['longitude'], $rest($b, 'to', 'latitude', 'longitude') + $o),
            'sendContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendContact($b['to'], $rest($b, 'to') + $o),
            'sendPoll' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendPoll($b['to'], $b['name'], $b['options'], $b['selectable_count'], $rest($b, 'to', 'name', 'options', 'selectable_count') + $o),
            'sendReaction' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendReaction($b['to'], $b['quoted_message_id'], $b['emoji'], $rest($b, 'to', 'quoted_message_id', 'emoji') + $o),
            'sendButtons' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendButtons($b['to'], $b['body'], $b['buttons'], $rest($b, 'to', 'body', 'buttons') + $o),
            'sendList' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->sendList($b['to'], $b['body'], $b['sections'], $rest($b, 'to', 'body', 'sections') + $o),
            'listScheduled' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listScheduled($q),
            'cancelScheduled' => fn (Client $c, PartnerClient $pc, array $p) => $c->cancelScheduled($p['id']),
            'editMessage' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->editMessage($p['id'], $b, $o),
            'revokeMessage' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->revokeMessage($p['id'], $q['for_everyone'] ?? null, $o),
            'forwardMessage' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->forwardMessage($b, $o),
            'markRead' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->markRead($p['id'], $b, $o),
            'presenceChat' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->presenceChat($b['instance_id'], $b['to'], $b['state']),

            // --- campanhas
            'createCampaign' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->createCampaign($b['variations'], $rest($b, 'variations')),
            'listCampaigns' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listCampaigns($q),
            'getCampaign' => fn (Client $c, PartnerClient $pc, array $p) => $c->getCampaign($p['id']),
            'updateCampaign' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->updateCampaign($p['id'], $b),
            'estimateCampaign' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->estimateCampaign($q['recipients'] ?? null, $q['pacing'] ?? null, $q['pool_id'] ?? null),
            'getCampaignEligibility' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getCampaignEligibility($q, $o),
            'uploadCampaignMedia' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->uploadCampaignMedia(...[...$file($b), $o]),
            'addCampaignRecipients' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->addCampaignRecipients($p['id'], $b),
            'listCampaignRecipients' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listCampaignRecipients($p['id'], $q),
            'startCampaign' => fn (Client $c, PartnerClient $pc, array $p) => $c->startCampaign($p['id']),
            'pauseCampaign' => fn (Client $c, PartnerClient $pc, array $p) => $c->pauseCampaign($p['id']),
            'resumeCampaign' => fn (Client $c, PartnerClient $pc, array $p) => $c->resumeCampaign($p['id']),
            'cancelCampaign' => fn (Client $c, PartnerClient $pc, array $p) => $c->cancelCampaign($p['id']),
            'dryRunCampaign' => fn (Client $c, PartnerClient $pc, array $p) => $c->dryRunCampaign($p['id']),

            // --- números (instâncias)
            'listInstances' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listInstances($q),
            'createInstance' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->createInstance($b['phone'], $rest($b, 'phone')),
            'getInstance' => fn (Client $c, PartnerClient $pc, array $p) => $c->getInstance($p['id']),
            'deleteInstance' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteInstance($p['id'], $o),
            'connectInstance' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->connectInstance($p['id'], $q['method'] ?? 'qr'),
            'disconnectInstance' => fn (Client $c, PartnerClient $pc, array $p) => $c->disconnectInstance($p['id']),
            'logoutInstance' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->logoutInstance($p['id'], $o),
            'clearInstanceSession' => fn (Client $c, PartnerClient $pc, array $p) => $c->clearInstanceSession($p['id']),
            'archiveInstance' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->archiveInstance($p['id'], $o),
            'unarchiveInstance' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->unarchiveInstance($p['id'], $o),
            'setInstanceProxy' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->setInstanceProxy($p['id'], $b, $o),
            'setInboundFilters' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->setInboundFilters($p['id'], $b, $o),
            'setProfile' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->setProfile($p['id'], $b),
            'setPrivacy' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->setPrivacy($p['id'], $b, $o),
            'getOfficialAccount' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getOfficialAccount($o),
            'connectOfficialAccount' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->connectOfficialAccount($b, $o),
            'disconnectOfficialAccount' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->disconnectOfficialAccount($o),

            // --- conversas e chats
            'listConversations' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listConversations($q['instance_id']),
            'conversationHistory' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->conversationHistory($q['instance_id'], $p['jid'], $rest($q, 'instance_id')),
            'archiveChat' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->archiveChat($b['instance_id'], $p['jid'], $b['on']),
            'pinChat' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->pinChat($b['instance_id'], $p['jid'], $b['on']),
            'markChat' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->markChat($b['instance_id'], $p['jid'], $b['on']),
            'muteChat' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->muteChat($b['instance_id'], $p['jid'], $b['on'], $o),
            'applyChatLabel' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->applyChatLabel($b['instance_id'], $p['jid'], $b['label_id'], $b['apply'], $o),
            'listLabels' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listLabels($q['instance_id'], $o),
            'createLabel' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->createLabel($b, $o),
            'deleteLabel' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteLabel($q['instance_id'], $p['id'], $o),
            'blockContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->blockContact($b['instance_id'], $p['jid'], $o),
            'unblockContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->unblockContact($b['instance_id'], $p['jid'], $o),
            'getBlocklist' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getBlocklist($q['instance_id'], $o),
            'rejectCall' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->rejectCall($b, $o),
            'offerCall' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->offerCall($b, $o),

            // --- grupos
            'listGroups' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listGroups($q['instance_id']),
            'createGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->createGroup($q['instance_id'], $b['name'], $b['participants']),
            'getGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->getGroup($q['instance_id'], $p['jid']),
            'updateGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->updateGroup($q['instance_id'], $p['jid'], $b, $o),
            'previewGroupInvite' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->previewGroupInvite($q['instance_id'], $b['code']),
            'joinGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->joinGroup($q['instance_id'], $b['code']),
            'updateGroupParticipants' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->updateGroupParticipants($q['instance_id'], $p['jid'], $b['action'], $b['participants']),
            'leaveGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->leaveGroup($q['instance_id'], $p['jid']),
            'groupInviteLink' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->groupInvite($q['instance_id'], $p['jid'], $q['reset'] ?? null),
            'listJoinRequests' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listJoinRequests($q['instance_id'], $p['jid'], $o),
            'updateJoinRequests' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->updateJoinRequests($q['instance_id'], $p['jid'], $b, $o),

            // --- contatos (CRM)
            'contactsCheck' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->contactsCheck($b['instance_id'], $b['phones']),
            'listContacts' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->listContacts($q),
            'importContacts' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->importContacts($b['contacts'], $b['dry_run'] ?? null, $o),
            'createContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->createContact($b, $o),
            'getContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getContact($p['id'], $o),
            'updateContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->updateContact($p['id'], $b, $o),
            'deleteContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteContact($p['id'], $o),
            'getContactHistory' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getContactHistory($p['id'], $q, $o),
            'addContactNote' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->addContactNote($p['id'], $b, $o),
            'mutateContactTags' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->mutateContactTags($p['id'], $b, $o),
            'mutateContactGroups' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->mutateContactGroups($p['id'], $b, $o),
            'optOutContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->optOutContact($p['id'], $o),
            'suppressContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->suppressContact($p['id'], $o),
            'optInContact' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->optInContact($p['id'], $o),
            'listTags' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listTags($o),
            'createTag' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->createTag($b, $o),
            'deleteTag' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteTag($p['id'], $o),
            'listContactGroups' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listContactGroups($o),
            'createContactGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->createContactGroup($b, $o),
            'deleteContactGroup' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteContactGroup($p['id'], $o),
            'listSuppressions' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listSuppressions($q, $o),
            'createSuppression' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->createSuppression($b, $o),
            'deleteSuppression' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteSuppression($q['phone'], $o),

            // --- pools
            'listPools' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listPools($o),
            'createPool' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->createPool($b, $o),
            'getPool' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getPool($p['id'], $o),
            'addPoolNumber' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->addPoolNumber($p['id'], $b, $o),

            // --- webhooks e avisos
            'listWebhooks' => fn (Client $c) => $c->listWebhooks(),
            'createWebhook' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->createWebhook($b['url'], $rest($b, 'url')),
            'updateWebhook' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->updateWebhook($p['id'], $b),
            'deleteWebhook' => fn (Client $c, PartnerClient $pc, array $p) => $c->deleteWebhook($p['id']),
            'testWebhook' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->testWebhook($p['id'], $b['event_type'] ?? null),
            'listWebhookDeliveries' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->webhookDeliveries($p['id'], $q['limit'] ?? null),
            'triggerWebhookEvent' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->triggerWebhookEvent($b, $o),
            'listAdvisories' => fn (Client $c) => $c->listAdvisories(),
            'markAdvisoryRead' => fn (Client $c, PartnerClient $pc, array $p) => $c->markAdvisoryRead($p['id']),

            // --- identidade, conta, projetos, usuários, chaves, marca
            'getHealth' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getHealth($o),
            'getMe' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getMe($o),
            'updateProfile' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->updateProfile($b, $o),
            'updateAccount' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->updateAccount($b, $o),
            'listMyKeys' => fn (Client $c) => $c->listKeys(),
            'createMyKey' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->createKey($b['name'], $b['role']),
            'revokeMyKey' => fn (Client $c, PartnerClient $pc, array $p) => $c->revokeKey($p['id']),
            'rotateMyKey' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->rotateKey($p['id'], $b['revoke_in_seconds'] ?? null, $o),
            'getBrand' => fn (Client $c) => $c->getBrand(),
            'setBrand' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->setBrand($b),
            'applyBrand' => fn (Client $c) => $c->applyBrand(),
            'uploadBrandLogo' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->uploadBrandLogo(...[...$file($b), $o]),
            'listProjects' => fn (Client $c) => $c->listProjects(),
            'createProject' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->createProject($b['name'], $b['api_mode'] ?? null),
            'getProjectsHealth' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getProjectsHealth($o),
            'updateProject' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->updateProject($p['id'], $b, $o),
            'deleteProject' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->deleteProject($p['id'], $o),
            'getProjectBrand' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getProjectBrand($p['id'], $o),
            'setProjectBrand' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->setProjectBrand($p['id'], $b, $o),
            'uploadProjectLogo' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->uploadProjectLogo($p['id'], ...[...$file($b), $o]),
            'listUsers' => fn (Client $c) => $c->listUsers(),
            'inviteUser' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->inviteUser($b['email'], $b['name'] ?? null, $b['role'] ?? null),
            'updateUserRole' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $c->updateUserRole($p['id'], $b['role']),
            'removeUser' => fn (Client $c, PartnerClient $pc, array $p) => $c->removeUser($p['id']),
            'getUsage' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->getUsage($q),
            'getAccountUsage' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $c->getAccountUsage($q),

            // --- cobrança
            'getMyEntitlements' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getMyEntitlements($o),
            'upgradePlan' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->upgradePlan($o),
            'cancelPlan' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->cancelPlan($o),
            'uncancelPlan' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->uncancelPlan($o),
            'getMySubscription' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getMySubscription($o),
            'changeAddon' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->changeAddon($b, $o),
            'getAddonCart' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getAddonCart($o),
            'clearAddonCart' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->clearAddonCart($o),
            'checkoutAddonCart' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->checkoutAddonCart($b, $o),
            'listMyInvoices' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->listMyInvoices($o),
            'payInvoice' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->payInvoice($p['id'], $o),
            'getBillingConfig' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getBillingConfig($o),
            'getPricing' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b, array $o) => $c->getPricing($o),

            // --- apps conectados (lado da conta) e bZapper Connect (PartnerClient)
            'listConnectedApps' => fn (Client $c) => $c->listConnectedApps(),
            'revokeConnectedApp' => fn (Client $c, PartnerClient $pc, array $p) => $c->revokeConnectedApp($p['id']),
            'getPartnerMe' => fn (Client $c, PartnerClient $pc) => $pc->me(),
            'createConnectSession' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $pc->createConnectSession($b['external_id'], $b['customer'], $b['locale'] ?? null),
            'exchangeConnectCode' => fn (Client $c, PartnerClient $pc, array $p, array $q, array $b) => $pc->exchangeCode($b['code']),
            'listPartnerConnections' => fn (Client $c, PartnerClient $pc, array $p, array $q) => $pc->listConnections($q['external_id'] ?? null, $q['status'] ?? null),
            'getPartnerConnection' => fn (Client $c, PartnerClient $pc, array $p) => $pc->getConnection($p['id']),
            'revokePartnerConnection' => fn (Client $c, PartnerClient $pc, array $p) => $pc->revokeConnection($p['id']),
            'rotatePartnerConnectionKey' => fn (Client $c, PartnerClient $pc, array $p) => $pc->rotateConnectionKey($p['id']),
        ];
    }

    /** @return iterable<string, array{int}> */
    public static function caseProvider(): iterable
    {
        foreach (Cases::assoc()['cases'] as $index => $case) {
            yield $case['id'] => [$index];
        }
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function signatureProvider(): iterable
    {
        foreach (Cases::assoc()['signatures'] as $i => $v) {
            yield ($v['id'] ?? ('vetor ' . $i)) => [$v['secret'], $v['body'], $v['signature'], $v['valid']];
        }
    }

    public function testEveryOpHasAMethod(): void
    {
        $data = Cases::assoc();
        $table = self::operations();
        $missing = array_values(array_diff($data['ops'], array_keys($table)));
        $this->assertSame([], $missing, 'ops de cases.json sem método na SDK PHP: registre em ConformanceTest::operations()');
        $this->assertCount(161, $data['ops']);
        $this->assertSame([], array_values(array_diff(self::LEGACY_ARRAY_OPS, array_keys($table))));
        // excluídas não podem ter método "por engano" na tabela
        $this->assertSame([], array_values(array_intersect($data['sdk_excluded_ops'], array_keys($table))));
    }

    #[DataProvider('caseProvider')]
    public function testCase(int $index): void
    {
        $data = Cases::assoc();
        $case = $data['cases'][$index];
        $caseObjects = Cases::objects()->cases[$index];
        $id = $case['id'];
        $op = $case['op'];

        $operations = self::operations();
        $this->assertArrayHasKey($op, $operations, sprintf('[%s] op "%s" não tem método na SDK PHP.', $id, $op));

        MockServer::script(array_map(static fn (\stdClass $ex): \stdClass => $ex->response, $caseObjects->exchanges));
        $sleeps = [];
        $opts = [
            'max_retries' => $data['max_retries'],
            'sleep' => static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        ];
        $client = new Client($data['api_key'], MockServer::url(), $opts);
        $partner = new PartnerClient($data['api_key'], MockServer::url(), $opts);

        $args = $caseObjects->args;
        $path = self::toPhp($args->path ?? new \stdClass());
        $query = self::toPhp($args->query ?? new \stdClass());
        $body = self::toPhp($args->body ?? new \stdClass());
        $options = (array) ($case['options'] ?? []);

        $result = null;
        $error = null;
        try {
            $result = $operations[$op]($client, $partner, is_array($path) ? $path : [], is_array($query) ? $query : [], is_array($body) ? $body : [], $options);
        } catch (BzapperException | \InvalidArgumentException $e) {
            $error = $e;
        }

        $received = MockServer::received();
        $this->assertRequests($data['api_key'], $case, $caseObjects, $received);
        $this->assertSleeps($id, $case['exchanges'], $sleeps);

        if (array_key_exists('error', $case['expect'])) {
            $expected = $case['expect']['error'];
            $this->assertNotNull($error, sprintf('[%s] esperava erro %s, veio resultado.', $id, $expected['type']));
            $this->assertSame($expected['type'], self::errorType($error), "[$id] tipo do erro: " . $error->getMessage());
            if ($expected['type'] === 'argument') {
                return;
            }
            \assert($error instanceof BzapperException);
            $this->assertSame($expected['code'], $error->getErrorCode(), "[$id] code");
            $this->assertSame($expected['status'], $error->getStatusCode(), "[$id] status");
            $this->assertSame($expected['status'], $error->getCode(), "[$id] getCode() = status");
            if (array_key_exists('request_id', $expected)) {
                $expectedRequestId = $expected['request_id'] === self::SENT
                    ? ($received[count($received) - 1]['headers']['x-request-id'] ?? null)
                    : $expected['request_id'];
                $this->assertNotNull($expectedRequestId, "[$id] X-Request-Id enviado");
                $this->assertSame($expectedRequestId, $error->getRequestId(), "[$id] request_id");
            }
            if (array_key_exists('retry_after', $expected)) {
                $this->assertSame($expected['retry_after'], $error->getRetryAfter(), "[$id] retry_after");
            }
            if (array_key_exists('required_scope', $expected)) {
                $this->assertSame($expected['required_scope'], $error->getRequiredScope(), "[$id] required_scope");
            }

            return;
        }

        if ($error !== null) {
            $this->fail(sprintf('[%s] erro inesperado: %s: %s', $id, $error::class, $error->getMessage()));
        }
        $expected = $case['expect']['result'];
        if ($expected === null && $result === [] && in_array($op, self::LEGACY_ARRAY_OPS, true)) {
            // Exceção estreita (ver LEGACY_ARRAY_OPS): método da v0.6.2 com retorno `array`.
            $this->addToAssertionCount(1);

            return;
        }
        $actual = self::sortKeys($result);
        if (is_array($expected) && is_array($actual) && array_key_exists('data', $expected) && array_is_list($actual)) {
            $expected = $expected['data']; // SDK que desembrulha {data: [...]} (BRIEF §4)
        }
        $this->assertSame(self::sortKeys($expected), $actual, "[$id] resultado");
    }

    #[DataProvider('signatureProvider')]
    public function testWebhookSignature(string $secret, string $body, string $signature, bool $valid): void
    {
        $this->assertSame($valid, Webhooks::verify($secret, $body, $signature));
        if ($valid) {
            $event = Webhooks::constructEvent($secret, $body, $signature);
            $this->assertSame(json_decode($body, true)['event'], $event['event']);
        } else {
            $this->expectException(\Bzapper\WebhookSignatureException::class);
            Webhooks::constructEvent($secret, $body, $signature);
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param list<array{method: string, uri: string, headers: array<string, string>, body: string}> $received
     */
    private function assertRequests(string $apiKey, array $case, \stdClass $caseObjects, array $received): void
    {
        $id = $case['id'];
        $this->assertCount(count($case['exchanges']), $received, "[$id] número de requisições");

        foreach ($case['exchanges'] as $i => $exchange) {
            $expected = $exchange['request'];
            $got = $received[$i];
            $headers = $got['headers'];
            $ctx = "[$id #$i]";

            $this->assertSame($expected['method'], $got['method'], "$ctx método");

            [$rawPath, $queryString] = array_pad(explode('?', $got['uri'], 2), 2, '');
            $this->assertStringNotContainsString(' ', $rawPath, "$ctx caminho cru sem espaço");
            $this->assertSame(
                array_map('rawurldecode', explode('/', $expected['path'])),
                array_map('rawurldecode', explode('/', $rawPath)),
                "$ctx caminho (segmento a segmento, decodificado)",
            );
            $this->assertSame(self::expectedPairs($expected['query']), self::parseQuery($queryString), "$ctx query");

            $expectedBody = $caseObjects->exchanges[$i]->request->body;
            if ($case['multipart'] ?? false) {
                $this->assertStringStartsWith('multipart/form-data', $headers['content-type'] ?? '', "$ctx Content-Type multipart");
                $this->assertStringContainsString($case['args']['body']['file']['filename'], $got['body'], "$ctx nome do arquivo no corpo");
            } elseif ($expectedBody === null) {
                $this->assertSame('', $got['body'], "$ctx sem corpo");
                $this->assertArrayNotHasKey('content-type', $headers, "$ctx Content-Type só com corpo");
            } else {
                $this->assertSame(
                    self::canon($expectedBody),
                    self::canon(json_decode($got['body'], false, 512, JSON_THROW_ON_ERROR)),
                    "$ctx corpo",
                );
                $this->assertSame('application/json', $headers['content-type'] ?? null, "$ctx Content-Type");
            }

            $this->assertSame('Bearer ' . $apiKey, $headers['authorization'] ?? null, "$ctx Authorization");
            $this->assertSame('application/json', $headers['accept'] ?? null, "$ctx Accept");
            $this->assertMatchesRegularExpression('#^bzapper-php/' . preg_quote(Client::VERSION, '#') . '$#', $headers['x-bzapper-client'] ?? '', "$ctx X-Bzapper-Client");
            $this->assertSame($headers['x-bzapper-client'] ?? null, $headers['user-agent'] ?? null, "$ctx User-Agent");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $headers['x-request-id'] ?? '', "$ctx X-Request-Id");
            $isWrite = in_array($expected['method'], self::WRITE_METHODS, true);
            if ($isWrite) {
                $this->assertNotSame('', $headers['idempotency-key'] ?? '', "$ctx Idempotency-Key em escrita");
            } else {
                $this->assertArrayNotHasKey('idempotency-key', $headers, "$ctx Idempotency-Key só em escrita");
            }
            foreach ($expected['headers'] ?? [] as $name => $value) {
                $this->assertSame($value, $headers[strtolower($name)] ?? null, "$ctx header $name");
            }

            if ($i === 0) {
                continue;
            }
            $previous = $received[$i - 1]['headers'];
            if ($exchange['retry']) {
                $this->assertSame($previous['x-request-id'] ?? null, $headers['x-request-id'] ?? null, "$ctx X-Request-Id repetido na nova tentativa");
                $this->assertSame($previous['idempotency-key'] ?? null, $headers['idempotency-key'] ?? null, "$ctx Idempotency-Key repetida na nova tentativa");
            } else {
                $this->assertNotSame($previous['x-request-id'] ?? null, $headers['x-request-id'] ?? null, "$ctx X-Request-Id novo por chamada");
                if ($isWrite) {
                    $this->assertNotSame($previous['idempotency-key'] ?? null, $headers['idempotency-key'] ?? null, "$ctx Idempotency-Key nova por chamada");
                }
            }
        }
    }

    /**
     * Uma espera por troca com `retry: true`: o Retry-After da resposta anterior (teto 60 s)
     * ou o backoff da tentativa.
     *
     * @param list<array<string, mixed>> $exchanges
     * @param list<float> $sleeps
     */
    private function assertSleeps(string $id, array $exchanges, array $sleeps): void
    {
        $expected = [];
        $attempt = 0;
        foreach ($exchanges as $i => $exchange) {
            if ($i > 0 && ($exchange['retry'] ?? false)) {
                $expected[] = [$attempt++, $exchanges[$i - 1]['response']['headers']['Retry-After'] ?? null];
            } else {
                $attempt = 0;
            }
        }

        $this->assertCount(count($expected), $sleeps, "[$id] esperas entre tentativas");
        foreach ($expected as $k => [$retry, $retryAfter]) {
            if ($retryAfter !== null) {
                $this->assertEqualsWithDelta(min(60.0, (float) $retryAfter), $sleeps[$k], 1e-9, "[$id] espera = Retry-After");
            } else {
                $base = min(8.0, 0.5 * (2 ** $retry));
                $this->assertGreaterThanOrEqual($base, $sleeps[$k], "[$id] backoff mínimo");
                $this->assertLessThanOrEqual($base * 1.25, $sleeps[$k], "[$id] backoff + jitter <= 25%");
            }
        }
    }

    private static function errorType(\Throwable $e): string
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => 'argument',
            $e instanceof AuthenticationException => 'authentication',
            $e instanceof PermissionDeniedException => 'permission_denied',
            $e instanceof NotFoundException => 'not_found',
            $e instanceof ConflictException => 'conflict',
            $e instanceof ValidationException => 'validation',
            $e instanceof RateLimitException => 'rate_limit',
            $e instanceof ServerException => 'server',
            $e instanceof NetworkException => 'network',
            default => 'api',
        };
    }

    /**
     * JSON do caso → valor PHP como um usuário da SDK escreveria: objeto com chaves vira array
     * associativo; objeto VAZIO vira `new \stdClass()` (é o jeito PHP de mandar `{}`).
     */
    private static function toPhp(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            if ($props === []) {
                return new \stdClass();
            }

            return array_map([self::class, 'toPhp'], $props);
        }
        if (is_array($value)) {
            return array_map([self::class, 'toPhp'], $value);
        }

        return $value;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map([self::class, 'sortKeys'], $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** Forma canônica que distingue objeto (`{}`) de lista (`[]`) e ignora ordem de chaves. */
    private static function canon(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            ksort($props);

            return ['{}' => array_map([self::class, 'canon'], $props)];
        }
        if (is_array($value)) {
            return array_map([self::class, 'canon'], $value);
        }

        return $value;
    }

    /**
     * Pares esperados da query, exatamente como em `cases.json` (sem tolerância).
     *
     * @param array<string, string> $query
     * @return list<array{string, string}>
     */
    private static function expectedPairs(array $query): array
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = [(string) $name, (string) $value];
        }
        sort($pairs);

        return $pairs;
    }

    /** @return list<array{string, string}> */
    private static function parseQuery(string $queryString): array
    {
        if ($queryString === '') {
            return [];
        }
        $pairs = [];
        foreach (explode('&', $queryString) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [rawurldecode(str_replace('+', ' ', $name)), rawurldecode(str_replace('+', ' ', $value))];
        }
        sort($pairs);

        return $pairs;
    }
}
