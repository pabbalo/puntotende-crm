 <?php
/**
 * Plugin Name: PuntoTende CRM
 * Plugin URI: https://www.puntotende.it/
 * Description: Plugin CRM con elenco clienti, aggiungi cliente, import/export CSV (delimitatore ;), debug riga per riga, e placeholder Google Calendar.
 * Version: 1.3
 * Author: Puntotende
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Disabilita temporaneamente i warning di deprecation per queste funzioni specifiche
error_reporting(E_ALL & ~E_DEPRECATED);
// Verifica se il plugin Google Calendar è attivo
function puntotende_crm_check_gcal() {
    if (!class_exists('PuntoTende_GCal')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-warning">
                <p>Per utilizzare le funzionalità di Google Calendar, attiva il plugin "PuntoTende CRM - Google Calendar".</p>
            </div>
            <?php
        });
    }
}
add_action('admin_init', 'puntotende_crm_check_gcal');

// Monkey patch delle funzioni problematiche
function override_wp_functions() {
    // Sovrascrivi strpos per gestire null
    if (!function_exists('wp_safe_strpos')) {
        function wp_safe_strpos($haystack, $needle, $offset = 0) {
            if (is_null($haystack)) {
                $haystack = '';
            }
            return strpos((string)$haystack, (string)$needle, $offset);
        }
    }

    // Sovrascrivi str_replace per gestire null
    if (!function_exists('wp_safe_str_replace')) {
        function wp_safe_str_replace($search, $replace, $subject) {
            if (is_null($subject)) {
                return '';
            }
            return str_replace($search, $replace, $subject);
        }
    }

    // Sovrascrivi strip_tags per gestire null
    if (!function_exists('wp_safe_strip_tags')) {
        function wp_safe_strip_tags($string, $allowed_tags = null) {
            if (is_null($string)) {
                return '';
            }
            return strip_tags($string, $allowed_tags);
        }
    }
}

// Applica gli override il prima possibile
add_action('plugins_loaded', 'override_wp_functions', -9999);

// Modifica le funzioni WordPress problematiche
add_action('init', function() {
    global $wp_filter;
    
    // Rimuovi i filtri problematici e applicane di nuovi
    if (isset($wp_filter['the_title'])) {
        $wp_filter['the_title']->callbacks = array();
    }
    
    add_filter('the_title', function($title) {
        return wp_safe_strip_tags($title);
    }, 0);
    
    add_filter('admin_title', function($title) {
        return wp_safe_strip_tags($title);
    }, 0);
}, 0);

// Funzione helper per gestire i valori null
function puntotende_ensure_string($value) {
    if (is_null($value)) {
        return '';
    }
    if (is_object($value)) {
        return '';
    }
    if (is_array($value)) {
        return '';
    }
    return (string)$value;
}

// Carica le classi necessarie
require_once plugin_dir_path(__FILE__) . 'includes/class-puntotende-events.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-puntotende-clients.php';

// Carica i tab
require_once plugin_dir_path(__FILE__) . 'includes/tabs/appuntamenti.php';
require_once plugin_dir_path(__FILE__) . 'includes/tabs/telefonate.php';
// ============================================================
// 1. ATTIVAZIONE: TABELLE, RUOLI
// ============================================================
function puntotende_crm_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabella clienti
    $table_clienti = $wpdb->prefix . 'puntotende_clienti';
    $sql_clienti = "CREATE TABLE IF NOT EXISTS $table_clienti (
        id INT NOT NULL AUTO_INCREMENT,
        denominazione VARCHAR(255) NOT NULL,
        fase VARCHAR(20) DEFAULT 'contatto',
        indirizzo VARCHAR(255),
        comune VARCHAR(255),
        cap VARCHAR(10),
        provincia VARCHAR(2),
        note_indirizzo TEXT,
        paese VARCHAR(255),
        email VARCHAR(255),
        referente VARCHAR(255),
        telefono VARCHAR(50),
        piva_tax VARCHAR(20),
        codice_fiscale VARCHAR(16),
        pec VARCHAR(255),
        codice_sdi VARCHAR(7),
        termini_pagamento TEXT,
        iban VARCHAR(34),
        sconto_predefinito DECIMAL(5,2),
        note TEXT,
        PRIMARY KEY (id),
        KEY fase (fase),
        KEY denominazione (denominazione)
    ) $charset_collate;";
    
    // Tabella eventi
    $table_eventi = $wpdb->prefix . 'puntotende_eventi';
    $sql_eventi = "CREATE TABLE IF NOT EXISTS $table_eventi (
        id INT NOT NULL AUTO_INCREMENT,
        cliente_id INT NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        data_ora DATETIME NOT NULL,
        durata INT,
        note TEXT,
        google_event_id VARCHAR(255),
        created_by BIGINT(20),
        created_at DATETIME,
        PRIMARY KEY (id),
        KEY cliente_id (cliente_id),
        KEY tipo (tipo),
        KEY data_ora (data_ora)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_clienti);
    dbDelta($sql_eventi);

    // Crea ruoli
    puntotende_crm_create_roles();
}

register_activation_hook(__FILE__, 'puntotende_crm_activate');

// ============================================================
// 2. RUOLI
// ============================================================
function puntotende_crm_create_roles() {
    // Ruolo pt_tecnico
    add_role('pt_tecnico', 'Tecnico CRM', [
        'read'               => true,
        'pt_crm_read_data'   => true,
        'pt_crm_edit_prices' => true
        // NOTA: non ha pt_crm_edit_sensitive, né manage_options
    ]);

    // Ruolo pt_editor
    $editor_caps = [
        'read'                 => true,
        'pt_crm_read_data'     => true,
        'pt_crm_edit_prices'   => true,
        'pt_crm_edit_sensitive'=> true,
    ];
    add_role('pt_editor', 'Editor CRM', $editor_caps);

    // Assegna cap extra all'administrator
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('pt_crm_read_data');
        $admin->add_cap('pt_crm_edit_prices');
        $admin->add_cap('pt_crm_edit_sensitive');
    }
}

// ============================================================
// 3. MENU
// ============================================================
function puntotende_crm_menu() {
    // MENU PRINCIPALE
    // Il 4° argomento 'puntotende-crm' è lo SLUG usato come PARENT per le subpage
    add_menu_page(
        'PuntoTende CRM',
        'CRM PuntoTende',
        'pt_crm_read_data',     // capability minima
        'puntotende-crm',       // slug principale
        'puntotende_crm_dashboard',
        'dashicons-portfolio',
        6
    );

    // Lista Clienti
    add_submenu_page(
        'puntotende-crm',
        'Lista Clienti',
        'Lista Clienti',
        'pt_crm_read_data',      // i tecnici hanno pt_crm_read_data
        'puntotende-crm-lista',
        'puntotende_crm_lista'
    );

    // Aggiungi Cliente
    add_submenu_page(
        'puntotende-crm',
        'Aggiungi Cliente',
        'Aggiungi Cliente',
        'pt_crm_edit_sensitive', // serve livello più alto
        'puntotende-crm-aggiungi',
        'puntotende_crm_aggiungi'
    );

    // Import/Export
    add_submenu_page(
        'puntotende-crm',
        'Import/Export',
        'Import/Export',
        'pt_crm_edit_sensitive', 
        'puntotende-crm-import',
        'puntotende_crm_import_export'
    );

    // Impostazioni CRM
    add_submenu_page(
        'puntotende-crm',
        'Impostazioni',
        'Impostazioni',
        'manage_options',        // solo admin
        'puntotende-crm-settings',
        'puntotende_crm_settings'
    );

    // Sottopagina "Modifica Cliente" (nascosta, link direct)
    add_submenu_page(
        null, // non appare nel menu
        'Modifica Cliente',
        'Modifica Cliente',
        'pt_crm_read_data',
        'puntotende-crm-clienti-edit',
        'puntotende_crm_clienti_edit'
    );
}
add_action('admin_menu', 'puntotende_crm_menu');

// ============================================================
// 4. DASHBOARD
// ============================================================
function puntotende_crm_dashboard() {
    echo '<div class="wrap"><h1>PuntoTende CRM - Dashboard</h1>';
    echo '<p>Benvenuto nel CRM PuntoTende!</p>';
    echo '</div>';
}

// ============================================================
// 5. COLORI FASI
// ============================================================
function puntotende_crm_get_fase_color($fase) {
    switch($fase) {
        case 'contatto':    return '#a2e1ff';
        case 'trattativa':  return '#fffaa2';
        case 'vendita':     return '#ffa2a2';
        case 'postvendita': return '#a2ffa4';
    }
    return '#ffffff';
}

// ============================================================
// 6. LISTA CLIENTI
// ============================================================
function puntotende_crm_lista() {
    global $wpdb;
    $table_clienti = $wpdb->prefix . 'puntotende_clienti';

    // IMPORTANTE: Gestione azioni di massa PRIMA di qualsiasi output
    if (!empty($_POST['bulk_action']) && !empty($_POST['selected_clients'])) {
        check_admin_referer('puntotende_crm_lista_bulk', 'puntotende_crm_lista_nonce');

        $selected_ids = array_map('intval', $_POST['selected_clients']);
        $action = sanitize_text_field($_POST['bulk_action']);

        // Log dell'operazione
        error_log(sprintf(
            "Bulk Action initiated - Date/Time (UTC): %s, User: %s, Action: %s",
            current_time('mysql', true),
            wp_get_current_user()->user_login,
            $action
        ));

        switch ($action) {
            case 'delete':
                foreach ($selected_ids as $id) {
                    $wpdb->delete($table_clienti, ['id' => $id], ['%d']);
                }
                wp_safe_redirect(add_query_arg(
                    [
                        'page' => 'puntotende-crm-lista',
                        'message' => urlencode('Clienti eliminati correttamente')
                    ],
                    admin_url('admin.php')
                ));
                exit;

            case 'change_fase':
                if (!empty($_POST['fase_nuova'])) {
                    $fase_nuova = sanitize_text_field($_POST['fase_nuova']);
                    $fasi_ammesse = ['contatto', 'trattativa', 'vendita', 'postvendita'];
                    
                    if (in_array($fase_nuova, $fasi_ammesse, true)) {
                        $updated = 0;
                        foreach ($selected_ids as $id) {
                            $result = $wpdb->update(
                                $table_clienti,
                                ['fase' => $fase_nuova],
                                ['id' => $id],
                                ['%s'],
                                ['%d']
                            );
                            
                            if ($result !== false) {
                                $updated++;
                            }
                        }

                        wp_safe_redirect(add_query_arg(
                            [
                                'page' => 'puntotende-crm-lista',
                                'updated' => $updated,
                                'message' => urlencode("Fase aggiornata a '$fase_nuova' per $updated clienti")
                            ],
                            admin_url('admin.php')
                        ));
                        exit;
                    }
                }
                wp_safe_redirect(add_query_arg(
                    [
                        'page' => 'puntotende-crm-lista',
                        'message' => urlencode('Fase non valida o non specificata')
                    ],
                    admin_url('admin.php')
                ));
                exit;

            case 'export_selected_csv':
                // Verifica se ci sono clienti selezionati
                if (empty($selected_ids)) {
                    wp_die('Nessun cliente selezionato per l\'export.');
                }

                // Prepara la query
                $ids_placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
                $sql = "SELECT * FROM $table_clienti WHERE id IN ($ids_placeholders)";
                $clienti_selezionati = $wpdb->get_results($wpdb->prepare($sql, $selected_ids));

                // Pulisci qualsiasi output precedente
                ob_clean();
                
                // Imposta gli headers
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename=clienti_selezionati.csv');
                
                // Apri l'output
                $out = fopen('php://output', 'w');
                
                // Aggiungi BOM per UTF-8
                fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

                // Intestazioni CSV
                fputcsv($out, [
                    'Denominazione', 'Indirizzo', 'Comune', 'CAP', 'Provincia', 'NoteIndirizzo',
                    'Paese', 'Email', 'Referente', 'Telefono', 'PIVA', 'CodFisc', 'PEC', 'CodSDI',
                    'TerminiPag', 'IBAN', 'Sconto', 'Note'
                ], ';');

                // Dati
                foreach ($clienti_selezionati as $cli) {
                    fputcsv($out, [
                        $cli->denominazione, $cli->indirizzo, $cli->comune, $cli->cap,
                        $cli->provincia, $cli->note_indirizzo, $cli->paese, $cli->email,
                        $cli->referente, $cli->telefono, $cli->piva_tax, $cli->codice_fiscale,
                        $cli->pec, $cli->codice_sdi, $cli->termini_pagamento, $cli->iban,
                        $cli->sconto_predefinito, $cli->note
                    ], ';');
                }
                fclose($out);
                exit;
        }
    }

    // Output della pagina HTML
    ?>
    <div class="wrap">
        <h1>Lista Clienti</h1>
        <?php
        // Mostra messaggi di stato
        if (isset($_GET['message'])) {
            echo '<div class="updated"><p>' . esc_html(urldecode($_GET['message'])) . '</p></div>';
        }
    
        // 2) ORDINAMENTO
        $orderby_allowed = ['id', 'denominazione', 'fase', 'telefono', 'email'];
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'denominazione';
        $order = (isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC') ? 'DESC' : 'ASC';
        if (!in_array($orderby, $orderby_allowed, true)) {
            $orderby = 'denominazione';
        }
    
        $sql = "SELECT * FROM $table_clienti ORDER BY $orderby $order";
        $clienti = $wpdb->get_results($sql);
        $nextOrder = ($order === 'ASC') ? 'DESC' : 'ASC';
        $baseUrl = admin_url('admin.php?page=puntotende-crm-lista');
    
        // Stili CSS per la ricerca
        ?>
        <style>
            .search-box {
                margin: 15px 0;
                max-width: 600px;
            }
            .search-box input {
                width: 100%;
                padding: 8px;
                font-size: 16px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .cliente-row {
                transition: all 0.3s ease;
            }
            .cliente-row.hidden {
                display: none;
            }
            .highlight {
                background-color: #fff3cd;
            }
            /* Nuovi stili per le larghezze delle colonne */
            .column-id {
                width: 50px;  /* Colonna ID più stretta */
            }
            .column-fase {
                width: 100px; /* Colonna fase più stretta */
            }
            .column-azioni {
                width: 80px;  /* Colonna azioni più stretta */
            }
            .column-denominazione {
                width: 30%;   /* Colonna denominazione più larga */
            }
            .column-telefono {
                width: 15%;   /* Colonna telefono dimensione media */
            }
            .column-email {
                width: 20%;   /* Colonna email dimensione media */
            }
        </style>
    
        <!-- Campo di ricerca -->
        <div class="search-box">
            <input type="text" 
                   id="clientSearch" 
                   placeholder="Cerca per nome, telefono, email..." 
                   autocomplete="off">
        </div>
    
        <!-- Link Export CSV TUTTI -->
        <a href="<?php echo esc_url(admin_url('admin-post.php?action=puntotende_export_csv')); ?>" 
           class="button button-primary" 
           style="margin-bottom:10px;">Export TUTTI CSV</a>
    
        <form method="post">
            <?php wp_nonce_field('puntotende_crm_lista_bulk', 'puntotende_crm_lista_nonce'); ?>
    
            <!-- Azioni di massa -->
            <div style="margin-bottom:10px;">
                <select name="bulk_action">
                    <option value="">Azioni di massa</option>
                    <option value="delete">Elimina</option>
                    <option value="change_fase">Cambia fase</option>
                    <option value="export_selected_csv">Esporta selezionati in CSV</option>
                </select>
    
                <select name="fase_nuova" style="margin-left:10px;">
                    <option value="">--Fase--</option>
                    <option value="contatto">contatto</option>
                    <option value="trattativa">trattativa</option>
                    <option value="vendita">vendita</option>
                    <option value="postvendita">postvendita</option>
                </select>
    
                <button type="submit" class="button">Applica</button>
            </div>
    
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td style="width:30px;">
                            <input type="checkbox" onclick="jQuery('.pt_sel').prop('checked', this.checked);" />
                        </td>
                        <th class="column-id">
                            <a href="<?php echo esc_url($baseUrl.'&orderby=id&order='.$nextOrder); ?>">ID</a>
                        </th>
                        <th class="column-denominazione">
                            <a href="<?php echo esc_url($baseUrl.'&orderby=denominazione&order='.$nextOrder); ?>">Denominazione</a>
                        </th>
                        <th class="column-fase">
                            <a href="<?php echo esc_url($baseUrl.'&orderby=fase&order='.$nextOrder); ?>">Fase</a>
                        </th>
                        <th class="column-telefono">
                            <a href="<?php echo esc_url($baseUrl.'&orderby=telefono&order='.$nextOrder); ?>">Telefono</a>
                        </th>
                        <th class="column-email">
                            <a href="<?php echo esc_url($baseUrl.'&orderby=email&order='.$nextOrder); ?>">Email</a>
                        </th>
                        <th class="column-azioni">Azioni</th>
                    </tr>
                </thead
                <tbody id="clientiTableBody">
                    <?php
                    if ($clienti) {
                        foreach ($clienti as $cli) {
                            $bg = puntotende_crm_get_fase_color($cli->fase);
                            ?>
                                <tr class="cliente-row" data-search="<?php echo esc_attr(strtolower($cli->denominazione . ' ' . 
                                                                                                  $cli->telefono . ' ' . 
                                                                                                  $cli->email)); ?>">
                                    <td>
                                        <input type="checkbox" class="pt_sel" name="selected_clients[]" 
                                               value="<?php echo intval($cli->id); ?>" />
                                    </td>
                                    <td class="column-id"><?php echo esc_html($cli->id); ?></td>
                                    <td class="column-denominazione"><?php echo esc_html($cli->denominazione); ?></td>
                                    <td class="column-fase" style="background-color:<?php echo esc_attr($bg); ?>;">
                                        <?php echo esc_html($cli->fase); ?>
                                    </td>
                                    <td class="column-telefono"><?php echo esc_html($cli->telefono); ?></td>
                                    <td class="column-email"><?php echo esc_html($cli->email); ?></td>
                                    <td class="column-azioni">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=puntotende-crm-clienti-edit&cliente_id=' . $cli->id)); ?>" 
                                           class="button">Modifica</a>
                                    </td>
                                </tr>
                            <?php
                        }
                    } else {
                        echo '<tr><td colspan="7">Nessun cliente presente.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </form>
    </div>
   <script>
    jQuery(document).ready(function($) {
        var searchTimeout;
        var rows = $('.cliente-row');
        
        $('#clientSearch').on('input', function() {
            clearTimeout(searchTimeout);
            
            searchTimeout = setTimeout(function() {
                var searchText = $('#clientSearch').val().toLowerCase();
                
                rows.each(function() {
                    var row = $(this);
                    var searchData = row.data('search');
                    
                    if (searchData.includes(searchText)) {
                        row.removeClass('hidden');
                    } else {
                        row.addClass('hidden');
                    }
                });
            }, 300);
        });
    });
    </script>
    <?php
    echo '</div>'; // Chiusura del div wrap
} // Chiusura della funzione
// ============================================================
// 7. AGGIUNGI CLIENTE
// ============================================================
function puntotende_crm_aggiungi() {
    global $wpdb;
    $table_clienti = $wpdb->prefix.'puntotende_clienti';

    if (isset($_POST['submit_nuovo_cliente'])) {
        if (!isset($_POST['puntotende_crm_nonce']) || 
            !wp_verify_nonce($_POST['puntotende_crm_nonce'], 'puntotende_crm_salva_cliente')) {
            wp_die('Nonce non valido!');
        }

        $den   = sanitize_text_field($_POST['denominazione']);
        $ind   = sanitize_text_field($_POST['indirizzo']);
        $com   = sanitize_text_field($_POST['comune']);
        $cap   = sanitize_text_field($_POST['cap']);
        $prov  = sanitize_text_field($_POST['provincia']);
        $ni    = sanitize_text_field($_POST['note_indirizzo']);
        $pese  = sanitize_text_field($_POST['paese']);
        $mail  = sanitize_email($_POST['email']);
        $ref   = sanitize_text_field($_POST['referente']);
        $tel   = sanitize_text_field($_POST['telefono']);
        $piva  = sanitize_text_field($_POST['piva_tax']);
        $cf    = sanitize_text_field($_POST['codice_fiscale']);
        $pec   = sanitize_text_field($_POST['pec']);
        $sdi   = sanitize_text_field($_POST['codice_sdi']);
        $tp    = sanitize_text_field($_POST['termini_pagamento']);
        $iban  = sanitize_text_field($_POST['iban']);
        $sco   = floatval($_POST['sconto_predefinito']);
        $notes = sanitize_textarea_field($_POST['note']);

        $wpdb->insert($table_clienti, [
            'denominazione' => $den,
            'fase' => 'contatto',
            'indirizzo' => $ind,
            'comune' => $com,
            'cap' => $cap,
            'provincia' => $prov,
            'note_indirizzo' => $ni,
            'paese' => $pese,
            'email' => $mail,
            'referente' => $ref,
            'telefono' => $tel,
            'piva_tax' => $piva,
            'codice_fiscale' => $cf,
            'pec' => $pec,
            'codice_sdi' => $sdi,
            'termini_pagamento' => $tp,
            'iban' => $iban,
            'sconto_predefinito' => $sco,
            'note' => $notes
        ]);
        if ($wpdb->last_error) {
            echo '<div class="error"><p>Errore DB: '.esc_html($wpdb->last_error).'</p></div>';
        } else {
            echo '<div class="updated"><p>Cliente aggiunto (ID: '.intval($wpdb->insert_id).').</p></div>';
        }
    }

    echo '<div class="wrap"><h1>Aggiungi Cliente</h1>';
    echo '<form method="post">';
    wp_nonce_field('puntotende_crm_salva_cliente','puntotende_crm_nonce');
    echo '<p><label>Denominazione: <input type="text" name="denominazione" required></label></p>';
    echo '<p><label>Indirizzo: <input type="text" name="indirizzo"></label></p>';
    echo '<p><label>Comune: <input type="text" name="comune"></label></p>';
    echo '<p><label>CAP: <input type="text" name="cap"></label></p>';
    echo '<p><label>Provincia: <input type="text" name="provincia"></label></p>';
    echo '<p><label>Note indirizzo: <input type="text" name="note_indirizzo"></label></p>';
    echo '<p><label>Paese: <input type="text" name="paese"></label></p>';
    echo '<p><label>Email: <input type="email" name="email"></label></p>';
    echo '<p><label>Referente: <input type="text" name="referente"></label></p>';
    echo '<p><label>Telefono: <input type="text" name="telefono"></label></p>';
    echo '<p><label>P.IVA: <input type="text" name="piva_tax"></label></p>';
    echo '<p><label>Codice Fiscale: <input type="text" name="codice_fiscale"></label></p>';
    echo '<p><label>PEC: <input type="text" name="pec"></label></p>';
    echo '<p><label>Codice SDI: <input type="text" name="codice_sdi"></label></p>';
    echo '<p><label>Termini Pagamento: <input type="text" name="termini_pagamento"></label></p>';
    echo '<p><label>IBAN: <input type="text" name="iban"></label></p>';
    echo '<p><label>Sconto: <input type="number" step="0.01" name="sconto_predefinito"></label></p>';
    echo '<p><label>Note: <textarea name="note"></textarea></label></p>';
    echo '<p><button type="submit" name="submit_nuovo_cliente" class="button button-primary">Aggiungi Cliente</button></p>';
    echo '</form>';
    echo '</div>';
}

// ============================================================
// 8. ADMIN_POST -> EXPORT CSV (TUTTI)
// ============================================================
add_action('admin_post_puntotende_export_csv', 'puntotende_crm_export_csv_action');
function puntotende_crm_export_csv_action() {
    if (!current_user_can('pt_crm_read_data')) {
        wp_die('Non hai i permessi per esportare!');
    }

    ob_clean();

    global $wpdb;
    $table_clienti = $wpdb->prefix.'puntotende_clienti';
    $clienti = $wpdb->get_results("SELECT * FROM $table_clienti ORDER BY id ASC");

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=\"clienti_puntotende.csv\"');

    $output = fopen('php://output', 'w');
    // Intestazioni CSV
    fputcsv($output, [
        'Denominazione','Indirizzo','Comune','CAP','Provincia','Note_Indirizzo',
        'Paese','Email','Referente','Telefono','PIVA','CodFisc','PEC','CodSDI',
        'TerminiPag','IBAN','Sconto','Note'
    ], ';'); // Delimitatore ;

    foreach($clienti as $cli) {
        fputcsv($output, [
            $cli->denominazione,
            $cli->indirizzo,
            $cli->comune,
            $cli->cap,
            $cli->provincia,
            $cli->note_indirizzo,
            $cli->paese,
            $cli->email,
            $cli->referente,
            $cli->telefono,
            $cli->piva_tax,
            $cli->codice_fiscale,
            $cli->pec,
            $cli->codice_sdi,
            $cli->termini_pagamento,
            $cli->iban,
            $cli->sconto_predefinito,
            $cli->note
        ], ';');
    }
    fclose($output);
    exit;
}

// ============================================================
// 9. MODIFICA CLIENTE (se serve rendere attivo)
// ============================================================
function puntotende_crm_clienti_edit() {
    if (!current_user_can('pt_crm_read_data')) {
        wp_die('Non hai il permesso di accedere a questa pagina.');
    }

    // Include i file dei tab
    require_once plugin_dir_path(__FILE__) . 'includes/tabs/info.php';
    require_once plugin_dir_path(__FILE__) . 'includes/tabs/appuntamenti.php';
    require_once plugin_dir_path(__FILE__) . 'includes/tabs/telefonate.php';

    global $wpdb;
    $table_clienti = $wpdb->prefix . 'puntotende_clienti';

    $cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 0;
    if ($cliente_id <= 0) {
        echo '<div class="error"><p>ID cliente non valido.</p></div>';
        return;
    }

    $cliente = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_clienti WHERE id = %d",
        $cliente_id
    ));

    if (!$cliente) {
        echo '<div class="error"><p>Cliente non trovato.</p></div>';
        return;
    }

    echo '<div class="wrap">';
    
    // Aggiungi il pulsante "Torna alla Lista" prima del titolo
    echo '<div style="margin-bottom: 20px;">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=puntotende-crm-lista')) . '" 
             class="button button-secondary" 
             id="backToList">';
    echo '<span class="dashicons dashicons-arrow-left-alt" style="vertical-align: middle; margin-right: 5px;"></span> Torna alla Lista';
    echo '</a>';
    echo '</div>';
    
    echo '<h1>Gestione Cliente: ' . esc_html($cliente->denominazione) . '</h1>';


    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'info';
    ?>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url(admin_url('admin.php?page=puntotende-crm-clienti-edit&cliente_id=' . $cliente_id . '&tab=info')); ?>" 
           class="nav-tab <?php echo $current_tab === 'info' ? 'nav-tab-active' : ''; ?>">
           Informazioni Cliente
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=puntotende-crm-clienti-edit&cliente_id=' . $cliente_id . '&tab=appuntamenti')); ?>" 
           class="nav-tab <?php echo $current_tab === 'appuntamenti' ? 'nav-tab-active' : ''; ?>">
           Appuntamenti
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=puntotende-crm-clienti-edit&cliente_id=' . $cliente_id . '&tab=telefonate')); ?>" 
           class="nav-tab <?php echo $current_tab === 'telefonate' ? 'nav-tab-active' : ''; ?>">
           Telefonate
        </a>
    </nav>

    <?php
    switch ($current_tab) {
        case 'appuntamenti':
            if (function_exists('puntotende_crm_show_appuntamenti')) {
                puntotende_crm_show_appuntamenti($cliente);
            } else {
                echo '<div class="error"><p>Funzionalità appuntamenti non disponibile.</p></div>';
            }
            break;
        case 'telefonate':
            if (function_exists('puntotende_crm_show_telefonate')) {
                puntotende_crm_show_telefonate($cliente);
            } else {
                echo '<div class="error"><p>Funzionalità telefonate non disponibile.</p></div>';
            }
            break;
        default:
            if (function_exists('puntotende_crm_show_info')) {
                puntotende_crm_show_info($cliente);
            } else {
                echo '<div class="error"><p>Funzionalità informazioni cliente non disponibile.</p></div>';
            }
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        var formModified = false;
        
        // Monitora tutti i campi di input, textarea e select per modifiche
        $('input, textarea, select').on('change', function() {
            formModified = true;
        });

        // Monitora anche l'input durante la digitazione
        $('input, textarea').on('keyup', function() {
            formModified = true;
        });

        // Reset del flag quando il form viene inviato
        $('form').on('submit', function() {
            formModified = false;
        });

        // Funzione per gestire il tentativo di uscita dalla pagina
        function handlePageLeave(e) {
            if (formModified) {
                var message = 'Ci sono modifiche non salvate. Sei sicuro di voler abbandonare la pagina?';
                e.returnValue = message;
                return message;
            }
        }

        // Aggiungi l'evento beforeunload per intercettare il tentativo di uscita dalla pagina
        window.addEventListener('beforeunload', handlePageLeave);

        // Gestisci il click sul pulsante "Torna alla Lista"
        $('#backToList').on('click', function(e) {
            if (formModified) {
                if (!confirm('Ci sono modifiche non salvate. Sei sicuro di voler abbandonare la pagina?')) {
                    e.preventDefault();
                }
            }
        });

        // Aggiungi la classe per evidenziare i campi modificati
        $('input, textarea, select').on('change keyup', function() {
            $(this).addClass('modified-field');
        });
    });
    </script>
    <style>
    .modified-field {
        background-color: #fff8e5;
        border-color: #ffc107 !important;
    }
    </style>
    <?php
    echo '</div>'; // Chiusura del div wrap
}



// ============================================================
// 10. IMPORT/EXPORT CSV (CON DEBUG RIGA PER RIGA)
// ============================================================
function puntotende_crm_import_export() {
    echo '<div class="wrap"><h1>Import/Export CSV</h1>';

    // Form per import
    echo '<h2>Import CSV (delimitatore ;)</h2>';
    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('puntotende_crm_import_csv','puntotende_crm_import_csv_nonce');
    echo '<p><input type="file" name="file_csv" accept=".csv"></p>';
    echo '<p><button type="submit" name="upload_csv" class="button">Importa CSV</button></p>';
    echo '</form>';

    // Se l'utente clicca su "Importa CSV"
    if (isset($_POST['upload_csv'])) {
        if (!isset($_POST['puntotende_crm_import_csv_nonce']) ||
            !wp_verify_nonce($_POST['puntotende_crm_import_csv_nonce'], 'puntotende_crm_import_csv')) {
            wp_die('Nonce non valido!');
        }

        if (!empty($_FILES['file_csv']['name'])) {
            $tmp_name = $_FILES['file_csv']['tmp_name'];
            $handle = fopen($tmp_name, 'r');
            if ($handle) {
                global $wpdb;
                $table_clienti = $wpdb->prefix.'puntotende_clienti';
                $count = 0;
                $line = 0;

                while (($data = fgetcsv($handle, 0, ';')) !== false) {
                    $line++;

                    // Debug: mostra la riga
                    echo "<pre>Linea n. $line => ";
                    print_r($data);
                    echo "</pre>";

                    // Salta la riga di intestazione
                    if ($line == 1) {
                        echo "Linea $line: salto intestazione<br>";
                        continue;
                    }

                    // Mappatura
                    // 0=Denominazione,1=Indirizzo,2=Comune,3=CAP,4=Provincia,5=Note_Indirizzo,
                    // 6=Paese,7=Email,8=Referente,9=Telefono,10=PIVA,11=CodFisc,
                    // 12=PEC,13=CodSDI,14=TerminiPag,15=IBAN,16=Sconto,17=Note
                    $den   = sanitize_text_field($data[0]);
                    $ind   = sanitize_text_field($data[1]);
                    $com   = sanitize_text_field($data[2]);
                    $cap   = sanitize_text_field($data[3]);
                    $prov  = sanitize_text_field($data[4]);
                    $ni    = sanitize_text_field($data[5]);
                    $pese  = sanitize_text_field($data[6]);
                    $mail  = sanitize_email($data[7]);
                    $ref   = sanitize_text_field($data[8]);
                    $tel   = sanitize_text_field($data[9]);
                    $piva  = sanitize_text_field($data[10]);
                    $cf    = sanitize_text_field($data[11]);
                    $pec   = sanitize_text_field($data[12]);
                    $sdi   = sanitize_text_field($data[13]);
                    $tp    = sanitize_text_field($data[14]);
                    $iban  = sanitize_text_field($data[15]);
                    $sco   = floatval($data[16]);
                    $notes = sanitize_textarea_field($data[17]);

                    // Controllo denominazione vuota
                    if (empty($den)) {
                        echo "Linea $line: denominazione vuota => skip<br>";
                        continue;
                    }
                    // Duplicato
                    $ex = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_clienti WHERE denominazione = %s", $den));
                    if ($ex) {
                        echo "Linea $line: '$den' DUPLICATO => skip<br>";
                        continue;
                    }

                    // Inserimento
                    $wpdb->insert($table_clienti, [
                        'denominazione' => $den,
                        'fase' => 'contatto',
                        'indirizzo' => $ind,
                        'comune' => $com,
                        'cap' => $cap,
                        'provincia' => $prov,
                        'note_indirizzo' => $ni,
                        'paese' => $pese,
                        'email' => $mail,
                        'referente' => $ref,
                        'telefono' => $tel,
                        'piva_tax' => $piva,
                        'codice_fiscale' => $cf,
                        'pec' => $pec,
                        'codice_sdi' => $sdi,
                        'termini_pagamento' => $tp,
                        'iban' => $iban,
                        'sconto_predefinito' => $sco,
                        'note' => $notes
                    ]);
                    if ($wpdb->last_error) {
                        echo "Linea $line: ERRORE DB => ".esc_html($wpdb->last_error)."<br>";
                    } else {
                        echo "Linea $line: INSERITO '$den'<br>";
                        $count++;
                    }
                }
                fclose($handle);

                echo "<div class='updated'><p>Import CSV completato. $count record inseriti.</p></div>";
            } else {
                echo '<div class="error"><p>Impossibile aprire il CSV</p></div>';
            }
        } else {
            echo '<div class="error"><p>Nessun file selezionato</p></div>';
        }
    }

    echo '</div>';
}

// ============================================================
// 11. Impostazioni
// ============================================================
function puntotende_crm_settings() {
    // Se l'utente non ha manage_options => "Non hai i permessi"
    if (!current_user_can('manage_options')) {
        wp_die('Non hai il permesso di accedere a questa pagina (serve manage_options).');
    }

    echo '<div class="wrap"><h1>Impostazioni CRM</h1>';
    echo '<p>Sezione dedicata a parametri aggiuntivi, come Google Calendar e altro (TODO).</p>';
    echo '</div>';
}

/**
 * Placeholder: Google Calendar Page 
 * Per debug. Usa la capability 'manage_options' se vuoi riservarlo all'admin.
 */
function puntotende_gcal_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Non hai il permesso di accedere a questa pagina (serve manage_options).');
    }

    echo '<div class="wrap"><h1>Integrazione Google Calendar (TODO)</h1>';
    echo '<p>Qui andranno le impostazioni OAuth di GCal.</p>';
    echo '</div>';
}
