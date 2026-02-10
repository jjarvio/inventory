<?php
define('CRON_B_INCLUDED', true);

// BOOTSTRAP (ENV)
define('WEBHOOK_CONTEXT', true);
require __DIR__ . '/bootstrap.php';

if (!defined('CRON_B_INCLUDED') && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$lockFile = '/home/jonitiet/cron/locks/cron_b.lock';

if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    echo "[LOCK] Cron B already running, exit\n";
    exit;
}

touch($lockFile);
register_shutdown_function(fn() => @unlink($lockFile));





// CONFIG (ENV)

$DEBUG = filter_var(getenv('CRON_B_DEBUG'), FILTER_VALIDATE_BOOLEAN);

$WOO_BASE_URL         = rtrim(getenv('WOO_URL'), '/');
$WOO_CONSUMER_KEY    = getenv('WOO_CONSUMER_KEY');
$WOO_CONSUMER_SECRET = getenv('WOO_CONSUMER_SECRET');

$SNIPE_BASE_URL      = rtrim(getenv('SNIPE_BASE_URL'), '/'); 
$SNIPE_API_TOKEN     = getenv('SNIPE_API_TOKEN');
$SNIPE_LOCATION_ID   = (int) getenv('SNIPE_LOCATION_ID');

$LOG_FILE            = rtrim(getenv('LOG_PATH'), '/') . '/cron_b_orders.log';

const DRY_RUN = false;

// -----------------------------------------------------------------------------
// WEBHOOK QUEUE HANDLING (runs first)
// -----------------------------------------------------------------------------

$queueDir  = rtrim(getenv('WEBHOOK_QUEUE_PATH'), '/');
$doneDir   = $queueDir . '/done';
$failedDir = $queueDir . '/failed';

$files = glob($queueDir . '/order_*.json') ?: [];

foreach ($files as $file) {

    $payload = json_decode(file_get_contents($file), true);
    $orderId = $payload['order_id'] ?? null;

    if (!$orderId) {
        // rikkinäinen tiedosto → failed
        rename($file, $failedDir . '/' . basename($file));
        log_line("[QUEUE] Invalid payload → moved to failed");
        continue;
    }

    try {
        log_line("[QUEUE] Processing order {$orderId}");

        process_order_from_woocommerce((int)$orderId);

        // onnistui → done
        rename($file, $doneDir . '/' . basename($file));
        log_line("[QUEUE] Order {$orderId} processed → moved to done");

    } catch (Throwable $e) {

        // oikea virhe → failed
        rename($file, $failedDir . '/' . basename($file));

        log_line("[QUEUE][ERROR] Order {$orderId} failed → moved to failed");
        log_line("[QUEUE][ERROR] " . $e->getMessage());
    }
}


// BASIC VALIDATION

$required = [
    'WOO_URL'             => $WOO_BASE_URL,
    'WOO_CONSUMER_KEY'    => $WOO_CONSUMER_KEY,
    'WOO_CONSUMER_SECRET' => $WOO_CONSUMER_SECRET,
    'SNIPE_BASE_URL'      => $SNIPE_BASE_URL,
    'SNIPE_API_TOKEN'     => $SNIPE_API_TOKEN,
    'SNIPE_LOCATION_ID'   => $SNIPE_LOCATION_ID,
];

foreach ($required as $key => $value) {
    if ($value === null || $value === '') {
        throw new RuntimeException("Missing ENV variable: {$key}");
    }
}


// LOG / DEBUG

function log_line(string $msg): void
{
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function debugMsg(string $msg): void
{
    global $DEBUG;
    if ($DEBUG) {
        log_line('[DEBUG] ' . $msg);
    }
}


// HTTP

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'   => $code >= 200 && $code < 300,
        'code' => $code,
        'json' => json_decode($resp, true),
        'raw'  => $resp,
    ];
}


// Woocommerce

function woo_auth(): string
{
    global $WOO_CONSUMER_KEY, $WOO_CONSUMER_SECRET;
    return 'consumer_key=' . $WOO_CONSUMER_KEY . '&consumer_secret=' . $WOO_CONSUMER_SECRET;
}

function woo_get_orders(): array
{
    global $WOO_BASE_URL;

    $url = $WOO_BASE_URL . '/wp-json/wc/v3/orders?' . woo_auth()
         . '&status=processing,completed&per_page=20';

    $res = http_request('GET', $url);
    return is_array($res['json']) ? $res['json'] : [];
}

function order_has_meta(array $order, string $key): bool
{
    foreach ($order['meta_data'] ?? [] as $m) {
        if ($m['key'] === $key && $m['value'] === 'yes') {
            return true;
        }
    }
    return false;
}

function mark_order_synced(int $order_id): void
{
    global $WOO_BASE_URL;

    if (DRY_RUN) {
        log_line("DRY_RUN mark order $order_id synced");
        return;
    }

    $url = $WOO_BASE_URL . "/wp-json/wc/v3/orders/$order_id?" . woo_auth();

    http_request(
        'PUT',
        $url,
        ['Content-Type: application/json'],
        json_encode([
            'meta_data' => [
                ['key' => '_snipe_synced', 'value' => 'yes']
            ]
        ])
    );
}


// SNIPE

function snipe_headers(): array
{
    global $SNIPE_API_TOKEN;
    return [
        'Authorization: Bearer ' . $SNIPE_API_TOKEN,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
}

function snipe_checkout(int $consumable_id, int $qty, int $order_id): bool
{
    global $SNIPE_BASE_URL;

    $url = $SNIPE_BASE_URL . "/api/v1/consumables/$consumable_id/checkout";

    if (DRY_RUN) {
        log_line("DRY_RUN checkout consumable=$consumable_id qty=$qty (order=$order_id)");
        return true;
    }

    $payload = [
        'assigned_to'  => 1,
        'checkout_qty' => $qty,
        'note'         => "Woo order #$order_id",
    ];

    $res = http_request('POST', $url, snipe_headers(), json_encode($payload));

    if (!$res['ok']) {
        log_line("ERROR SNIPE checkout failed id=$consumable_id order=$order_id raw={$res['raw']}");
        return false;
    }

    log_line("SNIPE checkout OK consumable=$consumable_id qty=$qty (order=$order_id)");
    return true;
}

function process_order_from_woocommerce(int $order_id): void
{
    // Hae yksittäinen order WooCommercesta
    global $WOO_BASE_URL;

    $orders = woo_get_orders();

    $order = null;
    foreach ($orders as $o) {
        if ((int)$o['id'] === $order_id) {
            $order = $o;
            break;
        }
    }

    if (!$order) {
        // Tilaus ei ole enää uusimpien joukossa → todennäköisesti jo käsitelty
        log_line("ORDER $order_id not found in Woo list → assume already handled");
        return;
    }



    // --- TÄSTÄ ALKAA SUORAAN SUN NYKYINEN POLLING-LOGIIKKA ---

    if (order_has_meta($order, '_snipe_synced')) {
        log_line("ORDER $order_id already synced → skip");
        return;
    }

    $consumables = [];

    foreach ($order['line_items'] as $item) {

        log_line(
            "ORDER $order_id item={$item['name']} sku={$item['sku']} qty={$item['quantity']}"
        );

        if (!preg_match('/^snipe-consumable-(\d+)$/', $item['sku'], $m)) {
            continue;
        }

        $cid = (int) $m[1];
        $consumables[$cid] = ($consumables[$cid] ?? 0) + (int) $item['quantity'];
    }

    if (empty($consumables)) {
        log_line("ORDER $order_id has no Snipe items");
        mark_order_synced($order_id);
        return;
    }

    foreach ($consumables as $cid => $qty) {
        if (!snipe_checkout($cid, $qty, $order_id)) {
            throw new RuntimeException("Snipe checkout failed for order $order_id");
        }
    }

    mark_order_synced($order_id);
    log_line("ORDER $order_id marked as synced");
}


// RUN (only when executed via CLI)
if (php_sapi_name() === 'cli') {

    log_line('=== Cron B Orders START ===');

    $orders = woo_get_orders();
    foreach ($orders as $order) {
        process_order_from_woocommerce((int)$order['id']);
    }

    log_line('=== Cron B Orders END ===');
}


// Cleanup old done files (>7 days)
foreach (glob($doneDir . '/order_*.json') as $f) {
    if (filemtime($f) < time() - 7 * 86400) {
        unlink($f);
    }
}