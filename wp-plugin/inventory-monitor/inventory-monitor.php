<?php
/**
 * Plugin Name: Inventory monitor
 * Description: Näyttää cron-ajojen älykkään yhteenvedon. Raakalokit, suodattimet ja asetukset korteissa.
 * Version: 1.4.0
 * Author: jjarvio
 */

if (!defined('ABSPATH')) exit;

const INV2_OPTION_KEY = 'inv2_monitor_settings';

/* SETTINGS */

function inv2_default_settings() {
    return [
        'php_binary'       => '/usr/local/bin/php',
        'cron_b_script'    => '/home/USER/cron/cron_b_orders_to_snipe.php',
        'cron_c_script'    => '/home/USER/cron/cron_c_consumables_sync.php',
        'cron_b_log'       => '/home/USER/cron/logs/cron_b_orders.log',
        'cron_c_log'       => '/home/USER/cron/logs/cron_c_consumables.log',
        'auto_clear_days'  => '7',
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

/* AUTOMATIC LOG CLEANUP (WP-CRON) */

// Varmistetaan, että päivittäinen siivousajastin on käynnissä
add_action('init', function() {
    if (!wp_next_scheduled('inv2_auto_clear_logs_event')) {
        wp_schedule_event(time(), 'daily', 'inv2_auto_clear_logs_event');
    }
});

add_action('inv2_auto_clear_logs_event', function() {
    $s = inv2_get_settings();
    $days = (int)($s['auto_clear_days'] ?? 0);

    // Jos asetus on 0, automatiikka on pois päältä
    if ($days > 0) {
        $last_clear = (int)get_option('inv2_last_auto_clear', 0);
        
        // Tarkistetaan onko kulunut X päivää viimeisestä tyhjennyksestä
        if (time() - $last_clear >= $days * DAY_IN_SECONDS) {
            
            if (file_exists($s['cron_b_log']) && is_writable($s['cron_b_log'])) {
                file_put_contents($s['cron_b_log'], '');
            }
            if (file_exists($s['cron_c_log']) && is_writable($s['cron_c_log'])) {
                file_put_contents($s['cron_c_log'], '');
            }
            
            // Päivitetään viimeisimmän tyhjennyksen aikaleima tietokantaan
            update_option('inv2_last_auto_clear', time());
        }
    }
});


/* AJAX RUN SCRIPT (Tausta-ajo) */

add_action('wp_ajax_inv2_run_script', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    if (!check_ajax_referer('inv2_ajax_action', 'nonce', false)) wp_send_json_error('Invalid nonce');

    $type = $_POST['type'] ?? '';
    $s = inv2_get_settings();
    $script = ($type === 'b') ? $s['cron_b_script'] : (($type === 'c') ? $s['cron_c_script'] : null);

    if (!$script || !file_exists($script)) wp_send_json_error('Skriptiä ei löydy.');

    $php_safe = escapeshellarg($s['php_binary']);
    $script_safe = escapeshellarg($script);

    exec("$php_safe $script_safe > /dev/null 2>&1 &");
    wp_send_json_success();
});

/* AJAX LOG FETCH ( Lokien haku ) */

add_action('wp_ajax_inv2_fetch_logs', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $limit_val = isset($_GET['limit']) ? $_GET['limit'] : '100';
    $is_all = ($limit_val === 'all');
    $limit = $is_all ? 'all' : max(1, (int)$limit_val);
    
    $s = inv2_get_settings();

    $read = function($file) use ($limit, $is_all) {
        if (!file_exists($file)) return [];
        
        $cmd = $is_all ? 'cat ' . escapeshellarg($file) : 'tail -n ' . $limit . ' ' . escapeshellarg($file);
        
        $output = shell_exec($cmd);
        if (!$output) return [];
        return explode("\n", rtrim($output));
    };

    wp_send_json([
        'b' => $read($s['cron_b_log']),
        'c' => $read($s['cron_c_log'])
    ]);
});

/* AJAX CLEAR LOG & DOWNLOAD */

add_action('wp_ajax_inv2_clear_log', function () {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    if (!check_ajax_referer('inv2_ajax_action', 'nonce', false)) wp_send_json_error('Invalid nonce');
    $type = $_POST['type'] ?? '';
    $s = inv2_get_settings();
    $map = ['b' => $s['cron_b_log'], 'c' => $s['cron_c_log']];
    if (!isset($map[$type])) wp_send_json_error('Invalid type');
    $file = $map[$type];
    if (!file_exists($file) || !is_writable($file)) wp_send_json_error('Ei voi kirjoittaa lokiin');
    file_put_contents($file, '');
    
    // Nollataan myös automaattisen tyhjennyksen ajastin, koska tyhjennettiin manuaalisesti
    update_option('inv2_last_auto_clear', time());
    
    wp_send_json_success();
});

add_action('admin_init', function () {
    if (!isset($_GET['inv2_download'])) return;
    if (!current_user_can('manage_options')) wp_die('Luvaton pääsy');
    $file = urldecode($_GET['inv2_download']);
    $s = inv2_get_settings();
    if ($file !== $s['cron_b_log'] && $file !== $s['cron_c_log']) wp_die('Kielletty polku.');
    header('Content-Type:text/plain');
    header('Content-Disposition:attachment; filename="'.basename($file).'"');
    readfile($file);
    exit;
});

/* UI RENDER */

function inv2_render() {
    $s = inv2_get_settings();

    echo '<div class="wrap inv2-wrap">';
    echo '<h1 style="margin-bottom:20px;">Inventory Monitor</h1>';

    /* 1. LATEST RUNS JA PIKATOIMINNOT (YHTENÄINEN KORTTI AINA NÄKYVISSÄ) */
    echo '<div class="inv2-card" style="margin-bottom: 25px;">';
    
    // Yhteenvedot
    echo '<div class="inv2-grid">';
    echo '<div id="latest_b" class="inv2-log-column" style="padding-left: 12px; border-left: 4px solid #0ea5e9;">Ladataan viimeisimmän ajon tietoja...</div>';
    echo '<div id="latest_c" class="inv2-log-column" style="padding-left: 12px; border-left: 4px solid #0ea5e9;">Ladataan viimeisimmän ajon tietoja...</div>';
    echo '</div>';

    // Erotinviiva
    echo '<hr style="margin: 20px 0 15px 0; border: 0; border-top: 1px solid #e2e4e7;">';

    // Toimintonapit
    echo '<div class="inv2-toolbar">';
    echo '<button class="button button-primary inv2-run-btn" data-type="b">Aja tilaukset nyt</button>';
    echo '<button class="button button-primary inv2-run-btn" data-type="c">Hae tuotteet nyt</button>';
    echo '</div>';
    
    echo '</div>'; // Sulkee inv2-card

    /* 2. MAIN LOGS JA SUODATUS (YHTENÄINEN KORTTI) */
    echo '<div class="inv2-card" style="padding: 0; margin-bottom: 25px;">';
    
    echo '<div class="inv2-accordion-header" data-target="inv2-logs-container">';
    echo '<h2 style="margin:0; font-size:16px;">Tarkastele koko raakalokia <span class="inv2-arrow">▼</span></h2>';
    echo '</div>';
    
    echo '<div id="inv2-logs-container" style="display: none; padding: 0 15px 15px 15px; border-top: 1px solid #e2e4e7;">';
    
    echo '<div class="inv2-grid" style="margin-top: 15px;">';
    echo inv2_log_card("Koko Loki: Tilaukset → Snipe-IT", $s['cron_b_log'], "log_b");
    echo inv2_log_card("Koko Loki: Snipe-IT → Verkkokauppa", $s['cron_c_log'], "log_c");
    echo '</div>';

    echo '<hr style="margin: 20px 0 15px 0; border: 0; border-top: 1px solid #e2e4e7;">';

    echo '<div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">';
    echo '<strong style="margin-right: 5px;">Suodatus:</strong>';
    echo '<span>Rivimäärä: <select id="inv2-limit" style="margin-left: 5px;">
            <option value="50">50</option>
            <option value="100" selected>100</option>
            <option value="200">200</option>
            <option value="500">500</option>
            <option value="1000">1000</option>
            <option value="all">Kaikki</option>
          </select></span>';
    echo '<input type="text" id="inv2-search" placeholder="Hae raakalokista...">';
    echo '<label><input type="checkbox" id="inv2-errors"> Vain virheet</label>';
    echo '</div>';

    echo '</div>'; // Sulkee inv2-logs-container
    echo '</div>'; // Sulkee inv2-card

    /* 3. SETTINGS (YHTENÄINEN KORTTI) */
    echo '<div class="inv2-card" style="padding: 0; margin-bottom: 25px;">';
    
    echo '<div class="inv2-accordion-header" data-target="inv2-settings-container">';
    echo '<h2 style="margin:0; font-size:16px;">Asetukset <span class="inv2-arrow">▼</span></h2>';
    echo '</div>';

    echo '<div id="inv2-settings-container" style="display: none; padding: 0 15px 15px 15px; border-top: 1px solid #e2e4e7;">';
    echo '<form method="post" action="options.php" style="margin-top: 15px;">';
    settings_fields('inv2_monitor');
    
    // Luetaan vain viralliset asetukset (piilotetaan tietokannan vanhat "haamut")
    $defaults = inv2_default_settings();
    
    foreach ($defaults as $k => $default_v) {
        $v = isset($s[$k]) ? $s[$k] : $default_v;
        $label = $k;
        if ($k === 'auto_clear_days') $label = 'Automaattinen tyhjennys (päivää) - 0 = pois päältä';
        
        echo '<p><strong>'.$label.'</strong><br><input class="regular-text code" name="'.INV2_OPTION_KEY.'['.$k.']" value="'.esc_attr($v).'"></p>';
    }
    submit_button();
    echo '</form>';
    echo '</div>'; // Sulkee inv2-settings-container
    
    echo '</div>'; // Sulkee inv2-card

    /* STYLES */
    echo '<style>
    .inv2-toolbar {display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .inv2-grid {display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .inv2-card {background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);}
    
    .inv2-log-column { display: flex; flex-direction: column; }
    
    .inv2-accordion-header {
        padding: 15px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; 
        align-items: center; background: transparent; transition: background 0.2s; user-select: none;
    }
    .inv2-accordion-header:hover { background: #f9fafb; }
    .inv2-accordion-header.is-open { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
    
    .inv2-arrow { font-size: 12px; color: #6b7280; transition: transform 0.3s ease; }
    .inv2-accordion-header.is-open .inv2-arrow { transform: rotate(180deg); color: #2271b1; }

    .inv2-log {
        background:#0d1117; color:#e6edf3; padding:10px; height:320px; overflow-y:auto; 
        font-family:monospace; border-radius:6px; white-space: pre-wrap; font-size: 13px;
        display: flex; flex-direction: column-reverse;
    }
    
    @keyframes inv2-spin { 100% { transform: rotate(360deg); } }
    .inv2-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-weight:bold; font-size:11px; border: 1px solid transparent; }
    .inv2-badge-blue { background:#f0f9ff; color:#0369a1; border-color: #bae6fd; }
    .inv2-badge-gray { background:#f9fafb; color:#4b5563; border-color: #e5e7eb; }
    .inv2-badge-red { background:#fef2f2; color:#b91c1c; border-color: #fecaca; }
    </style>';

    $ajax_nonce = wp_create_nonce('inv2_ajax_action');

    /* JS */
    echo '<script>
    const inv2_ajax_nonce = "'.esc_js($ajax_nonce).'";
    const isError = l => /error|fatal|exception|failed/i.test(l);

    // ACCORDION LOGIIKKA
    document.querySelectorAll(".inv2-accordion-header").forEach(header => {
        header.addEventListener("click", () => {
            const targetId = header.getAttribute("data-target");
            const targetEl = document.getElementById(targetId);
            const isOpen = header.classList.contains("is-open");
            
            if (isOpen) {
                targetEl.style.display = "none";
                header.classList.remove("is-open");
            } else {
                targetEl.style.display = "block";
                header.classList.add("is-open");
            }
        });
    });

    // KÄSITTELEE KOKO LOKIN PIIRTÄMISEN
    function render(id, lines) {
        const search = document.getElementById("inv2-search").value.toLowerCase();
        const errors = document.getElementById("inv2-errors").checked;
        const el = document.getElementById(id);
        let html = "";
        const filteredLines = [];

        lines.forEach(l => {
            if (search && !l.toLowerCase().includes(search)) return;
            if (errors && !isError(l)) return;
            filteredLines.push(l);
        });

        filteredLines.reverse().forEach(l => {
            let color = isError(l) ? "#ff6b6b" : (/debug/i.test(l) ? "#888" : "#e6edf3");
            const safeText = l.replace(/</g, "&lt;").replace(/>/g, "&gt;");
            html += `<div style="color:${color}">${safeText}</div>`;
        });

        if (el.innerHTML !== html) el.innerHTML = html;
    }

    // KÄSITTELIJÄ VIIMEISIMMÄLLE AJOLLE
    function renderLatest(id, logId, lines, title, startMarker, endMarker) {
        const el = document.getElementById(id);
        if (!el) return;

        let status = "unknown";
        let runLines = [];
        let startIndex = -1;
        let endIndex = -1;

        for (let i = lines.length - 1; i >= 0; i--) {
            if (lines[i].includes(endMarker)) {
                endIndex = i; status = "completed"; break;
            } else if (lines[i].includes(startMarker)) {
                startIndex = i; status = "running"; break;
            }
        }

        if (status === "completed") {
            for (let j = endIndex; j >= 0; j--) {
                if (lines[j].includes(startMarker)) { startIndex = j; break; }
            }
            if (startIndex !== -1) {
                runLines = lines.slice(startIndex, endIndex + 1);
            } else {
                runLines = lines.slice(0, endIndex + 1); 
            }
        } else if (status === "running") {
            runLines = lines.slice(startIndex);
        }

        let html = `<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                        <h3 style="margin:0; font-size:15px; color:#1d2327;">${title}</h3>
                        <div>
                            <button class="button button-small inv2-copy" data-target="mini_${logId}">Kopioi</button>
                        </div>
                    </div>`;

        if (status === "running") {
            html += `<div style="color: #0284c7; font-weight: bold; margin-bottom: 12px; display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; animation: inv2-spin 2s linear infinite; font-size: 16px;">⏳</span> Ajo on parhaillaan käynnissä...
                     </div>`;
        } else if (status === "completed") {
            const match = runLines[runLines.length - 1]?.match(/\[(.*?)\]/);
            const time = match ? match[1] : "Tuntematon aika";
            html += `<div style="color: #10b981; font-weight: bold; margin-bottom: 12px;">
                        ✅ Viimeisin ajo päättyi: ${time}
                     </div>`;
        } else {
            html += `<div style="color: #6b7280; font-style: italic; margin-bottom: 12px;">Ei tietoa aiemmista ajoista valituilla riveillä.</div>`;
        }

        if (runLines.length > 0) {
            let updates = 0, skips = 0, errors = 0;
            runLines.forEach(l => {
                if (/UPDATE|CREATE|HIDE/i.test(l)) updates++;
                if (/skip/i.test(l)) skips++;
                if (isError(l)) errors++;
            });

            html += `<div style="display:flex; gap:8px; margin-bottom:12px;">
                        <span class="inv2-badge inv2-badge-blue">Muutettu/Käsitelty: ${updates} kpl</span>
                        <span class="inv2-badge inv2-badge-gray">Ohitettu: ${skips} kpl</span>
                        <span class="inv2-badge ${errors > 0 ? "inv2-badge-red" : "inv2-badge-gray"}">Virheet: ${errors} kpl</span>
                     </div>`;

            let logHtml = [...runLines].reverse().map(l => {
                let c = isError(l) ? "#ff6b6b" : (/skip|debug/i.test(l) ? "#888" : "#e6edf3");
                return `<div style="color:${c}">${l.replace(/</g, "&lt;").replace(/>/g, "&gt;")}</div>`;
            }).join("");

            html += `<div id="mini_${logId}" class="inv2-log" style="height: 320px; padding:8px; font-size:13px; border:1px solid #30363d;">${logHtml}</div>`;
        }

        if (el.innerHTML !== html) {
            el.innerHTML = html;
            attachButtonEvents(el);
        }
    }

    function loadLogs() {
        const limit = document.getElementById("inv2-limit").value;

        fetch(ajaxurl + "?action=inv2_fetch_logs&limit=" + limit)
        .then(r => r.json())
        .then(d => {
            if(d.success === false) return;
            
            // Viimeisin ajo
            renderLatest("latest_b", "log_b", d.b || [], "Yhteenveto: Tilaukset → Snipe-IT", "Cron B Orders START", "Cron B Orders END");
            renderLatest("latest_c", "log_c", d.c || [], "Yhteenveto: Snipe-IT → Verkkokauppa", "Cron C Consumables START", "Cron C Consumables END");

            // Raakalokit
            render("log_b", d.b || []);
            render("log_c", d.c || []);
        });
    }

    setInterval(loadLogs, 5000);
    loadLogs();

    document.getElementById("inv2-limit").onchange = loadLogs;
    document.getElementById("inv2-search").oninput = loadLogs;
    document.getElementById("inv2-errors").onchange = loadLogs;

    document.querySelectorAll(".inv2-run-btn").forEach(btn => {
        btn.onclick = () => {
            const type = btn.dataset.type;
            const origText = btn.innerText;
            btn.innerText = "Käynnistetään...";
            btn.disabled = true;

            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type":"application/x-www-form-urlencoded"},
                body: "action=inv2_run_script&nonce=" + inv2_ajax_nonce + "&type=" + type
            })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    btn.innerText = "Ajo käynnissä taustalla!";
                    setTimeout(loadLogs, 1000); 
                    setTimeout(() => { btn.innerText = origText; btn.disabled = false; }, 3000);
                } else {
                    alert("Virhe: " + res.data);
                    btn.innerText = origText; btn.disabled = false;
                }
            });
        };
    });

    function attachButtonEvents(container) {
        container.querySelectorAll(".inv2-copy").forEach(btn => {
            btn.onclick = () => {
                const el = document.getElementById(btn.dataset.target);
                if(!el) return;
                const text = Array.from(el.children).map(c => c.innerText).reverse().join("\\n");
                navigator.clipboard.writeText(text).then(() => {
                    const o = btn.innerText; btn.innerText = "Kopioitu!";
                    setTimeout(() => btn.innerText = o, 2000);
                });
            };
        });

        container.querySelectorAll(".inv2-clear").forEach(btn => {
            btn.onclick = () => {
                if (!confirm("Haluatko varmasti tyhjentää lokin?")) return;
                fetch(ajaxurl, {
                    method: "POST",
                    headers: {"Content-Type":"application/x-www-form-urlencoded"},
                    body: "action=inv2_clear_log&nonce=" + inv2_ajax_nonce + "&type=" + btn.dataset.type
                }).then(() => loadLogs());
            };
        });
    }
    
    attachButtonEvents(document);
    
    </script></div>';
}

function inv2_log_card($title, $path, $id) {
    $url = admin_url('admin.php?page=inv2-monitor&inv2_download='.urlencode($path));
    return '<div class="inv2-log-column"><h3 style="margin-top:0;">'.$title.'</h3><div style="margin-bottom:8px;"><a class="button" href="'.esc_url($url).'">Lataa .txt</a> <button class="button inv2-copy" data-target="'.$id.'">Kopioi leikepöydälle</button> <button class="button inv2-clear" data-type="'.($id === 'log_b' ? 'b' : 'c').'">Tyhjennä</button></div><div id="'.$id.'" class="inv2-log">Ladataan...</div></div>';
}