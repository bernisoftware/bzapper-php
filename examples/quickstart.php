<?php

declare(strict_types=1);

/**
 * Quickstart do SDK do bZapper em PHP.
 *
 * Rode com:
 *   BZAPPER_BASE_URL=http://localhost:8080 BZAPPER_API_KEY=bz_live_... \
 *   php examples/quickstart.php
 *
 * Em um projeto real, o autoload vem do Composer:
 *   require __DIR__ . '/vendor/autoload.php';
 */

require __DIR__ . '/../vendor/autoload.php';

use Bzapper\Client;
use Bzapper\BzapperException;

$baseUrl = getenv('BZAPPER_BASE_URL') ?: 'http://localhost:8080';
$apiKey  = getenv('BZAPPER_API_KEY') ?: 'bz_live_xxx';
$to      = getenv('BZAPPER_TO') ?: '+5511999999999';

$bz = new Client($baseUrl, $apiKey, ['locale' => 'pt-BR', 'timeout' => 30]);

try {
    // 1) Texto
    $msg = $bz->sendText($to, 'Olá do bZapper PHP SDK!');
    echo "Texto enfileirado: {$msg['message_id']}\n";

    // 2) Imagem (por URL)
    $bz->sendImage($to, ['url' => 'https://picsum.photos/600', 'caption' => 'Foto aleatória']);

    // 3) Vídeo
    $bz->sendVideo($to, ['url' => 'https://example.com/video.mp4', 'caption' => 'Veja isso']);

    // 4) Documento
    $bz->sendDocument($to, ['url' => 'https://example.com/contrato.pdf', 'filename' => 'contrato.pdf']);

    // 5) Áudio (nota de voz: ptt = true)
    $bz->sendAudio($to, ['url' => 'https://example.com/audio.ogg', 'ptt' => true]);

    // 6) Sticker
    $bz->sendSticker($to, ['url' => 'https://example.com/sticker.webp']);

    // 7) Localização
    $bz->sendLocation($to, -23.5613, -46.6565, ['name' => 'Av. Paulista', 'address' => 'São Paulo, SP']);

    // 8) Contato (vCard)
    $bz->sendContact($to, ['contact_name' => 'Suporte bZapper']);

    // 9) Enquete
    $bz->sendPoll($to, 'Qual seu plano favorito?', ['Free', 'Pro', 'Enterprise'], 1);

    // 10) Reação (precisa do id da mensagem citada)
    $bz->sendReaction($to, $msg['message_id'], '👍');

    // 11) Botões (caem para menu de texto numerado no WhatsApp)
    $bz->sendButtons($to, 'Escolha uma opção:', [
        ['id' => 'yes', 'title' => 'Sim'],
        ['id' => 'no', 'title' => 'Não'],
    ], ['footer' => 'Powered by bZapper']);

    // 12) Lista (idem fallback)
    $bz->sendList($to, 'Cardápio:', [
        ['title' => 'Bebidas', 'rows' => [
            ['id' => 'coke', 'title' => 'Refrigerante', 'description' => 'Lata 350ml'],
            ['id' => 'water', 'title' => 'Água'],
        ]],
    ], ['button_text' => 'Ver opções', 'footer' => 'Entrega rápida']);

    // Instâncias / keys / uso
    $instances = $bz->listInstances();
    echo 'Instâncias: ' . count($instances['data'] ?? []) . "\n";

    // Operações avançadas (precisam de um instance_id explícito)
    $inst = getenv('BZAPPER_INSTANCE_ID') ?: ($instances['data'][0]['id'] ?? null);
    if ($inst !== null) {
        // Presença "digitando…" — funciona inclusive em grupos!
        $bz->presenceChat($inst, $to, 'typing');

        // Conversas e grupos
        $bz->listConversations($inst);
        $groups = $bz->listGroups($inst);
        echo 'Grupos: ' . count($groups['data'] ?? []) . "\n";

        // Verifica quais telefones têm WhatsApp
        $check = $bz->contactsCheck($inst, [$to]);
        echo 'Contatos verificados: ' . count($check['data'] ?? []) . "\n";
    }

    $usage = $bz->getUsage(['from' => '2026-01-01T00:00:00Z']);
    echo 'Total enviado: ' . ($usage['sent'] ?? 0) . "\n";
} catch (BzapperException $e) {
    // SEMPRE use o code neutro (estável), nunca o texto da mensagem.
    fwrite(STDERR, sprintf(
        "Erro [%s] (HTTP %d): %s\n",
        $e->getErrorCode(),
        $e->getStatusCode(),
        $e->getMessage()
    ));
    exit(1);
}
