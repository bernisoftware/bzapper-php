<?php

/**
 * Roteador do servidor HTTP de teste (`php -S 127.0.0.1:<porta> tests/server.php`).
 *
 * Lê o roteiro de respostas de `$BZAPPER_MOCK_DIR/script.json` (uma por requisição, em ordem),
 * registra cada requisição recebida CRUA em `$BZAPPER_MOCK_DIR/requests.jsonl` e responde com a
 * resposta de mesmo índice. Quem confere o que chegou é o teste (MockServer::received()).
 *
 * Resposta: `body` string = `text/plain`; `null` = sem corpo; qualquer outra coisa = JSON.
 */

declare(strict_types=1);

$dir = (string) getenv('BZAPPER_MOCK_DIR');
$logFile = $dir . '/requests.jsonl';

$lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$index = is_array($lines) ? count($lines) : 0;

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower((string) $name)] = (string) $value;
}

file_put_contents($logFile, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '', // cru: o caminho exatamente como a SDK codificou
    'headers' => $headers,
    'body' => base64_encode((string) file_get_contents('php://input')),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

// Objetos (não arrays associativos) para que `{}` do roteiro volte como `{}`.
$script = json_decode((string) file_get_contents($dir . '/script.json'), false, 512, JSON_THROW_ON_ERROR);
$response = is_array($script) ? ($script[$index] ?? null) : null;

if (!$response instanceof stdClass) {
    http_response_code(418);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'MOCK_UNEXPECTED_REQUEST', 'message' => 'MOCK_UNEXPECTED_REQUEST', 'locale' => 'pt-BR']);

    return true;
}

http_response_code((int) $response->status);
foreach ((array) ($response->headers ?? []) as $name => $value) {
    header($name . ': ' . $value);
}
$body = $response->body ?? null;
if ($body === null) {
    header_remove('Content-Type');
} elseif (is_string($body)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
} else {
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

return true;
