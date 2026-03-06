<?php
/**
 * Plugin Name: Inventory monitor
 * Description: Näyttää Inventoryn cron-ajojen historian, virheet ja mahdollistaa Cron B/C manuaalisen ajon.
 * Version: 1.0.0
 * Author: jjarvio
 */

if (!defined('ABSPATH')) {
    exit;
}

const INV2_OPTION_KEY = 'inv2_monitor_settings';
const INV2_LAST_CLEANUP_OPTION_KEY = 'inv2_monitor_last_cleanup_ts';
const INV2_CLEANUP_HOOK = 'inv2_monitor_daily_cleanup';


//Activation / Deactivation
 
register_activation_hook(__FILE__, function (): void {
    if (!wp_next_scheduled(INV2_CLEANUP_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', INV2_CLEANUP_HOOK);
    }
});

register_deactivation_hook(__FILE__, function (): void {
    if ($ts = wp_next_scheduled(INV2_CLEANUP_HOOK)) {
        wp_unschedule_event($ts, INV2_CLEANUP_HOOK);
    }
});

add_action(INV2_CLEANUP_HOOK, 'inv2_maybe_cleanup_logs');


// Admin
 
add_action('admin_menu', function (): void {
    add_menu_page(
        'Inventory monitor',
        'Inventory monitor',
        'manage_options',
        'inv2-monitor',
        'inv2_render_admin_page',
        'dashicons-update',
        58
    );
});

add_action('admin_init', function (): void {
    register_setting(
        'inv2_monitor',
        INV2_OPTION_KEY,
        [
            'type'              => 'array',
            'sanitize_callback' => 'inv2_sanitize_settings',
            'default'           => inv2_default_settings(),
        ]
    );

    inv2_maybe_cleanup_logs();
});

add_action('wp_ajax_inv2_logs_status', 'inv2_ajax_logs_status');


 // Settings
 
function inv2_default_settings(): array
{
    return [
        'php_binary'        => '/usr/local/bin/php',
        'cron_b_script'     => '/home/USER/cron/cron_b_orders_to_snipe.php',
        'cron_c_script'     => '/home/USER/cron/cron_c_consumables_sync.php',
        'cron_b_log'        => '/home/USER/cron/logs/cron_b_orders.log',
        'cron_c_log'        => '/home/USER/cron/logs/cron_c_consumables.log',
        'log_retention_days'=> 7,
    ];
}

function inv2_get_settings(): array
{
    $saved = get_option(INV2_OPTION_KEY, []);
    $settings = wp_parse_args(is_array($saved) ? $saved : [], inv2_default_settings());
    $settings['log_retention_days'] = max(1, (int) $settings['log_retention_days']);
    return $settings;
}

function inv2_sanitize_settings($input): array
{
    $defaults = inv2_default_settings();
    $input = is_array($input) ? $input : [];

    return [
        'php_binary' => sanitize_text_field($input['php_binary'] ?? $defaults['php_binary']),
        'cron_b_script' => sanitize_text_field($input['cron_b_script'] ?? $defaults['cron_b_script']),
        'cron_c_script' => sanitize_text_field($input['cron_c_script'] ?? $defaults['cron_c_script']),
        'cron_b_log' => sanitize_text_field($input['cron_b_log'] ?? $defaults['cron_b_log']),
        'cron_c_log' => sanitize_text_field($input['cron_c_log'] ?? $defaults['cron_c_log']),
        'log_retention_days' => max(1, (int) ($input['log_retention_days'] ?? $defaults['log_retention_days'])),
    ];
}


 // Log cleanup
 
function inv2_maybe_cleanup_logs(): void
{
    $settings = inv2_get_settings();
    $threshold = $settings['log_retention_days'] * DAY_IN_SECONDS;

    $lastCleanup = (int) get_option(INV2_LAST_CLEANUP_OPTION_KEY, 0);
    if ((time() - $lastCleanup) < $threshold) {
        return;
    }

    foreach (['cron_b_log', 'cron_c_log'] as $key) {
        $path = $settings[$key] ?? '';
        if ($path && file_exists($path) && is_writable($path)) {
            file_put_contents($path, '');
        }
    }

    update_option(INV2_LAST_CLEANUP_OPTION_KEY, time(), false);
}

function inv2_clear_logs_now(): array
{
    $settings = inv2_get_settings();
    $errors = [];
    $cleared = 0;

    foreach (['cron_b_log', 'cron_c_log'] as $key) {
        $path = $settings[$key];
        if (!file_exists($path)) {
            $errors[] = "Lokia ei löytynyt: {$path}";
            continue;
        }
        if (!is_writable($path)) {
            $errors[] = "Loki ei ole kirjoitettava: {$path}";
            continue;
        }
        file_put_contents($path, '');
        $cleared++;
    }

    update_option(INV2_LAST_CLEANUP_OPTION_KEY, time(), false);

    return ['ok' => empty($errors), 'cleared' => $cleared, 'errors' => $errors];
}


 // Cron runner

function inv2_run_script(string $scriptPath, string $phpBinary): array
{
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'output' => 'Scriptiä ei löydy: ' . $scriptPath];
    }

    exec(
        escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' 2>&1',
        $out,
        $code
    );

    return [
        'ok'     => $code === 0,
        'output' => implode("\n", $out),
    ];
}


 // UI helpers
 
function inv2_tail_file(string $path, int $maxLines = 120): array
{
    if (!file_exists($path)) {
        return [];
    }

    $maxLines = max(1, $maxLines);
    $file = @fopen($path, 'rb');
    if (!$file) {
        return [];
    }

    $buffer = '';
    $chunkSize = 4096;
    $lineBreaks = 0;

    fseek($file, 0, SEEK_END);
    $position = ftell($file);

    while ($position > 0 && $lineBreaks <= $maxLines) {
        $readSize = min($chunkSize, $position);
        $position -= $readSize;
        fseek($file, $position);
        $chunk = (string) fread($file, $readSize);
        $buffer = $chunk . $buffer;
        $lineBreaks += substr_count($chunk, "\n");
    }

    fclose($file);

    $lines = preg_split('/\r\n|\r|\n/', $buffer);
    if ($lines === false) {
        return [];
    }

    $lines = array_values(array_filter($lines, static fn($line): bool => $line !== ''));
    return array_slice($lines, -$maxLines);
}
function inv2_is_error_line(string $line): bool
{
    return (bool) preg_match('/error|fatal|exception|failed/i', $line);
}

function inv2_is_debug_line(string $line): bool
{
    return (bool) preg_match('/\bdebug\b/i', $line);
}

function inv2_filter_lines(array $lines, bool $errorsOnly = false, string $search = '', bool $showDebugLines = false): array
{
    $search = trim($search);

    return array_values(array_filter($lines, static function (string $line) use ($errorsOnly, $search, $showDebugLines): bool {
        if (!$showDebugLines && inv2_is_debug_line($line)) {
            return false;
        }

        if ($errorsOnly && !inv2_is_error_line($line)) {
            return false;
        }

        if ($search !== '' && stripos($line, $search) === false) {
            return false;
        }

        return true;
    }));
}

function inv2_get_logs_status(): array
{
    $settings = inv2_get_settings();
    $status = [];

    foreach (['cron_b_log', 'cron_c_log'] as $key) {
        $path = $settings[$key] ?? '';
        $status[$key] = [
            'path' => $path,
            'mtime' => ($path && file_exists($path)) ? (int) filemtime($path) : 0,
            'size' => ($path && file_exists($path)) ? (int) filesize($path) : 0,
        ];
    }

    return $status;
}

function inv2_ajax_logs_status(): void
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized'], 403);
    }

    check_ajax_referer('inv2_logs_status_nonce', 'nonce');
    wp_send_json_success(['logs' => inv2_get_logs_status()]);
}

function inv2_log_download_url(string $logPath): string
{
    return wp_nonce_url(
        add_query_arg(
            [
                'page' => 'inv2-monitor',
                'inv2_download_log' => rawurlencode($logPath),
            ],
            admin_url('admin.php')
        ),
        'inv2_download_log:' . $logPath
    );
}

function inv2_maybe_handle_log_download(): void
{
    if (!is_admin() || !current_user_can('manage_options') || !isset($_GET['inv2_download_log'])) {
        return;
    }

    $logPath = (string) rawurldecode(wp_unslash($_GET['inv2_download_log']));
    check_admin_referer('inv2_download_log:' . $logPath);

    if (!file_exists($logPath) || !is_readable($logPath)) {
        wp_die('Lokia ei löytynyt tai sitä ei voi lukea.');
    }

    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($logPath) . '"');
    header('Content-Length: ' . (string) filesize($logPath));
    readfile($logPath);
    exit;
}

add_action('admin_init', 'inv2_maybe_handle_log_download');

function inv2_render_log_panel(string $title, string $logPath, string $id): void
{
    $lineOptions = [50, 200, 1000];
    $selectedLineLimit = isset($_GET[$id . '_lines']) ? (int) $_GET[$id . '_lines'] : 200;
    if (!in_array($selectedLineLimit, $lineOptions, true)) {
        $selectedLineLimit = 200;
    }

    $errorsOnly = !empty($_GET[$id . '_errors_only']);
    $showDebugLines = !empty($_GET['inv2_debug_mode']);
    $search = isset($_GET[$id . '_search']) ? sanitize_text_field(wp_unslash($_GET[$id . '_search'])) : '';

    $lines  = inv2_tail_file($logPath, $selectedLineLimit);
    $filteredLines = inv2_filter_lines($lines, $errorsOnly, $search, $showDebugLines);
    $downloadUrl = inv2_log_download_url($logPath);

    echo '<div class="postbox"><div class="postbox-header"><h2 style="padding:8px 12px;margin:0;">' . esc_html($title) . '</h2></div><div class="inside">';
    echo '<p><code>' . esc_html($logPath) . '</code></p>';

    echo '<form method="get" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="inv2-monitor">';
    echo '<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">';
    echo '<label><input type="checkbox" name="' . esc_attr($id . '_errors_only') . '" value="1"' . checked($errorsOnly, true, false) . '> Näytä vain virheet</label>';
    echo '<label><input type="checkbox" name="inv2_debug_mode" value="1"' . checked($showDebugLines, true, false) . '> Debug mode (näytä debug-rivit)</label>';
    echo '</div>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
    echo '<label>Hae tekstillä <input type="text" name="' . esc_attr($id . '_search') . '" value="' . esc_attr($search) . '"></label>';
    submit_button('Suodata', 'secondary', '', false);
    echo '<a class="button" href="' . esc_url($downloadUrl) . '">Lataa loki</a>';
    echo '<button type="button" class="button inv2-copy-log" data-target="' . esc_attr($id . '-history') . '">Kopioi</button>';
    echo '</div>';

    echo '<strong>Historia (' . esc_html((string) count($filteredLines)) . ' riviä)</strong>';
    echo $filteredLines
        ? '<textarea id="' . esc_attr($id . '-history') . '" rows="14" class="large-text code">' . esc_textarea(implode("\n", $filteredLines)) . '</textarea>'
        : '<p>Ei lokeja.</p>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px;">';
    echo '<label>Näytä rivejä <select name="' . esc_attr($id . '_lines') . '">';
    foreach ($lineOptions as $option) {
        echo '<option value="' . esc_attr((string) $option) . '"' . selected($selectedLineLimit, $option, false) . '>' . esc_html((string) $option) . '</option>';
    }
    echo '</select></label>';
    echo '</div>';
    echo '</form>';

    echo '</div></div>';
}

function inv2_render_run_result(?array $runResult): void
{
    echo '<div class="postbox"><div class="postbox-header"><h2 style="padding:8px 12px;margin:0;">Suorituksen loki</h2></div><div class="inside">';
    if (!$runResult) {
        echo '<p>Ei suorituksia tässä istunnossa.</p>';
    } else {
        echo '<textarea rows="10" class="large-text code">' . esc_textarea($runResult['output']) . '</textarea>';
    }
    echo '</div></div>';
}


 // Admin page
 
function inv2_render_admin_page(): void
{
    $settings = inv2_get_settings();
    $runResult = null;

    if (isset($_POST['inv2_run_action'])) {
        check_admin_referer('inv2_run_cron_action');
        $runResult = inv2_run_script(
            $settings[$_POST['inv2_run_action'] === 'run_b' ? 'cron_b_script' : 'cron_c_script'],
            $settings['php_binary']
        );
    }

    if (isset($_POST['inv2_cleanup_action'])) {
        check_admin_referer('inv2_cleanup_logs_action');
        inv2_clear_logs_now();
    }

    echo '<div class="wrap"><h1>Inventory monitor</h1>';

    inv2_render_run_result($runResult);

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
    inv2_render_log_panel('WooCommerce-tilausten synkronointi Snipe-IT:iin', $settings['cron_b_log'], 'cron_b');
    inv2_render_log_panel('Snipe-IT-varastosynkronointi WooCommerceen', $settings['cron_c_log'], 'cron_c');
    echo '</div>';

        $logsStatusNonce = wp_create_nonce('inv2_logs_status_nonce');
    $logsStatus = inv2_get_logs_status();
    $pollScript = sprintf(
        <<<'JS'
<script>
(function () {
    var ajaxUrl = %s;
    var nonce = %s;
    var lastStatus = %s;

    function normalizeStatus(logs) {
        return JSON.stringify(logs || {});
    }

    function pollLogs() {
        if (document.visibilityState !== 'visible') {
            return;
        }

        var params = new URLSearchParams();
        params.set('action', 'inv2_logs_status');
        params.set('nonce', nonce);

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: params.toString(),
            credentials: 'same-origin'
        })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
            if (!payload || !payload.success || !payload.data || !payload.data.logs) {
                return;
            }

            var nextStatus = normalizeStatus(payload.data.logs);
            if (nextStatus !== normalizeStatus(lastStatus)) {
                window.location.reload();
            }
        })
        .catch(function () {
            // ignore polling errors
        });
    }

    setInterval(pollLogs, 15000);
}());

document.querySelectorAll('.inv2-copy-log').forEach(function (button) {
    button.addEventListener('click', function () {
        var targetId = button.getAttribute('data-target');
        var target = document.getElementById(targetId);
        if (!target) {
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(target.value);
        } else {
            target.select();
            document.execCommand('copy');
        }
    });
});
</script>
JS,
        wp_json_encode(admin_url('admin-ajax.php')),
        wp_json_encode($logsStatusNonce),
        wp_json_encode($logsStatus)
    );
    echo $pollScript;

    echo '<h2>Toiminnot</h2><div style="display:flex;gap:10px;">';

    echo '<form method="post">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_b">Aja tilausten synkronointi nyt</button>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_c">Aja varastosynkronointi nyt</button>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('inv2_cleanup_logs_action');
    echo '<button class="button" name="inv2_cleanup_action" value="1">Tyhjennä lokit nyt</button>';
    echo '</form>';

    echo '</div>';

    echo '<h2>Asetukset</h2>';
    echo '<form method="post" action="options.php">';
    settings_fields('inv2_monitor');
    echo '<table class="form-table"><tbody>';

    foreach ($settings as $key => $value) {
        echo '<tr><th>' . esc_html($key) . '</th><td>';
        echo '<input class="regular-text code" name="' . INV2_OPTION_KEY . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
        echo '</td></tr>';
    }

    echo '</tbody></table>';
    submit_button();
    echo '</form></div>';
}
