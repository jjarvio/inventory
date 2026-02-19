<?php
/**
 * Plugin Name: Inventory 2.0 Monitor
 * Description: Näyttää Inventory 2.0 cron-ajojen historian, virheet ja mahdollistaa Cron B/C manuaalisen ajon.
 * Version: 0.1.0
 * Author: Inventory 2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

const INV2_OPTION_KEY = 'inv2_monitor_settings';

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
});

function inv2_default_settings(): array
{
    return [
        'php_binary' => '/usr/local/bin/php',
        'cron_b_script' => '/home/USER/cron/cron_b_orders_to_snipe.php',
        'cron_c_script' => '/home/USER/cron/cron_c_consumables_sync.php',
        'cron_b_log' => '/home/USER/cron/logs/cron_b_orders.log',
        'cron_c_log' => '/home/USER/cron/logs/cron_c_consumables.log',
    ];
}

function inv2_get_settings(): array
{
    $saved = get_option(INV2_OPTION_KEY, []);

    return wp_parse_args(is_array($saved) ? $saved : [], inv2_default_settings());
}

function inv2_run_script(string $scriptPath, string $phpBinary): array
{
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'output' => 'Scriptiä ei löydy: ' . $scriptPath];
    }

    if (!is_executable($phpBinary)) {
        return ['ok' => false, 'output' => 'PHP-binääri ei ole ajettava: ' . $phpBinary];
    }

    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [
        'ok' => $exitCode === 0,
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
    ];
}

function inv2_tail_file(string $path, int $maxLines = 120): array
{
    if (!file_exists($path) || !is_readable($path)) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }

    return array_slice($lines, -$maxLines);
}

function inv2_extract_errors(array $lines): array
{
    $errors = [];
    foreach ($lines as $line) {
        if (preg_match('/\b(error|fatal|exception|failed|missing)\b/i', $line)) {
            $errors[] = $line;
        }
    }

    return array_slice($errors, -40);
}

function inv2_render_log_panel(string $title, string $logPath): void
{
    $lines = inv2_tail_file($logPath);
    $errors = inv2_extract_errors($lines);

    echo '<h2>' . esc_html($title) . '</h2>';
    echo '<p><code>' . esc_html($logPath) . '</code></p>';

    echo '<h3>Virheet (uusimmat)</h3>';
    if (!$errors) {
        echo '<p>Ei havaittuja virherivejä valitusta lokin osasta.</p>';
    } else {
        echo '<textarea readonly rows="8" style="width:100%;font-family:monospace;">' . esc_textarea(implode("\n", $errors)) . '</textarea>';
    }

    echo '<h3>Historia (viimeisimmät lokirivit)</h3>';
    if (!$lines) {
        echo '<p>Lokitiedostoa ei löytynyt tai sitä ei voi lukea.</p>';
    } else {
        echo '<textarea readonly rows="14" style="width:100%;font-family:monospace;">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
    }
}

function inv2_render_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Ei oikeuksia');
    }

    $settings = inv2_get_settings();
    $runResult = null;

    if (isset($_POST['inv2_run_action'])) {
        check_admin_referer('inv2_run_cron_action');
        $action = sanitize_text_field(wp_unslash($_POST['inv2_run_action']));

        if ($action === 'run_b') {
            $runResult = inv2_run_script($settings['cron_b_script'], $settings['php_binary']);
        }

        if ($action === 'run_c') {
            $runResult = inv2_run_script($settings['cron_c_script'], $settings['php_binary']);
        }
    }

    echo '<div class="wrap">';
    echo '<h1>Inventory 2.0 Monitor</h1>';
    echo '<p>Tällä sivulla voit ajaa Cron B/C käsin sekä tarkastella ajohistoriaa ja virheitä.</p>';

    echo '<h2>Aja cron käsin</h2>';
    echo '<form method="post" style="display:flex;gap:10px;">';
    wp_nonce_field('inv2_run_cron_action');
    echo '<button class="button button-primary" name="inv2_run_action" value="run_b">Aja Cron B nyt</button>';
    echo '<button class="button" name="inv2_run_action" value="run_c">Aja Cron C nyt</button>';
    echo '</form>';

    if (is_array($runResult)) {
        $class = $runResult['ok'] ? 'notice-success' : 'notice-error';
        $status = $runResult['ok'] ? 'Ajo onnistui' : 'Ajo epäonnistui';

        echo '<div class="notice ' . esc_attr($class) . '" style="padding:8px 12px;margin-top:12px;">';
        echo '<p><strong>' . esc_html($status) . '</strong></p>';
        echo '<textarea readonly rows="8" style="width:100%;font-family:monospace;">' . esc_textarea($runResult['output'] ?? '') . '</textarea>';
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
    ];

    echo '<table class="form-table"><tbody>';
    foreach ($fields as $key => $label) {
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
        echo '<td><input name="' . esc_attr(INV2_OPTION_KEY . '[' . $key . ']') . '" id="' . esc_attr($key) . '" type="text" class="regular-text code" value="' . esc_attr($settings[$key] ?? '') . '" /></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    submit_button('Tallenna asetukset');
    echo '</form>';

    echo '<hr />';
    inv2_render_log_panel('Cron B', $settings['cron_b_log']);
    echo '<hr />';
    inv2_render_log_panel('Cron C', $settings['cron_c_log']);
    echo '</div>';
}
