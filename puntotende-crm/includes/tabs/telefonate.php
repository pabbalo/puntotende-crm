<?php
if (!defined('ABSPATH')) {
    exit;
}

function puntotende_crm_show_telefonate($cliente) {
    global $wpdb;
    $table_eventi = $wpdb->prefix . 'puntotende_eventi';
    
    // Controlla se la tabella esiste
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_eventi'") != $table_eventi) {
        echo '<div class="error"><p>La tabella degli eventi non esiste. Prova a disattivare e riattivare il plugin.</p></div>';
        return;
    }

    // Gestione modifica telefonata
    if (isset($_POST['edit_telefonata']) && check_admin_referer('edit_telefonata')) {
        $telefonata_id = intval($_POST['telefonata_id']);
        $datetime = sanitize_text_field($_POST['telefonata_datetime']);
        $notes = sanitize_textarea_field($_POST['telefonata_notes']);
        
        error_log('PuntoTende CRM - Tentativo modifica telefonata ID: ' . $telefonata_id);
        error_log('PuntoTende CRM - Nuova data: ' . $datetime);
        error_log('PuntoTende CRM - Nuove note: ' . $notes);

        // Verifica che la telefonata appartenga al cliente corrente
        $existing_telefonata = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_eventi WHERE id = %d AND cliente_id = %d AND tipo = 'telefonata'",
            $telefonata_id,
            $cliente->id
        ));

        if (!$existing_telefonata) {
            echo '<div class="error"><p>Telefonata non trovata o non autorizzata.</p></div>';
            return;
        }
        
        $updated = $wpdb->update(
            $table_eventi,
            [
                'data_ora' => $datetime,
                'note' => $notes
            ],
            [
                'id' => $telefonata_id,
                'tipo' => 'telefonata',
                'cliente_id' => $cliente->id
            ],
            ['%s', '%s'],
            ['%d', '%s', '%d']
        );

        if ($updated !== false) {
            error_log('PuntoTende CRM - Telefonata aggiornata con successo');
            echo '<div class="updated"><p>✓ Telefonata aggiornata con successo!</p></div>';
        } else {
            error_log('PuntoTende CRM - Errore aggiornamento telefonata: ' . $wpdb->last_error);
            echo '<div class="error"><p>Errore nell\'aggiornamento della telefonata</p></div>';
        }
    }
    
    // Gestione form nuova telefonata
    if (isset($_POST['add_call']) && check_admin_referer('add_call')) {
        $notes = sanitize_textarea_field($_POST['call_notes']);
        
        $wpdb->insert($table_eventi, [
            'cliente_id' => $cliente->id,
            'tipo' => 'telefonata',
            'data_ora' => current_time('mysql'),
            'note' => $notes,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);

        if ($wpdb->last_error) {
            echo '<div class="error"><p>Errore nel salvataggio della telefonata: ' . esc_html($wpdb->last_error) . '</p></div>';
        } else {
            echo '<div class="updated"><p>Telefonata registrata con successo!</p></div>';
        }
    }

    // Gestione eliminazione telefonata
    if (isset($_POST['delete_telefonata']) && check_admin_referer('delete_telefonata')) {
        $telefonata_id = intval($_POST['telefonata_id']);
        
        error_log('PuntoTende CRM - Tentativo eliminazione telefonata ID: ' . $telefonata_id);
        
        $deleted = $wpdb->delete(
            $table_eventi,
            [
                'id' => $telefonata_id,
                'tipo' => 'telefonata'
            ],
            [
                '%d',
                '%s'
            ]
        );
    
        if ($deleted) {
            // Elimina anche i metadata associati
            $wpdb->delete(
                $wpdb->prefix . 'puntotende_eventi_meta',
                ['evento_id' => $telefonata_id],
                ['%d']
            );
            
            echo '<div class="updated"><p>✓ Telefonata eliminata con successo!</p></div>';
        } else {
            echo '<div class="error"><p>Errore nell\'eliminazione della telefonata</p></div>';
        }
    }
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Registra Telefonata</h2>
        <form method="post">
            <?php wp_nonce_field('add_call'); ?>
            <p>
                <label>Note Telefonata:<br>
                <textarea name="call_notes" rows="3" style="width: 100%;" required></textarea></label>
            </p>
            <p>
                <button type="submit" name="add_call" class="button button-primary">
                    Registra Telefonata
                </button>
            </p>
        </form>
    </div>

    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Storico Telefonate</h2>
        <?php
        $telefonate = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_eventi 
             WHERE cliente_id = %d AND tipo = 'telefonata'
             ORDER BY data_ora DESC",
            $cliente->id
        ));
        
        if ($telefonate) {
            foreach ($telefonate as $telefonata) {
                $user_info = get_userdata($telefonata->created_by);
                ?>
                <div class="telefonata-card" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                    <div class="telefonata-header" style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <strong><?php echo esc_html(date('d/m/Y H:i', strtotime($telefonata->data_ora))); ?></strong>
                        </div>
                        <div>
                            <button type="button" class="button" 
                                    onclick="toggleEditTelForm(<?php echo esc_attr($telefonata->id); ?>)">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('delete_telefonata'); ?>
                                <input type="hidden" name="telefonata_id" value="<?php echo esc_attr($telefonata->id); ?>">
                                <button type="submit" name="delete_telefonata" class="button" 
                                        onclick="return confirm('Sei sicuro di voler eliminare questa telefonata?');">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="telefonata-details">
                        <p><?php echo nl2br(esc_html($telefonata->note)); ?></p>
                        <small>
                            <?php 
                            if ($user_info) {
                                echo 'Registrata da: ' . esc_html($user_info->display_name);
                            } else {
                                echo 'Registrata da: utente non trovato';
                            }
                            ?>
                        </small>
                    </div>

                    <!-- Form modifica telefonata -->
                    <div id="edit-tel-form-<?php echo esc_attr($telefonata->id); ?>" style="display: none; margin-top: 15px;">
                        <form method="post">
                            <?php wp_nonce_field('edit_telefonata'); ?>
                            <input type="hidden" name="telefonata_id" value="<?php echo esc_attr($telefonata->id); ?>">
                            <p>
                                <label>Data e Ora:<br>
                                <input type="datetime-local" name="telefonata_datetime" 
                                       value="<?php echo esc_attr(str_replace(' ', 'T', $telefonata->data_ora)); ?>" 
                                       required></label>
                            </p>
                            <p>
                                <label>Note:<br>
                                <textarea name="telefonata_notes" rows="3" 
                                          style="width: 100%;" required><?php echo esc_textarea($telefonata->note); ?></textarea></label>
                            </p>
                            <p>
                                <button type="submit" name="edit_telefonata" class="button button-primary">
                                    Aggiorna Telefonata
                                </button>
                                <button type="button" class="button" 
                                        onclick="toggleEditTelForm(<?php echo esc_attr($telefonata->id); ?>)">
                                    Annulla
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
                <?php
            }
        } else {
            echo '<p>Nessuna telefonata registrata.</p>';
        }
        ?>
        <script>
        function toggleEditTelForm(id) {
            var form = document.getElementById('edit-tel-form-' + id);
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
        </script>
    </div>
    <?php
}