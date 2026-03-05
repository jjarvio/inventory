<?php
/**
 * Cron B – Orders → Snipe-IT
 *
 * - Hakee WooCommerce tilaukset (processing, completed)
 * - Aggregoi line_items → consumable qty
 * - Checkouttaa consumablen TASAN KERRAN / tilaus
 * - Luottaa Snipe-IT checkoutiin varastototuutena
 * - Estää tuplakäsittelyn (_snipe_synced)
 */

require __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$DEBUG = filter_var(getenv('CRON_B_DEBUG'), FILTER_VALIDATE_BOOLEAN);

$WOO_BASE_URL        = rtrim(getenv('WOO_URL'), '/');
$WOO_CONSUMER_KEY    = getenv('WOO_CONSUMER_KEY');
$WOO_CONSUMER_SECRET = getenv('WOO_CONSUMER_SECRET');

$SNIPE_BASE_URL    = rtrim(getenv('SNIPE_BASE_URL'), '/');
$SNIPE_API_TOKEN   = getenv('SNIPE_API_TOKEN');
$SNIPE_LOCATION_ID = (int) getenv('SNIPE_LOCATION_ID');

$LOG_FILE = rtrim(getenv('LOG_PATH'), '/') . '/cron_b_orders.log';

const DRY_RUN = false;

$required = [
    'WOO_URL'             => $WOO_BASE_URL,
    'WOO_CONSUMER_KEY'    => $WOO_CONSUMER_KEY,
    'WOO_CONSUMER_SECRET' => $WOO_CONSUMER_SECRET,
    'SNIPE_BASE_URL'      => $SNIPE_BASE_URL,
    'SNIPE_API_TOKEN'     => $SNIPE_API_TOKEN,
    'SNIPE_LOCATION_ID'   => $SNIPE_LOCATION_ID,
    'LOG_PATH'            => rtrim(getenv('LOG_PATH'), '/'),
];

foreach ($required as $key => $value) {
    if ($value === null || $value === '') {
        throw new RuntimeException("Missing ENV variable: {$key}");
    }
}

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

$RUN_ID = bin2hex(random_bytes(4));

set_exception_handler(function (Throwable $e) use ($RUN_ID): void {
    log_line("[ERROR] Unhandled exception run_id={$RUN_ID}: " . $e->getMessage());
    exit(1);
});

register_shutdown_function(function () use ($RUN_ID): void {
    $lastError = error_get_last();

    if ($lastError !== null && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_line(
            "[ERROR] Fatal error run_id={$RUN_ID}: {$lastError['message']} @ {$lastError['file']}:{$lastError['line']}"
        );
    }
});

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
    $curlError = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return [
            'ok'   => false,
            'code' => 0,
            'json' => null,
            'raw'  => '',
            'err'  => $curlError,
        ];
    }

    return [
        'ok'   => $code >= 200 && $code < 300,
        'code' => $code,
        'json' => json_decode($resp, true),
        'raw'  => $resp,
        'err'  => $curlError,
    ];
}

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

    if (!$res['ok']) {
        log_line("ERROR WOO orders fetch failed code={$res['code']} err={$res['err']} raw={$res['raw']}");
        return [];
    }

    return is_array($res['json']) ? $res['json'] : [];
}

function order_has_meta(array $order, string $key): bool
{
    foreach ($order['meta_data'] ?? [] as $m) {
        if (($m['key'] ?? null) === $key && ($m['value'] ?? null) === 'yes') {
            return true;
        }
    }

    return false;
}

function mark_order_synced(int $order_id): bool
{
    global $WOO_BASE_URL;

    if (DRY_RUN) {
        log_line("DRY_RUN mark order {$order_id} synced");
        return true;
    }

    $url = $WOO_BASE_URL . "/wp-json/wc/v3/orders/{$order_id}?" . woo_auth();

    $res = http_request(
        'PUT',
        $url,
        ['Content-Type: application/json'],
        json_encode([
            'meta_data' => [
                ['key' => '_snipe_synced', 'value' => 'yes'],
            ],
        ])
    );

    if (!$res['ok']) {
        log_line("ERROR WOO mark synced failed order={$order_id} code={$res['code']} err={$res['err']} raw={$res['raw']}");
        return false;
    }

    return true;
}

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

    $url = $SNIPE_BASE_URL . "/api/v1/consumables/{$consumable_id}/checkout";

    if (DRY_RUN) {
        log_line("DRY_RUN checkout consumable={$consumable_id} qty={$qty} (order={$order_id})");
        return true;
    }

    $payload = [
        'assigned_to'  => 1,
        'checkout_qty' => $qty,
        'note'         => "Woo order #{$order_id}",
    ];

    $res = http_request('POST', $url, snipe_headers(), json_encode($payload));

    if (!$res['ok']) {
        log_line(
            "ERROR SNIPE checkout failed id={$consumable_id} order={$order_id} code={$res['code']} err={$res['err']} raw={$res['raw']}"
        );
        return false;
    }

    log_line("SNIPE checkout OK consumable={$consumable_id} qty={$qty} (order={$order_id})");
    return true;
}

log_line("=== Cron B Orders START id={$RUN_ID} ===");
debugMsg('Cron B started');

$orders = woo_get_orders();
debugMsg('Orders fetched: ' . count($orders));

if (empty($orders)) {
    log_line("=== Cron B Orders END id={$RUN_ID} (no orders) ===");
    exit(0);
}

foreach ($orders as $order) {
    $order_id = (int) ($order['id'] ?? 0);
    if ($order_id <= 0) {
        continue;
    }

    debugMsg("Processing order {$order_id} status=" . ($order['status'] ?? 'unknown'));

    if (order_has_meta($order, '_snipe_synced')) {
        log_line("ORDER {$order_id} already synced → skip");
        continue;
    }

    $consumables = [];

    foreach ($order['line_items'] ?? [] as $item) {
        $sku = (string) ($item['sku'] ?? '');
        $qty = (int) ($item['quantity'] ?? 0);

        log_line("ORDER {$order_id} item=" . ($item['name'] ?? '') . " sku={$sku} qty={$qty}");

        if (!preg_match('/^snipe-consumable-(\d+)$/', $sku, $m)) {
            continue;
        }

        $cid = (int) $m[1];
        $consumables[$cid] = ($consumables[$cid] ?? 0) + $qty;
    }

    if (empty($consumables)) {
        log_line("ORDER {$order_id} has no Snipe items");
        mark_order_synced($order_id);
        continue;
    }

    foreach ($consumables as $cid => $qty) {
        if (!snipe_checkout($cid, $qty, $order_id)) {
            continue 2;
        }
    }

    if (mark_order_synced($order_id)) {
        log_line("ORDER {$order_id} marked as synced");
    }
}

log_line("=== Cron B Orders END id={$RUN_ID} ===");