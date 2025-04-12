<?php
if (!defined('ABSPATH')) {
    exit;
}

function puntotende_crm_show_documenti($cliente) {
    global $wpdb;
    $table_documenti = $wpdb->prefix . 'puntotende_documenti';
    
    // Assicuriamoci che la tabella documenti esista
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_documenti'") != $table_documenti) {
        // Creiamo la tabella dei documenti se non esiste
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_documenti (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cliente_id bigint(20) NOT NULL,
            nome_file varchar(255) NOT NULL,
            percorso_file varchar(255) NOT NULL,
            tipo_file varchar(100) NOT NULL,
            dimensione_file bigint(20) NOT NULL,
            descrizione text,
            caricato_da bigint(20) NOT NULL,
            data_caricamento datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY cliente_id (cliente_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_documenti'") != $table_documenti) {
            echo '<div class="error"><p>Impossibile creare la tabella dei documenti. Contattare l\'amministratore.</p></div>';
            return;
        }
    }
    
    // Gestione caricamento nuovo documento
    if (isset($_POST['upload_documento']) && check_admin_referer('upload_documento')) {
        if (!empty($_FILES['file_documento']['name'])) {
            // Controlliamo che sia stato caricato un file valido
            if ($_FILES['file_documento']['error'] == 0) {
                // Crea la directory di upload se non esiste
                $upload_dir = wp_upload_dir();
                $cliente_dir = $upload_dir['basedir'] . '/puntotende-crm/' . $cliente->id;
                
                if (!file_exists($cliente_dir)) {
                    wp_mkdir_p($cliente_dir);
                    
                    // Proteggiamo la directory con un file .htaccess
                    file_put_contents($cliente_dir . '/.htaccess', 'deny from all');
                }
                
                $file_name = sanitize_file_name($_FILES['file_documento']['name']);
                $file_path = $cliente_dir . '/' . $file_name;
                
                // Genera un nome univoco se il file esiste già
                $counter = 0;
                $pathinfo = pathinfo($file_name);
                $base_name = $pathinfo['filename'];
                $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';
                
                while (file_exists($file_path)) {
                    $counter++;
                    $file_name = $base_name . '-' . $counter . $extension;
                    $file_path = $cliente_dir . '/' . $file_name;
                }
                
                // Carica il file
                if (move_uploaded_file($_FILES['file_documento']['tmp_name'], $file_path)) {
                    // Inserisci il documento nel database
                    $result = $wpdb->insert(
                        $table_documenti,
                        [
                            'cliente_id' => $cliente->id,
                            'nome_file' => $file_name,
                            'percorso_file' => str_replace($upload_dir['basedir'], '', $file_path),
                            'tipo_file' => $_FILES['file_documento']['type'],
                            'dimensione_file' => $_FILES['file_documento']['size'],
                            'descrizione' => sanitize_textarea_field($_POST['descrizione_documento']),
                            'caricato_da' => get_current_user_id(),
                            'data_caricamento' => current_time('mysql')
                        ]
                    );
                    
                    if ($result) {
                        echo '<div class="updated"><p>Documento caricato con successo!</p></div>';
                    } else {
                        echo '<div class="error"><p>Errore nell\'inserimento del documento nel database: ' . $wpdb->last_error . '</p></div>';
                        // Elimina il file se non è stato possibile inserirlo nel database
                        @unlink($file_path);
                    }
                } else {
                    echo '<div class="error"><p>Errore nel caricamento del file. Controlla i permessi della directory.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Errore nel caricamento del file: codice ' . $_FILES['file_documento']['error'] . '</p></div>';
            }
        } else {
            echo '<div class="error"><p>Nessun file selezionato.</p></div>';
        }
    }
    
    // Gestione eliminazione documento
    if (isset($_POST['delete_documento']) && check_admin_referer('delete_documento')) {
        $documento_id = intval($_POST['documento_id']);
        
        // Ottieni le informazioni sul documento prima di eliminarlo
        $documento = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_documenti WHERE id = %d AND cliente_id = %d",
            $documento_id,
            $cliente->id
        ));
        
        if ($documento) {
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . $documento->percorso_file;
            
            // Elimina il documento dal database
            $deleted = $wpdb->delete(
                $table_documenti,
                [
                    'id' => $documento_id,
                    'cliente_id' => $cliente->id
                ],
                [
                    '%d',
                    '%d'
                ]
            );
            
            if ($deleted) {
                // Elimina il file dal server
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
                echo '<div class="updated"><p>Documento eliminato con successo!</p></div>';
            } else {
                echo '<div class="error"><p>Errore nell\'eliminazione del documento dal database.</p></div>';
            }
        } else {
            echo '<div class="error"><p>Documento non trovato o non autorizzato.</p></div>';
        }
    }
    
    // Formulario per caricare un nuovo documento
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Carica Documento</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('upload_documento'); ?>
            <p>
                <label>Seleziona file:<br>
                <input type="file" name="file_documento" required accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.jpg,.jpeg,.png"></label>
            </p>
            <p>
                <label>Descrizione:<br>
                <textarea name="descrizione_documento" rows="3" style="width: 100%;"></textarea></label>
            </p>
            <p>
                <button type="submit" name="upload_documento" class="button button-primary">
                    Carica Documento
                </button>
            </p>
        </form>
    </div>
    
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Documenti Caricati</h2>
        <?php
        $documenti = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_documenti 
             WHERE cliente_id = %d
             ORDER BY data_caricamento DESC",
            $cliente->id
        ));
        
        if ($documenti && count($documenti) > 0) {
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Nome File</th>
                        <th>Descrizione</th>
                        <th>Tipo</th>
                        <th>Dimensione</th>
                        <th>Caricato il</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documenti as $documento) {
                        $user_info = get_userdata($documento->caricato_da);
                        $file_size = size_format($documento->dimensione_file);
                        $upload_dir = wp_upload_dir();
                        $file_url = admin_url('admin-ajax.php?action=puntotende_download_documento&id=' . $documento->id . '&nonce=' . wp_create_nonce('download_documento_' . $documento->id));
                        
                        // Otteniamo un'icona in base al tipo di file
                        $file_icon = 'dashicons-media-default';
                        if (strpos($documento->tipo_file, 'pdf') !== false) {
                            $file_icon = 'dashicons-pdf';
                        } elseif (strpos($documento->tipo_file, 'word') !== false || strpos($documento->tipo_file, 'doc') !== false) {
                            $file_icon = 'dashicons-media-document';
                        } elseif (strpos($documento->tipo_file, 'excel') !== false || strpos($documento->tipo_file, 'spreadsheet') !== false) {
                            $file_icon = 'dashicons-media-spreadsheet';
                        } elseif (strpos($documento->tipo_file, 'image') !== false) {
                            $file_icon = 'dashicons-format-image';
                        }
                    ?>
                        <tr>
                            <td>
                                <span class="dashicons <?php echo $file_icon; ?>" style="vertical-align: middle;"></span>
                                <?php echo esc_html($documento->nome_file); ?>
                            </td>
                            <td><?php echo nl2br(esc_html($documento->descrizione)); ?></td>
                            <td><?php echo esc_html($documento->tipo_file); ?></td>
                            <td><?php echo esc_html($file_size); ?></td>
                            <td>
                                <?php 
                                echo esc_html(date('d/m/Y H:i', strtotime($documento->data_caricamento)));
                                if ($user_info) {
                                    echo '<br><small>da ' . esc_html($user_info->display_name) . '</small>';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($file_url); ?>" class="button" title="Scarica">
                                    <span class="dashicons dashicons-download"></span>
                                </a>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('delete_documento'); ?>
                                    <input type="hidden" name="documento_id" value="<?php echo esc_attr($documento->id); ?>">
                                    <button type="submit" name="delete_documento" class="button" 
                                            onclick="return confirm('Sei sicuro di voler eliminare questo documento?');" title="Elimina">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>Nessun documento caricato per questo cliente.</p>';
        }
        ?>
    </div>
    <?php
}
?>