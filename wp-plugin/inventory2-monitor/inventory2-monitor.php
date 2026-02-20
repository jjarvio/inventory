<?php
/**
 * Plugin Name: Inventory 2.0 Monitor
 * Description: Näyttää Inventory 2.0 cron-ajojen historian, virheet ja mahdollistaa Cron B/C manuaalisen ajon.
 * Version: 0.2.0
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
    register_setting('inv2_monitor', INV2_OPTION_KEY);
    // Fallback: jos wp-cron ei laukea luotettavasti
    inv2_maybe_cleanup_logs();
});

/* =======================
 * Settings
 * ======================= */
function inv2_default_settings(): array
{
    return [
        'php_binary' => '/usr/local/bin/php',
        'cron_b_script' => '/home/USER/cron/cron_b_orders_to_snipe.php',
        'cron_c_script' => '/home/USER/cron/cron_c_consumables_sync.php',
        'cron_b_log' => '/home/USER/cron/logs/cron_b_orders.log',
        'cron_c_log' => '/home/USER/cron/logs/cron_c_consumables.log',
        'log_retention_days' => 7,
    ];
}

function inv2_get_settings(): array
{
    $saved = get_option(INV2_OPTION_KEY, []);
    $settings = wp_parse_args(is_array($saved) ? $saved : [], inv2_default_settings());

    $settings['log_retention_days'] = max(1, (int) ($settings['log_retention_days'] ?? 7));
    return $settings;
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
        'ok' => empty($errors),
        'cleared' => $cleared,
        'errors' => $errors,
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
        'ok' => $code === 0,
        'output' => implode("\n", $out),
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
    $lines = inv2_tail_file($logPath);
    $errors = inv2_extract_errors($lines);

    echo '<h2>' . esc_html($title) . '</h2>';
    echo '<p><code>' . esc_html($logPath) . '</code></p>';

    echo '<h3>Virheet</h3>';
    echo $errors
        ? '<textarea readonly rows="6" style="width:100%;font-family:monospace;">' . esc_textarea(implode("\n", $errors)) . '</textarea>'
        : '<p>Ei virheitä.</p>';

    echo '<h3>Historia</h3>';
    echo $lines
        ? '<textarea readonly rows="10" style="width:100%;font-family:monospace;">' . esc_textarea(implode("\n", $lines)) . '</textarea>'
        : '<p>Ei lokeja.</p>';
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
    echo '<style>
        .inv2-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px;}
        .inv2-card{border:1px solid #dcdcde;background:#fff;padding:12px;}
        .inv2-actions{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;}
        @media (max-width:960px){.inv2-grid{grid-template-columns:1fr;}}
    </style>';

    echo '<h1>Inventory 2.0 Monitor</h1>';

    echo '<div class="inv2-grid">';
    echo '<div class="inv2-card">';
    inv2_render_log_panel('Cron B', $settings['cron_b_log']);
    echo '</div>';
    echo '<div class="inv2-card">';
    inv2_render_log_panel('Cron C', $settings['cron_c_log']);
    echo '</div>';
    echo '</div>';

    echo '<div class="inv2-actions">';
    echo '<form method="post">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_b">Aja Cron B nyt</button>';
    echo '</form>';
    echo '<form method="post">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button" name="inv2_run_action" value="run_c">Aja Cron C nyt</button>';
    echo '</form>';
    echo '<form method="post">';
    wp_nonce_field('inv2_cleanup_logs_action');
    echo '<button class="button button-secondary" name="inv2_cleanup_action" value="1">Tyhjennä lokit nyt</button>';
    echo '</form>';
    echo '</div>';

    if (is_array($runResult)) {
        $class = $runResult['ok'] ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '" style="padding:8px 12px;">';
        echo '<textarea readonly rows="6" style="width:100%;font-family:monospace;">'
            . esc_textarea($runResult['output'] ?? '') . '</textarea>';
        echo '</div>';
    }

    if (is_array($cleanupResult)) {
        $class = $cleanupResult['ok'] ? 'notice-success' : 'notice-warning';
        echo '<div class="notice ' . esc_attr($class) . '" style="padding:8px 12px;">';
        echo '<p>Tyhjennettyjä lokeja: ' . esc_html((string) ($cleanupResult['cleared'] ?? 0)) . '</p>';
        if (!empty($cleanupResult['errors'])) {
            echo '<textarea readonly rows="4" style="width:100%;font-family:monospace;">'
                . esc_textarea(implode("\n", $cleanupResult['errors'])) . '</textarea>';
        }
        echo '</div>';
    }

    echo '<hr />';
    echo '<h2>Asetukset</h2>';
    echo '<form method="post" action="options.php">';
    settings_fields('inv2_monitor');

    $fields = [
        'php_binary' => 'PHP-binääri',
        'cron_b_script' => 'Cron B scriptin polku',
        'cron_c_script' => 'Cron C scriptin polku',
        'cron_b_log' => 'Cron B loki',
        'cron_c_log' => 'Cron C loki',
        'log_retention_days' => 'Lokien tyhjennysväli (päivää)',
    ];

    echo '<table class="form-table"><tbody>';
    foreach ($fields as $key => $label) {
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input name="' . esc_attr(INV2_OPTION_KEY . '[' . $key . ']') . '" id="' . esc_attr($key)
            . '" type="text" class="regular-text code" value="'
            . esc_attr((string) ($settings[$key] ?? '')) . '" /></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    submit_button('Tallenna asetukset');
    echo '</form>';

    echo '<p><em>Lokit tyhjennetään automaattisesti asetetun päivän välein (oletus 7).</em></p>';
    echo '</div>';
}