<?php
/**
 * Plugin Name: Inventory Monitor
 * Description: Näyttää Inventoryn cron-ajojen historian, virheet ja mahdollistaa Cron B/C manuaalisen ajon.
 * Version: 0.3.1
 * Author: jjarvio
 */

if (!defined('ABSPATH')) {
    exit;
}

const INV2_OPTION_KEY = 'inv2_monitor_settings';
const INV2_LAST_CLEANUP_OPTION_KEY = 'inv2_monitor_last_cleanup_ts';
const INV2_CLEANUP_HOOK = 'inv2_monitor_daily_cleanup';


 *//Activation / Deactivation
 
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


 *// Admin
 
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

    inv2_maybe_cleanup_logs();
});


 *// Settings
 
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


 *// Log cleanup
 
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


 *// Cron runner

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


 *// UI helpers
 
function inv2_tail_file(string $path, int $maxLines = 120): array
{
    if (!file_exists($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    return array_slice($lines, -$maxLines);
}

function inv2_extract_errors(array $lines): array
{
    return array_filter($lines, fn($l) => preg_match('/error|fatal|exception|failed/i', $l));
}

function inv2_render_log_panel(string $title, string $logPath): void
{
    $lines  = inv2_tail_file($logPath);
    $errors = inv2_extract_errors($lines);

    echo '<div class="postbox"><div class="postbox-header"><h2>' . esc_html($title) . '</h2></div><div class="inside">';
    echo '<p><code>' . esc_html($logPath) . '</code></p>';

    echo '<strong>Virheet</strong>';
    echo $errors
        ? '<textarea rows="6" class="large-text code">' . esc_textarea(implode("\n", $errors)) . '</textarea>'
        : '<p>Ei virheitä.</p>';

    echo '<strong>Historia</strong>';
    echo $lines
        ? '<textarea rows="10" class="large-text code">' . esc_textarea(implode("\n", $lines)) . '</textarea>'
        : '<p>Ei lokeja.</p>';

    echo '</div></div>';
}

function inv2_render_run_result(?array $runResult): void
{
    echo '<div class="postbox"><div class="postbox-header"><h2>Suorituksen loki</h2></div><div class="inside">';
    if (!$runResult) {
        echo '<p>Ei suorituksia tässä istunnossa.</p>';
    } else {
        echo '<textarea rows="10" class="large-text code">' . esc_textarea($runResult['output']) . '</textarea>';
    }
    echo '</div></div>';
}


 *// Admin page
 
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

    echo '<div class="wrap"><h1>Inventory 2.0 Monitor</h1>';

    inv2_render_run_result($runResult);

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
    inv2_render_log_panel('Cron B', $settings['cron_b_log']);
    inv2_render_log_panel('Cron C', $settings['cron_c_log']);
    echo '</div>';

    echo '<h2>Toiminnot</h2><div style="display:flex;gap:10px;">';

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