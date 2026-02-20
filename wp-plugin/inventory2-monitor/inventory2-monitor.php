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

register_activation_hook(__FILE__, function (): void {
    if (!wp_next_scheduled(INV2_CLEANUP_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', INV2_CLEANUP_HOOK);
    }
});

register_deactivation_hook(__FILE__, function (): void {
    $timestamp = wp_next_scheduled(INV2_CLEANUP_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, INV2_CLEANUP_HOOK);
    }
});

add_action(INV2_CLEANUP_HOOK, 'inv2_maybe_cleanup_logs');

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
    // Varmistus: jos wp-cron ei laukea, siivotaan adminissa harvakseltaan
    inv2_maybe_cleanup_logs();
});

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

    // Varmista järkevä arvo
    $settings['log_retention_days'] = max(1, (int) ($settings['log_retention_days'] ?? 7));

    return $settings;
}

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

function inv2_run_script(string $scriptPath, string $phpBinary): array
{
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'output' => 'Scriptiä ei löydy: ' . $scriptPath];
    }
    if (!is_executable($phpBinary)) {
        return ['ok' => false, 'output' => 'PHP-binääri ei ole ajettava: ' . $phpBinary];
    }

    exec(escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out, $code);

    return [
        'ok' => $code === 0,
        'output' => implode("\n", $out),
        'exit_code' => $code,
    ];
}

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

    echo '<form method="post" style="display:flex;gap:10px;margin-bottom:16px;">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_b">Aja Cron B</button>';
    echo '<button class="button" name="inv2_run_action" value="run_c">Aja Cron C</button>';
    echo '</form>';

    echo '<form method="post">';
    wp_nonce_field('inv2_cleanup_logs_action');
    echo '<button class="button button-secondary" name="inv2_cleanup_action" value="1">Tyhjennä lokit nyt</button>';
    echo '</form>';

    if ($runResult) {
        echo '<pre>' . esc_html($runResult['output']) . '</pre>';
    }
    if ($cleanupResult) {
        echo '<pre>' . esc_html(print_r($cleanupResult, true)) . '</pre>';
    }

    echo '</div>';
}