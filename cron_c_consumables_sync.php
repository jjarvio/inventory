<?php
/**
 * Cron C – Consumables → WooCommerce
 *
 * Ominaisuudet:
 * - Synkkaa vain consumables joiden supplier.name on SALES_SUPPLIER_NAME (case-insensitive)
 * - Woo-kategoria = Snipe-kategoria
 *   - jos Woo-kategoriaa ei ole → luo automaattisesti
 * - UUSI tuote: luodaan piilotettuna (private/hidden)
 * - Kun tuote on kerran publish (ihminen julkaissut) → muistetaan (_snipe_has_been_published=yes)
 * - qty=0 → piilotetaan
 * - qty>0 + ollut aiemmin julkaistu → julkaistaan automaattisesti
 * - Tuotekuva siirtyy automaattisesti (Woo images src), vain jos tuotteella ei vielä ole kuvaa
 */

require __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$DEBUG = filter_var(getenv('CRON_C_DEBUG'), FILTER_VALIDATE_BOOLEAN);

$SNIPE_BASE_URL  = rtrim(getenv('SNIPE_BASE_URL'), '/');
$SNIPE_API_TOKEN = getenv('SNIPE_API_TOKEN');

$WOO_BASE_URL        = rtrim(getenv('WOO_URL'), '/');
$WOO_CONSUMER_KEY    = getenv('WOO_CONSUMER_KEY');
$WOO_CONSUMER_SECRET = getenv('WOO_CONSUMER_SECRET');

$SALES_SUPPLIER_NAME = getenv('SALES_SUPPLIER_NAME') ?: 'Myynnissä';
$LOG_FILE = rtrim(getenv('LOG_PATH'), '/') . '/cron_c_consumables.log';

const DRY_RUN = false;

/* ================= helpers ================= */

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
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        log_line("[ERROR] Fatal error run_id={$RUN_ID}: {$err['message']} @ {$err['file']}:{$err['line']}");
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
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'   => $resp !== false && $code >= 200 && $code < 300,
        'code' => $code,
        'json' => $resp !== false ? json_decode($resp, true) : null,
        'raw'  => (string) $resp,
        'err'  => $err,
    ];
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

function woo_auth(): string
{
    global $WOO_CONSUMER_KEY, $WOO_CONSUMER_SECRET;
    return 'consumer_key=' . $WOO_CONSUMER_KEY . '&consumer_secret=' . $WOO_CONSUMER_SECRET;
}

/* ================= business logic ================= */

function extract_supplier_name(array $item): string
{
    foreach ([
        $item['supplier']['name'] ?? null,
        $item['supplier_name'] ?? null,
        $item['supplier'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }
    return '';
}

function supplier_is_for_sale(string $supplier): bool
{
    global $SALES_SUPPLIER_NAME;
    return mb_strtolower($supplier, 'UTF-8')
        === mb_strtolower($SALES_SUPPLIER_NAME, 'UTF-8');
}

function normalize_snipe_image_url(?string $imagePath): ?string
{
    global $SNIPE_BASE_URL;

    if (!$imagePath) {
        return null;
    }
    if (preg_match('#^https?://#i', $imagePath)) {
        return $imagePath;
    }

    $root = (string) preg_replace('#/public/index\.php$#i', '', $SNIPE_BASE_URL);
    return $root . (str_starts_with($imagePath, '/') ? $imagePath : '/uploads/' . $imagePath);
}

function woo_get_product_by_sku(string $sku): ?array
{
    global $WOO_BASE_URL;
    $res = http_request(
        'GET',
        $WOO_BASE_URL . '/wp-json/wc/v3/products?' . woo_auth() . '&sku=' . urlencode($sku)
    );
    return $res['ok'] ? ($res['json'][0] ?? null) : null;
}

function woo_get_or_create_category(string $name): ?array
{
    global $WOO_BASE_URL;

    $search = http_request(
        'GET',
        $WOO_BASE_URL . '/wp-json/wc/v3/products/categories?' . woo_auth() . '&search=' . urlencode($name)
    );

    if ($search['ok']) {
        foreach ($search['json'] as $cat) {
            if (strcasecmp($cat['name'] ?? '', $name) === 0) {
                return $cat;
            }
        }
    }

    if (DRY_RUN) {
        return ['id' => 0, 'name' => $name];
    }

    $create = http_request(
        'POST',
        $WOO_BASE_URL . '/wp-json/wc/v3/products/categories?' . woo_auth(),
        ['Content-Type: application/json'],
        json_encode(['name' => $name])
    );

    return $create['ok'] ? $create['json'] : null;
}

function woo_create_product(array $payload): void
{
    global $WOO_BASE_URL;
    if (!DRY_RUN) {
        http_request(
            'POST',
            $WOO_BASE_URL . '/wp-json/wc/v3/products?' . woo_auth(),
            ['Content-Type: application/json'],
            json_encode($payload)
        );
    }
}

function woo_update_product(int $id, array $payload): void
{
    global $WOO_BASE_URL;
    if (!DRY_RUN) {
        http_request(
            'PUT',
            $WOO_BASE_URL . "/wp-json/wc/v3/products/{$id}?" . woo_auth(),
            ['Content-Type: application/json'],
            json_encode($payload)
        );
    }
}

/* ================= run ================= */

log_line("=== Cron C Consumables START id={$RUN_ID} ===");
debugMsg("SALES_SUPPLIER_NAME={$SALES_SUPPLIER_NAME}");

$offset = 0;
$limit  = 100;

$seen = $processed = $hidden = $created = $updated = 0;

while (true) {
    $res = http_request(
        'GET',
        "{$SNIPE_BASE_URL}/api/v1/consumables?limit={$limit}&offset={$offset}",
        snipe_headers()
    );

    $rows  = $res['json']['rows'] ?? [];
    $total = (int) ($res['json']['total'] ?? 0);

    if (!$rows) {
        break;
    }

    foreach ($rows as $c) {
        $seen++;

        $id       = (int) ($c['id'] ?? 0);
        $name     = (string) ($c['name'] ?? '');
        $qty      = (int) ($c['remaining'] ?? 0);
        $price    = (string) ($c['purchase_cost'] ?? '0');
        $category = (string) ($c['category']['name'] ?? '');
        $supplier = extract_supplier_name($c);
        $sku      = "snipe-consumable-{$id}";

        if (!$id || $name === '') {
            continue;
        }

        $woo = woo_get_product_by_sku($sku);
        $isForSale = supplier_is_for_sale($supplier);

        if (!$isForSale) {
            if ($woo) {
                woo_update_product((int) $woo['id'], [
                    'status' => 'private',
                    'catalog_visibility' => 'hidden',
                ]);
                $hidden++;
            }
            continue;
        }

        $processed++;

        $wooCat = $category !== '' ? woo_get_or_create_category($category) : null;
        $categories = !empty($wooCat['id']) ? [['id' => (int) $wooCat['id']]] : [];

        $hasBeenPublished = false;
        foreach ($woo['meta_data'] ?? [] as $m) {
            if (($m['key'] ?? '') === '_snipe_has_been_published' && ($m['value'] ?? '') === 'yes') {
                $hasBeenPublished = true;
            }
        }
        if ($woo && ($woo['status'] ?? '') === 'publish') {
            $hasBeenPublished = true;
        }

        $visible = $qty > 0 && $hasBeenPublished;

        $payload = [
            'name'               => $name,
            'sku'                => $sku,
            'regular_price'      => $price,
            'manage_stock'       => true,
            'stock_quantity'     => $qty,
            'stock_status'       => $qty > 0 ? 'instock' : 'outofstock',
            'status'             => $visible ? 'publish' : 'private',
            'catalog_visibility' => $visible ? 'visible' : 'hidden',
            'categories'         => $categories,
            'meta_data'          => [
                ['key' => '_snipeit_consumable_id', 'value' => $id],
                ['key' => '_snipe_has_been_published', 'value' => $hasBeenPublished ? 'yes' : 'no'],
                ['key' => '_snipeit_category', 'value' => $category],
                ['key' => '_snipeit_supplier', 'value' => $supplier],
            ],
        ];

        $imageUrl = normalize_snipe_image_url($c['image'] ?? null);
        if ($imageUrl && (!$woo || empty($woo['images']))) {
            $payload['images'] = [['src' => $imageUrl]];
        }

        if ($woo) {
            woo_update_product((int) $woo['id'], $payload);
            $updated++;
        } else {
            woo_create_product($payload);
            $created++;
        }
    }

    $offset += $limit;
    if ($offset >= $total) {
        break;
    }
}

log_line("SUMMARY seen={$seen} processed={$processed} hidden={$hidden} created={$created} updated={$updated}");
log_line("=== Cron C Consumables END id={$RUN_ID} ===");