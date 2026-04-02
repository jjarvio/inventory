<?php
/**
 * Plugin Name: Inventory monitor
 * Description: Näyttää Inventoryn cron-ajojen historian, virheet ja mahdollistaa Cron B/C manuaalisen ajon turvallisesti.
 * Version: 1.2.0
 * Author: jjarvio
 */

if (!defined('ABSPATH')) exit;

const INV2_OPTION_KEY = 'inv2_monitor_settings';

/* SETTINGS */

function inv2_default_settings() {
    return [
        'php_binary'    => '/usr/local/bin/php',
        'cron_b_script' => '/home/USER/cron/cron_b_orders_to_snipe.php',
        'cron_c_script' => '/home/USER/cron/cron_c_consumables_sync.php',
        'cron_b_log'    => '/home/USER/cron/logs/cron_b_orders.log',
        'cron_c_log'    => '/home/USER/cron/logs/cron_c_consumables.log',
    ];
}

function inv2_get_settings() {
    return wp_parse_args(get_option(INV2_OPTION_KEY, []), inv2_default_settings());
}

add_action('admin_menu', function () {
    add_menu_page('Inventory Monitor', 'Inventory Monitor', 'manage_options', 'inv2-monitor', 'inv2_render', 'dashicons-database');
});

add_action('admin_init', function () {
    register_setting('inv2_monitor', INV2_OPTION_KEY);
});

/* CRON RUNNER */

function inv2_run($script, $php) {
    // Suoritetaan komento. Huomioi, että $php ja $script tulevat tietokannasta admin-käyttäjän asettamina.
    $command = escapeshellcmd("$php $script 2>&1");
    exec($command, $out, $code);
    return ['ok' => $code === 0, 'output' => implode("\n", $out)];
}

/* AJAX LOG FETCH (Turvattu) */

add_action('wp_ajax_inv2_fetch_logs', function () {
    // 1. Tietoturva: Tarkista oikeudet
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $s = inv2_get_settings();

    $read = function($file) {
        if (!file_exists($file)) return [];
        // 2. Suorituskyky: Käytetään tail-komentoa RAM-muistin säästämiseksi
        $output = shell_exec('tail -n 400 ' . escapeshellarg($file));
        if (!$output) return [];
        return explode("\n", rtrim($output));
    };

    wp_send_json([
        'b' => $read($s['cron_b_log']),
        'c' => $read($s['cron_c_log'])
    ]);
});

/* AJAX CLEAR LOG (Turvattu) */

add_action('wp_ajax_inv2_clear_log', function () {
    // 1. Tietoturva: Tarkista oikeudet
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    // 2. Tietoturva: CSRF-suojaus (Nonce)
    if (!check_ajax_referer('inv2_ajax_action', 'nonce', false)) {
        wp_send_json_error('Invalid nonce');
    }

    $type = $_POST['type'] ?? '';
    $s = inv2_get_settings();

    $map = [
        'b' => $s['cron_b_log'],
        'c' => $s['cron_c_log']
    ];

    if (!isset($map[$type])) {
        wp_send_json_error('Invalid type');
    }

    $file = $map[$type];

    if (!file_exists($file) || !is_writable($file)) {
        wp_send_json_error('Ei voi kirjoittaa lokiin');
    }

    file_put_contents($file, '');

    wp_send_json_success();
});

/* DOWNLOAD (Turvattu) */

add_action('admin_init', function () {
    if (!isset($_GET['inv2_download'])) return;

    // 1. Tietoturva: Varmista, että kyseessä on ylläpitäjä
    if (!current_user_can('manage_options')) {
        wp_die('Luvaton pääsy');
    }

    $file = urldecode($_GET['inv2_download']);
    $s = inv2_get_settings();

    // 2. Tietoturva: Estetään "Directory Traversal" sallimalla VAIN asetetut lokit
    if ($file !== $s['cron_b_log'] && $file !== $s['cron_c_log']) {
        wp_die('Tiedoston lataus ei ole sallittu.');
    }

    if (!file_exists($file)) wp_die('Loki puuttuu');

    header('Content-Type:text/plain');
    header('Content-Disposition:attachment; filename="'.basename($file).'"');
    readfile($file);
    exit;
});

/* UI */

function inv2_render() {
    $s = inv2_get_settings();
    $res = null;

    // Tietoturva: CSRF-suojaus manuaalisiin ajoihin
    if ((isset($_POST['run_b']) || isset($_POST['run_c'])) && check_admin_referer('inv2_run_scripts', 'inv2_nonce')) {
        if (isset($_POST['run_b'])) $res = inv2_run($s['cron_b_script'], $s['php_binary']);
        if (isset($_POST['run_c'])) $res = inv2_run($s['cron_c_script'], $s['php_binary']);
    }

    echo '<div class="wrap inv2-wrap">';
    echo '<h1 style="margin-bottom:20px;">Inventory Monitor</h1>';

    /* RESULT */
    if ($res) {
        echo '<div class="inv2-alert '.($res['ok'] ? 'ok' : 'err').'">';
        echo '<strong>'.($res['ok'] ? 'Suoritus onnistui' : 'Suoritus epäonnistui').'</strong><br>';
        echo nl2br(esc_html($res['output']));
        echo '</div>';
    }

    /* LOGS */
    echo '<div class="inv2-grid">';
    echo inv2_log_card("Tilaukset → Snipe-IT", $s['cron_b_log'], "log_b");
    echo inv2_log_card("Snipe-IT → Verkkokauppa", $s['cron_c_log'], "log_c");
    echo '</div>';

    /* TOOLBAR */
    echo '<h3 style="margin-bottom:6px;">Toiminnot ja suodatus</h3>';
    echo '<div class="inv2-toolbar" style="margin:20px 0;">';
    
    // Lomake, jossa Nonce-kenttä turvaamassa POST-pyynnöt
    echo '<form method="post" style="display:flex; gap:10px;">';
    wp_nonce_field('inv2_run_scripts', 'inv2_nonce');
    echo '<button class="button button-primary" name="run_b">Aja tilausten synkronointi nyt</button>';
    echo '<button class="button button-primary" name="run_c">Aja tuotteet verkkokauppaan</button>';
    echo '</form>';

    echo '<input type="text" id="inv2-search" placeholder="Hae lokista...">';
    echo '<label><input type="checkbox" id="inv2-errors"> Vain virheet</label>';
    echo '</div>';

    /* SETTINGS */
    echo '<div class="inv2-card" style="margin-top:30px;">';
    echo '<h2>Asetukset</h2>';
    echo '<form method="post" action="options.php">';
    settings_fields('inv2_monitor');

    foreach ($s as $k => $v) {
        echo '<p><strong>'.$k.'</strong><br>';
        echo '<input class="regular-text code" name="'.INV2_OPTION_KEY.'['.$k.']" value="'.esc_attr($v).'"></p>';
    }

    submit_button();
    echo '</form></div>';

    /* STYLES */
    echo '<style>
    .inv2-toolbar {display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px;align-items:center;}
    .inv2-grid {display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .inv2-card {background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:12px;}
    .inv2-log {
        background:#0d1117; 
        color:#e6edf3; 
        padding:10px; 
        height:320px; 
        overflow-y:auto; 
        font-family:monospace; 
        border-radius:6px; 
        white-space: pre-wrap; 
        font-size: 13px;
        /* Nämä kaksi riviä tekevät taian: */
        display: flex;
        flex-direction: column-reverse;
    }
    .inv2-alert {padding:12px;border-radius:6px;margin-bottom:15px;}
    .inv2-alert.ok {background:#d1e7dd;}
    .inv2-alert.err {background:#f8d7da;}
    </style>';

    /* Ladataan nonce JavaScriptiä varten */
    $ajax_nonce = wp_create_nonce('inv2_ajax_action');

    /* JS */
    echo '<script>
    const inv2_ajax_nonce = "'.esc_js($ajax_nonce).'";
    const isError = l => /error|fatal|exception|failed/i.test(l);

    function render(id, lines) {
        const search = document.getElementById("inv2-search").value.toLowerCase();
        const errors = document.getElementById("inv2-errors").checked;
        const el = document.getElementById(id);

        let html = "";
        const filteredLines = [];

        // 1. Suodatetaan rivit
        lines.forEach(l => {
            if (search && !l.toLowerCase().includes(search)) return;
            if (errors && !isError(l)) return;
            filteredLines.push(l);
        });

        // 2. KÄÄNNETÄÄN JÄRJESTYS YLÖSALAISIN (uusin rivi tulee DOM:n alkuun)
        filteredLines.reverse().forEach(l => {
            let color = "#e6edf3";
            if (isError(l)) color = "#ff6b6b";
            if (/debug/i.test(l)) color = "#888";

            const safeText = l.replace(/</g, "&lt;").replace(/>/g, "&gt;");
            html += `<div style="color:${color}">${safeText}</div>`;
        });

        // Estetään turha päivittäminen ja välkkyminen, jos sisältö ei ole muuttunut
        if (el.innerHTML !== html) {
            el.innerHTML = html;
            // Mitään rullauskomentoja ei enää tarvita! CSS hoitaa sen automaattisesti.
        }
    }

    function loadLogs() {
        fetch(ajaxurl + "?action=inv2_fetch_logs")
        .then(r => r.json())
        .then(d => {
            if(d.success === false) return;
            render("log_b", d.b || []);
            render("log_c", d.c || []);
        }).catch(err => console.error("Fetch error:", err));
    }

    // Päivitetään lokit 5 sekunnin välein
    setInterval(loadLogs, 5000);
    loadLogs();

    document.getElementById("inv2-search").oninput = loadLogs;
    document.getElementById("inv2-errors").onchange = loadLogs;

    document.querySelectorAll(".inv2-copy").forEach(btn => {
        btn.onclick = () => {
            const el = document.getElementById(btn.dataset.target);
            // Koska DOM on nurinpäin, pitää teksti kääntää oikein päin ennen kopiointia
            const linesToCopy = Array.from(el.children)
                                     .map(child => child.innerText)
                                     .reverse()
                                     .join("\n");
            
            navigator.clipboard.writeText(linesToCopy).then(() => {
                const orig = btn.innerText;
                btn.innerText = "Kopioitu!";
                setTimeout(() => btn.innerText = orig, 2000);
            });
        };
    });

    document.querySelectorAll(".inv2-clear").forEach(btn => {
        btn.onclick = () => {
            if (!confirm("Haluatko varmasti tyhjentää lokin?")) return;

            const type = btn.dataset.type;

            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type":"application/x-www-form-urlencoded"},
                body: "action=inv2_clear_log&nonce=" + inv2_ajax_nonce + "&type=" + type
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    loadLogs();
                } else {
                    alert("Virhe: " + res.data);
                }
            });
        };
    });
    </script>';

    echo '</div>';
}

/* LOG CARD */

function inv2_log_card($title, $path, $id) {
    $download_url = admin_url('admin.php?page=inv2-monitor&inv2_download='.urlencode($path));

    return '
    <div class="inv2-card">
        <h3>'.$title.'</h3>
        <div style="margin-bottom:8px;">
            <a class="button" href="'.esc_url($download_url).'">Lataa</a>
            <button class="button inv2-copy" data-target="'.$id.'">Kopioi näkymä</button>
            <button class="button inv2-clear" data-type="'.($id === 'log_b' ? 'b' : 'c').'">Tyhjennä loki</button>
        </div>
        <div id="'.$id.'" class="inv2-log">Ladataan lokia...</div>
    </div>';
}