<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use BYD\Controllers\VapiWebhookController;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
$_ENV['APP_ENV'] = 'development';

$filePath = $argv[1] ?? '';
if (empty($filePath) || !file_exists($filePath)) {
    echo "Temporary payload file not found: {$filePath}\n";
    exit(1);
}

$raw = file_get_contents($filePath);
$payload = json_decode($raw, true);

if (!$payload || !isset($payload['message']['type'])) {
    echo "Invalid payload in file\n";
    exit(1);
}

$message = $payload['message'];
$type = $message['type'];

$controller = new VapiWebhookController();
$reflector = new ReflectionClass($controller);

if ($type === 'conversation-start') {
    $method = $reflector->getMethod('handleConversationStart');
} elseif ($type === 'status-update') {
    $method = $reflector->getMethod('handleStatusUpdate');
} elseif ($type === 'transcript') {
    $method = $reflector->getMethod('handleTranscript');
} elseif ($type === 'function-call' || $type === 'tool-calls') {
    $method = $reflector->getMethod('handleFunctionCall');
} elseif ($type === 'end-of-call-report') {
    $method = $reflector->getMethod('handleEndOfCall');
} else {
    echo "Unknown type: {$type}\n";
    exit(1);
}

$method->setAccessible(true);
$method->invoke($controller, $message);
