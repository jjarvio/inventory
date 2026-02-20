<?php
/**
 * Plugin Name: Inventory 2.0 Monitor
 * Description: Näyttää Inventory 2.0 cron-ajojen historian, virheet ja mahdollistaa Cron B/C manuaalisen ajon.
 * Version: 0.3.0
 * Author: Inventory 2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

const INV2_OPTION_KEY = 'inv2_monitor_settings';
const INV2_LAST_CLEANUP_OPTION_KEY = 'inv2_monitor_last_cleanup_ts';
const INV2_CLEANUP_HOOK = 'inv2_monitor_daily_cleanup';

/* =======================
 * Activation / Deactivation
 * ======================= */
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

/* =======================
 * Admin
 * ======================= */
add_action('admin_menu', function (): void {
    add_menu_page(
        'Inventory 2.0 Monitor',
        'Inventory 2.0',
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

    // Fallback: jos wp-cron ei laukea luotettavasti
    inv2_maybe_cleanup_logs();
});

/* =======================
 * Settings
 * ======================= */
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

/* =======================
 * Log cleanup
 * ======================= */
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
        if (is_string($path) && $path !== '' && file_exists($path) && is_writable($path)) {
            file_put_contents($path, '');
        }
    }

    update_option(INV2_LAST_CLEANUP_OPTION_KEY, time(), false);
}

function inv2_clear_logs_now(): array
{
    $settings = inv2_get_settings();
    $cleared = 0;
    $errors = [];

    foreach (['cron_b_log', 'cron_c_log'] as $key) {
        $path = $settings[$key] ?? '';
        if (!is_string($path) || $path === '') {
            continue;
        }
        if (!file_exists($path)) {
            $errors[] = "Lokia ei löytynyt: {$path}";
            continue;
        }
        if (!is_writable($path)) {
            $errors[] = "Loki ei ole kirjoitettava: {$path}";
            continue;
        }
        if (file_put_contents($path, '') === false) {
            $errors[] = "Lokin tyhjennys epäonnistui: {$path}";
            continue;
        }
        $cleared++;
    }

    update_option(INV2_LAST_CLEANUP_OPTION_KEY, time(), false);

    return [
        'ok'      => empty($errors),
        'cleared' => $cleared,
        'errors'  => $errors,
    ];
}

/* =======================
 * Cron runner
 * ======================= */
function inv2_run_script(string $scriptPath, string $phpBinary): array
{
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'output' => 'Scriptiä ei löydy: ' . $scriptPath];
    }
    if (!is_executable($phpBinary)) {
        return ['ok' => false, 'output' => 'PHP-binääri ei ole ajettava: ' . $phpBinary];
    }

    exec(
        escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' 2>&1',
        $out,
        $code
    );

    return [
        'ok'        => $code === 0,
        'output'    => implode("\n", $out),
        'exit_code' => $code,
    ];
}

/* =======================
 * UI helpers
 * ======================= */
function inv2_tail_file(string $path, int $maxLines = 120): array
{
    if (!file_exists($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    return is_array($lines) ? array_slice($lines, -$maxLines) : [];
}

function inv2_extract_errors(array $lines): array
{
    return array_slice(
        array_filter($lines, fn($l) => preg_match('/\b(error|fatal|exception|failed|missing)\b/i', $l)),
        -40
    );
}

function inv2_render_log_panel(string $title, string $logPath): void
{
    $lines  = inv2_tail_file($logPath);
    $errors = inv2_extract_errors($lines);

    echo '<div class="postbox">';
    echo '<div class="postbox-header"><h2 class="hndle">' . esc_html($title) . '</h2></div>';
    echo '<div class="inside">';
    echo '<p><strong>Lokipolku:</strong> <code>' . esc_html($logPath) . '</code></p>';

    echo '<p><strong>Virheet</strong></p>';
    echo $errors
        ? '<textarea readonly rows="6" class="large-text code">' . esc_textarea(implode("\n", $errors)) . '</textarea>'
        : '<p>Ei virheitä.</p>';

    echo '<p><strong>Historia</strong></p>';
    echo $lines
        ? '<textarea readonly rows="10" class="large-text code">' . esc_textarea(implode("\n", $lines)) . '</textarea>'
        : '<p>Ei lokeja.</p>';
    echo '</div>';
    echo '</div>';
}

/* =======================
 * Admin page
 * ======================= */
function inv2_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Ei oikeuksia');
    }

    $settings = inv2_get_settings();
    $runResult = null;
    $cleanupResult = null;

    if (isset($_POST['inv2_run_action'])) {
        check_admin_referer('inv2_run_cron_action');
        $runResult = inv2_run_script(
            $settings[$_POST['inv2_run_action'] === 'run_b' ? 'cron_b_script' : 'cron_c_script'],
            $settings['php_binary']
        );
    }

    if (isset($_POST['inv2_cleanup_action'])) {
        check_admin_referer('inv2_cleanup_logs_action');
        $cleanupResult = inv2_clear_logs_now();
    }

    echo '<div class="wrap">';
    echo '<h1>Inventory 2.0 Monitor</h1>';
    echo '<p>Tällä sivulla voit hallita asetuksia, ajaa Cron B/C käsin sekä tarkastella ajohistoriaa ja virheitä.</p>';

    echo '<h2 class="title">Toiminnot</h2>';
    echo '<div style="margin:12px 0;display:flex;gap:10px;flex-wrap:wrap;">';
    echo '<form method="post">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_b">Aja Cron B nyt</button>';
    echo '</form>';
    echo '<form method="post">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_c">Aja Cron C nyt</button>';
    echo '</form>';
    echo '<form method="post">';
    wp_nonce_field('inv2_cleanup_logs_action');
    echo '<button class="button" name="inv2_cleanup_action" value="1">Tyhjennä lokit nyt</button>';
    echo '</form>';
    echo '</div>';

    echo '<hr style="margin:18px 0;">';
    echo '<h2 class="title">Asetukset</h2>';
    echo '<form method="post" action="options.php">';
    settings_fields('inv2_monitor');
    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';
    echo '<tr><th scope="row"><label for="inv2_php_binary">PHP-binääri</label></th><td>';
    echo '<input name="' . esc_attr(INV2_OPTION_KEY) . '[php_binary]" id="inv2_php_binary" type="text" class="regular-text code" value="' . esc_attr($settings['php_binary']) . '">';
    echo '<p class="description">Esim. /usr/local/bin/php</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="inv2_cron_b_script">Cron B scriptipolku</label></th><td>';
    echo '<input name="' . esc_attr(INV2_OPTION_KEY) . '[cron_b_script]" id="inv2_cron_b_script" type="text" class="regular-text code" value="' . esc_attr($settings['cron_b_script']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="inv2_cron_c_script">Cron C scriptipolku</label></th><td>';
    echo '<input name="' . esc_attr(INV2_OPTION_KEY) . '[cron_c_script]" id="inv2_cron_c_script" type="text" class="regular-text code" value="' . esc_attr($settings['cron_c_script']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="inv2_cron_b_log">Cron B loki</label></th><td>';
    echo '<input name="' . esc_attr(INV2_OPTION_KEY) . '[cron_b_log]" id="inv2_cron_b_log" type="text" class="regular-text code" value="' . esc_attr($settings['cron_b_log']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="inv2_cron_c_log">Cron C loki</label></th><td>';
    echo '<input name="' . esc_attr(INV2_OPTION_KEY) . '[cron_c_log]" id="inv2_cron_c_log" type="text" class="regular-text code" value="' . esc_attr($settings['cron_c_log']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="inv2_log_retention_days">Lokien säilytys (päivää)</label></th><td>';
    echo '<input name="' . esc_attr(INV2_OPTION_KEY) . '[log_retention_days]" id="inv2_log_retention_days" type="number" min="1" class="small-text" value="' . esc_attr((string) $settings['log_retention_days']) . '">';
    echo '<p class="description">Tyhjennetään automaattisesti tämän välein.</p>';
    echo '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    submit_button('Tallenna asetukset');
    echo '</form>';

    echo '<hr style="margin:18px 0;">';
    echo '<h2 class="title">Lokit</h2>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:16px;margin-top:12px;">';
    inv2_render_log_panel('Cron B', $settings['cron_b_log']);
    inv2_render_log_panel('Cron C', $settings['cron_c_log']);
    echo '</div>';

    if ($runResult) {
        echo '<pre>' . esc_html($runResult['output']) . '</pre>';
    }
    if ($cleanupResult) {
        echo '<pre>' . esc_html(print_r($cleanupResult, true)) . '</pre>';
    }

    echo '</div>';
}
