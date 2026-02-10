<?php

// BOOTSTRAP (ENV)

require __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// CONFIG (ENV)

$DEBUG = filter_var(getenv('CRON_C_DEBUG'), FILTER_VALIDATE_BOOLEAN);

$SNIPE_BASE_URL  = rtrim(getenv('SNIPE_BASE_URL'), '/');
$SNIPE_API_TOKEN = getenv('SNIPE_API_TOKEN');

$WOO_BASE_URL        = rtrim(getenv('WOO_URL'), '/');
$WOO_CONSUMER_KEY    = getenv('WOO_CONSUMER_KEY');
$WOO_CONSUMER_SECRET = getenv('WOO_CONSUMER_SECRET');

$SALE_SUPPLIER_NAME = mb_strtolower(
    getenv('SALE_SUPPLIER_NAME') ?: 'myynnissä',
    'UTF-8'
);

$LOG_FILE = rtrim(getenv('LOG_PATH'), '/') . '/cron_c_consumables.log';

const DRY_RUN = false;

// BASIC VALIDATION

foreach ([
    'SNIPE_BASE_URL'      => $SNIPE_BASE_URL,
    'SNIPE_API_TOKEN'     => $SNIPE_API_TOKEN,
    'WOO_URL'             => $WOO_BASE_URL,
    'WOO_CONSUMER_KEY'    => $WOO_CONSUMER_KEY,
    'WOO_CONSUMER_SECRET' => $WOO_CONSUMER_SECRET,
    'LOG_PATH'            => rtrim(getenv('LOG_PATH'), '/'),
] as $k => $v) {
    if ($v === null || $v === '') {
        throw new RuntimeException("Missing ENV variable: {$k}");
    }
}

// HELPERS

function log_line(string $msg): void {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function debugMsg(string $msg): void {
    global $DEBUG;
    if ($DEBUG) {
        log_line('[DEBUG] ' . $msg);
    }
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null): array {
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

// AUTH

function snipe_headers(): array {
    global $SNIPE_API_TOKEN;
    return [
        'Authorization: Bearer ' . $SNIPE_API_TOKEN,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
}

function woo_auth(): string {
    global $WOO_CONSUMER_KEY, $WOO_CONSUMER_SECRET;
    return 'consumer_key=' . $WOO_CONSUMER_KEY . '&consumer_secret=' . $WOO_CONSUMER_SECRET;
}

// SALE FILTER

function consumable_is_for_sale(array $c): bool {
    global $SALE_SUPPLIER_NAME;

    if (empty($c['supplier']['name'])) {
        return false;
    }

    return mb_strtolower(trim($c['supplier']['name']), 'UTF-8') === $SALE_SUPPLIER_NAME;
}

// IMAGE NORMALIZATION

function normalize_snipe_image_url(?string $imagePath): ?string {
    global $SNIPE_BASE_URL;

    if (!$imagePath) return null;

    if (preg_match('#^https?://#i', $imagePath)) {
        return $imagePath;
    }

    $root = preg_replace('#/public/index\.php$#i', '', $SNIPE_BASE_URL);

    if (str_starts_with($imagePath, '/uploads/')) {
        return $root . $imagePath;
    }

    return $root . '/uploads/' . ltrim($imagePath, '/');
}

// WOO API HELPERS

function woo_get_product_by_sku(string $sku): ?array {
    global $WOO_BASE_URL;
    $url = $WOO_BASE_URL . "/wp-json/wc/v3/products?" . woo_auth() . "&sku=" . urlencode($sku);
    $res = http_request('GET', $url);
    return $res['json'][0] ?? null;
}

function woo_create_product(array $payload): void {
    global $WOO_BASE_URL;

    if (DRY_RUN) {
        log_line("DRY_RUN CREATE SKU={$payload['sku']}");
        return;
    }

    $url = $WOO_BASE_URL . "/wp-json/wc/v3/products?" . woo_auth();
    $res = http_request('POST', $url, ['Content-Type: application/json'], json_encode($payload));

    if (!$res['ok']) {
        log_line("ERROR WOO create failed sku={$payload['sku']} code={$res['code']} raw={$res['raw']}");
    }
}

function woo_update_product(int $id, array $payload): void {
    global $WOO_BASE_URL;

    if (DRY_RUN) {
        log_line("DRY_RUN UPDATE product id=$id");
        return;
    }

    $url = $WOO_BASE_URL . "/wp-json/wc/v3/products/$id?" . woo_auth();
    $res = http_request('PUT', $url, ['Content-Type: application/json'], json_encode($payload));

    if (!$res['ok']) {
        log_line("ERROR WOO update failed id=$id code={$res['code']} raw={$res['raw']}");
    }
}

function woo_get_category_by_name(string $name): ?array {
    global $WOO_BASE_URL;

    $url = $WOO_BASE_URL . "/wp-json/wc/v3/products/categories?" . woo_auth()
         . "&search=" . urlencode($name) . "&per_page=100";
    $res = http_request('GET', $url);

    foreach (($res['json'] ?? []) as $cat) {
        if (mb_strtolower($cat['name'], 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
            return $cat;
        }
    }
    return null;
}

function woo_create_category(string $name): ?array {
    global $WOO_BASE_URL;

    if (DRY_RUN) {
        log_line("DRY_RUN CREATE category '$name'");
        return ['id' => 0, 'name' => $name];
    }

    $url = $WOO_BASE_URL . "/wp-json/wc/v3/products/categories?" . woo_auth();
    $res = http_request('POST', $url, ['Content-Type: application/json'], json_encode(['name' => $name]));

    if (!$res['ok']) {
        log_line("ERROR WOO category create failed name='$name' code={$res['code']} raw={$res['raw']}");
        return null;
    }

    return $res['json'] ?? null;
}

function woo_has_been_published_from_meta(array $woo): bool {
    foreach (($woo['meta_data'] ?? []) as $m) {
        if (($m['key'] ?? '') === '_snipe_has_been_published' && ($m['value'] ?? '') === 'yes') {
            return true;
        }
    }
    return false;
}

// SNIPE API

function snipe_get_consumables(int $offset, int $limit): array {
    global $SNIPE_BASE_URL;
    $url = $SNIPE_BASE_URL . "/api/v1/consumables?limit=$limit&offset=$offset";
    return http_request('GET', $url, snipe_headers());
}

// MAIN

log_line("=== Cron C Consumables START ===");

$offset = 0;
$limit  = 100;

while (true) {

    $res = snipe_get_consumables($offset, $limit);

    if (!$res['ok']) {
        log_line("ERROR SNIPE list failed offset=$offset code={$res['code']} raw={$res['raw']}");
        break;
    }

    $rows  = $res['json']['rows'] ?? [];
    $total = (int)($res['json']['total'] ?? 0);

    if (empty($rows)) break;

    foreach ($rows as $c) {

        $id       = (int)$c['id'];
        $name     = (string)$c['name'];
        $price    = (string)($c['purchase_cost'] ?? '0');
        $qty      = (int)($c['remaining'] ?? 0);
        $category = (string)($c['category']['name'] ?? '');
        $sku      = "snipe-consumable-$id";

        if (!$id || $name === '') continue;

        $isForSale = consumable_is_for_sale($c);
        $woo = woo_get_product_by_sku($sku);

        if (!$isForSale) {
            if ($woo) {
                log_line("HIDE {$woo['id']} $name");
                woo_update_product((int)$woo['id'], [
                    'status' => 'private',
                    'catalog_visibility' => 'hidden',
                ]);
            }
            continue;
        }

        $wooCategoryName = trim($category);
        $wooCat = $wooCategoryName ? woo_get_category_by_name($wooCategoryName) : null;

        if ($wooCategoryName && !$wooCat) {
            log_line("CATEGORY create '$wooCategoryName'");
            $wooCat = woo_create_category($wooCategoryName);
        }

        $categoriesPayload = $wooCat ? [['id' => (int)$wooCat['id']]] : [];

        $imageUrl = normalize_snipe_image_url($c['image'] ?? null);

        $hasBeenPublished = false;

        if ($woo) {
            // jos meta kertoo että on ollut julkaistu
            if (woo_has_been_published_from_meta($woo)) {
                $hasBeenPublished = true;
            }

            // jos ihminen on joskus julkaissut (status publish milloin tahansa)
            if (($woo['status'] ?? '') === 'publish') {
                $hasBeenPublished = true;
            }
}


        $visible = ($qty > 0) && $hasBeenPublished;

        // Jos tuote on publish nyt, lukitse muisti pysyvästi
        if ($woo && ($woo['status'] ?? '') === 'publish') {
            $hasBeenPublished = true;
        }


        $payload = [
            'name'               => $name,
            'sku'                => $sku,
            'regular_price'      => $price,
            'manage_stock'       => true,
            'stock_quantity'     => $qty,
            'stock_status'       => $qty > 0 ? 'instock' : 'outofstock',
            'status'             => $visible ? 'publish' : 'private',
            'catalog_visibility' => $visible ? 'visible' : 'hidden',
            'categories'         => $categoriesPayload,
            'meta_data' => [
                ['key' => '_snipeit_consumable_id',    'value' => $id],
                ['key' => '_snipe_has_been_published', 'value' => $hasBeenPublished ? 'yes' : 'no'],
                ['key' => '_snipeit_category',         'value' => $category],
                ['key' => '_snipeit_supplier',         'value' => $c['supplier']['name'] ?? ''],
            ],
        ];

        if ($imageUrl && (!$woo || empty($woo['images']))) {
            log_line("IMAGE set for $name");
            $payload['images'] = [['src' => $imageUrl]];
        }

        if (!$woo) {
            log_line("CREATE $name qty=$qty visible=" . ($visible ? 'yes' : 'no'));
            woo_create_product($payload);
        } else {
            log_line("UPDATE {$woo['id']} $name qty=$qty visible=" . ($visible ? 'yes' : 'no'));
            woo_update_product((int)$woo['id'], $payload);
        }
    }

    $offset += $limit;
    if ($offset >= $total) break;
}

log_line("=== Cron C Consumables END ===");
