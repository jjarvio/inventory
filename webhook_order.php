<?php
declare(strict_types=1);

/**
 * WooCommerce Webhook → Queue 
 */

require __DIR__ . '/bootstrap.php';


// Helpers
function respond(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

function log_webhook(string $message, array $context = []): void {
    $logPath = getenv('LOG_PATH');
    $file = rtrim($logPath, '/') . '/webhook.log';

    file_put_contents($file, json_encode([
        'time' => date('Y-m-d H:i:s'),
        'msg'  => $message,
        'ctx'  => $context,
    ]) . PHP_EOL, FILE_APPEND);
}


// Security

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, 'Method Not Allowed');
}

$expected = getenv('WEBHOOK_SECRET');
$provided = $_GET['key'] ?? '';

if (!$expected || !hash_equals($expected, $provided)) {
    log_webhook('Unauthorized', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    respond(401, 'Unauthorized');
}


// Payload
$data = json_decode(file_get_contents('php://input'), true);
$orderId = $data['id'] ?? null;

if (!$orderId || !is_numeric($orderId)) {
    respond(400, 'Invalid order id');
}

// Queue
$queueDir = rtrim(getenv('WEBHOOK_QUEUE_PATH'), '/');
$file = $queueDir . '/order_' . (int)$orderId . '.json';

if (!file_exists($file)) {
    file_put_contents($file, json_encode([
        'order_id' => (int)$orderId,
        'queued_at' => date('c'),
    ], JSON_PRETTY_PRINT));
}

log_webhook('Order queued', ['order_id' => $orderId]);
respond(200, 'Queued');
