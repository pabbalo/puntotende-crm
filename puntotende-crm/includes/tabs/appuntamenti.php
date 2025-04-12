<?php
if (!defined('ABSPATH')) {
    exit;
}

function puntotende_crm_show_appuntamenti($cliente) {
    global $wpdb;
    $table_eventi = $wpdb->prefix . 'puntotende_eventi';
    
    // Verifica stato Google Calendar
    $gcal_client_id = get_option('puntotende_gcal_client_id');
    $gcal_token = get_option('puntotende_gcal_token');
    
    if (empty($gcal_client_id) || empty($gcal_token)) {
        echo '<div class="notice notice-warning">
            <p>⚠️ L\'integrazione con Google Calendar non è configurata. 
            <a href="' . admin_url('admin.php?page=puntotende-gcal-settings') . '">Configura ora</a></p>
        </div>';
    }
    // Gestione eliminazione appuntamento
    if (isset($_POST['delete_appointment']) && check_admin_referer('delete_appointment')) {
        $appointment_id = intval($_POST['appointment_id']);
        
        error_log('PuntoTende CRM - Tentativo eliminazione appuntamento ID: ' . $appointment_id);
        
        // Recupera l'ID dell'evento dal database degli eventi
        $gcal_event_id = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}puntotende_eventi_meta 
             WHERE evento_id = %d AND meta_key = 'gcal_event_id'",
            $appointment_id
        ));
        
        error_log('PuntoTende CRM - GCal Event ID trovato: ' . ($gcal_event_id ?: 'nessuno'));
        
        $deletion_successful = true;
        
        // Prima elimina l'evento da Google Calendar
        if (!empty($gcal_event_id)) {
            try {
                $deleted_from_gcal = apply_filters('puntotende_gcal_delete_event', false, $gcal_event_id);
                error_log('PuntoTende CRM - Risultato eliminazione da GCal: ' . ($deleted_from_gcal ? 'success' : 'failed'));
                
                if (!$deleted_from_gcal) {
                    $deletion_successful = false;
                }
            } catch (Exception $e) {
                error_log('PuntoTende CRM - Errore eliminazione GCal: ' . $e->getMessage());
                $deletion_successful = false;
            }
        }
        
        // Elimina dal database locale
        $deleted = $wpdb->delete(
            $table_eventi,
            ['id' => $appointment_id],
            ['%d']
        );
        
        if ($deleted) {
            // Elimina anche i metadata associati
            $wpdb->delete(
                $wpdb->prefix . 'puntotende_eventi_meta',
                ['evento_id' => $appointment_id],
                ['%d']
            );
            
            if ($deletion_successful) {
                echo '<div class="updated"><p>✓ Appuntamento eliminato con successo!</p></div>';
            } else {
                echo '<div class="updated"><p>⚠️ Appuntamento eliminato dal database locale, ma potrebbero esserci problemi con Google Calendar</p></div>';
            }
        } else {
            echo '<div class="error"><p>Errore nell\'eliminazione dell\'appuntamento</p></div>';
        }
    }
    // Gestione modifica appuntamento (aggiungi questo codice dopo la gestione dell'eliminazione e prima della gestione dell'aggiunta)
    if (isset($_POST['edit_appointment']) && check_admin_referer('edit_appointment')) {
        $appointment_id = intval($_POST['appointment_id']);
        $datetime = sanitize_text_field(puntotende_ensure_string($_POST['appointment_datetime']));
        $notes = sanitize_textarea_field(puntotende_ensure_string($_POST['appointment_notes']));
        
        // Verifica che l'appuntamento appartenga al cliente corrente
        $existing_appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_eventi WHERE id = %d AND cliente_id = %d",
            $appointment_id, $cliente->id
        ));
        
        if ($existing_appointment) {
            // Aggiorna l'appuntamento nel database locale
            $updated = $wpdb->update(
                $table_eventi,
                [
                    'data_ora' => $datetime,
                    'note' => $notes
                ],
                ['id' => $appointment_id],
                ['%s', '%s'],
                ['%d']
            );
            
            if ($updated) {
                $update_successful = true;
                
                // Recupera l'ID dell'evento Google Calendar
                $gcal_event_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}puntotende_eventi_meta 
                     WHERE evento_id = %d AND meta_key = 'gcal_event_id'",
                    $appointment_id
                ));
                
                // Se c'è un evento Google Calendar associato, aggiornalo
                if (!empty($gcal_event_id)) {
                    try {
                        // Prepara i dati aggiornati per Google Calendar
                        $gcal_data = [
                            'title' => 'Appuntamento: ' . $cliente->denominazione,
                            'start_time' => $datetime,
                            'end_time' => date('Y-m-d H:i:s', strtotime($datetime . '+1 hour')),
                            'description' => $notes . "\n\n" .
                                         "Cliente: " . $cliente->denominazione . "\n" .
                                         "Telefono: " . puntotende_ensure_string($cliente->telefono) . "\n" .
                                         "Email: " . puntotende_ensure_string($cliente->email) . "\n" .
                                         "Indirizzo: " . puntotende_ensure_string($cliente->indirizzo) . "\n" .
                                         "Comune: " . puntotende_ensure_string($cliente->comune) . "\n" .
                                         "Provincia: " . puntotende_ensure_string($cliente->provincia) . "\n" .
                                         "CAP: " . puntotende_ensure_string($cliente->cap),
                            'location' => $cliente->indirizzo . ', ' . $cliente->comune . ' ' . $cliente->provincia,
                            'event_id' => $gcal_event_id
                        ];
                        
                        // Debug
                        error_log('PuntoTende CRM - Tentativo aggiornamento evento GCal: ' . print_r($gcal_data, true));
                        
                        // Aggiorna l'evento in Google Calendar
                        $updated_in_gcal = apply_filters('puntotende_gcal_update_event', false, $gcal_event_id, $gcal_data);
                        
                        if (!$updated_in_gcal) {
                            $update_successful = false;
                            error_log('PuntoTende CRM - Aggiornamento GCal non riuscito per event_id: ' . $gcal_event_id);
                        }
                    } catch (Exception $e) {
                        error_log('PuntoTende CRM - Errore aggiornamento GCal: ' . $e->getMessage());
                        $update_successful = false;
                    }
                }
                
                if ($update_successful) {
                    echo '<div class="updated"><p>✓ Appuntamento aggiornato con successo!</p></div>';
                } else {
                    echo '<div class="updated"><p>⚠️ Appuntamento aggiornato nel database locale, ma potrebbero esserci problemi con Google Calendar</p></div>';
                }
            } else {
                echo '<div class="error"><p>Errore nell\'aggiornamento dell\'appuntamento</p></div>';
            }
        } else {
            echo '<div class="error"><p>Appuntamento non trovato o non autorizzato</p></div>';
        }
    }

      // Gestione form nuovo appuntamento
    if (isset($_POST['add_appointment']) && check_admin_referer('add_appointment')) {
        $datetime = sanitize_text_field(puntotende_ensure_string($_POST['appointment_datetime']));
        $notes = sanitize_textarea_field(puntotende_ensure_string($_POST['appointment_notes']));
        
        // Inserisci nel DB
        $inserted = $wpdb->insert($table_eventi, [
            'cliente_id' => $cliente->id,
            'tipo' => 'appuntamento',
            'data_ora' => $datetime,
            'note' => $notes,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        ]);

        if ($wpdb->last_error) {
            echo '<div class="error"><p>Errore nel salvataggio dell\'appuntamento: ' . esc_html($wpdb->last_error) . '</p></div>';
        } else {
            $event_id = $wpdb->insert_id;
            
            // Prepara i dati per Google Calendar
            $gcal_data = [
                'title' => 'Appuntamento: ' . $cliente->denominazione,
                'start_time' => $datetime,
                'end_time' => date('Y-m-d H:i:s', strtotime($datetime . '+1 hour')),
                'description' => $notes . "\n\n" .
                               "Cliente: " . $cliente->denominazione . "\n" .
                               "Telefono: " . puntotende_ensure_string($cliente->telefono) . "\n" .
                               "Email: " . puntotende_ensure_string($cliente->email) . "\n" .
                               "Indirizzo: " . puntotende_ensure_string($cliente->indirizzo) . "\n" .
                               "Comune: " . puntotende_ensure_string($cliente->comune) . "\n" .
                               "Provincia: " . puntotende_ensure_string($cliente->provincia) . "\n" .
                               "CAP: " . puntotende_ensure_string($cliente->cap),
                'location' => $cliente->indirizzo . ', ' . $cliente->comune . ' ' . $cliente->provincia,
                'event_id' => $event_id
            ];

            // Debug
            error_log('PuntoTende CRM - Tentativo creazione evento GCal: ' . print_r($gcal_data, true));
            
            // Crea l'evento in Google Calendar
            try {
                $gcal_event_id = apply_filters('puntotende_gcal_create_event', false, $gcal_data);
                if ($gcal_event_id) {
                    // Salva l'ID dell'evento Google Calendar usando la tabella eventi_meta
                    $wpdb->insert(
                        $wpdb->prefix . 'puntotende_eventi_meta',
                        [
                            'evento_id' => $event_id,
                            'meta_key' => 'gcal_event_id',
                            'meta_value' => $gcal_event_id
                        ],
                        ['%d', '%s', '%s']
                    );
                    echo '<div class="updated"><p>✓ Appuntamento aggiunto e sincronizzato con Google Calendar!</p></div>';
                } else {
                    echo '<div class="updated"><p>Appuntamento aggiunto (sincronizzazione GCal non riuscita)</p></div>';
                }
            } catch (Exception $e) {
                error_log('PuntoTende CRM - Errore GCal: ' . $e->getMessage());
                echo '<div class="error"><p>Appuntamento salvato ma errore con Google Calendar: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
    }


    // Form nuovo appuntamento
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Nuovo Appuntamento</h2>
        <form method="post">
            <?php wp_nonce_field('add_appointment'); ?>
            <p>
                <label>Data e Ora:<br>
                <input type="datetime-local" name="appointment_datetime" required></label>
            </p>
            <p>
                <label>Note:<br>
                <textarea name="appointment_notes" rows="3" style="width: 100%;"></textarea></label>
            </p>
            <p>
                <button type="submit" name="add_appointment" class="button button-primary">
                    Aggiungi Appuntamento
                </button>
            </p>
        </form>
    </div>

    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Storico Appuntamenti</h2>
        <?php
        $eventi = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_eventi 
             WHERE cliente_id = %d AND tipo = 'appuntamento'
             ORDER BY data_ora DESC",
            $cliente->id
        ));

        if ($eventi) {
            foreach ($eventi as $evento) {
                $user_info = get_userdata($evento->created_by);
                ?>
                <div class="appointment-card" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                    <div class="appointment-header" style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <strong><?php echo esc_html(date('d/m/Y H:i', strtotime($evento->data_ora))); ?></strong>
                        </div>
                        <div>
                            <button type="button" class="button" 
                                    onclick="toggleEditForm(<?php echo esc_attr($evento->id); ?>)">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('delete_appointment'); ?>
                                <input type="hidden" name="appointment_id" value="<?php echo esc_attr($evento->id); ?>">
                                <button type="submit" name="delete_appointment" class="button" 
                                        onclick="return confirm('Sei sicuro di voler eliminare questo appuntamento?');">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="appointment-details">
                        <p><?php echo nl2br(esc_html($evento->note)); ?></p>
                        <small>Creato da: <?php echo esc_html($user_info ? $user_info->display_name : 'N/A'); ?></small>
                    </div>

                    <!-- Form modifica -->
                    <div id="edit-form-<?php echo esc_attr($evento->id); ?>" style="display: none; margin-top: 15px;">
                        <form method="post">
                            <?php wp_nonce_field('edit_appointment'); ?>
                            <input type="hidden" name="appointment_id" value="<?php echo esc_attr($evento->id); ?>">
                            <p>
                                <label>Data e Ora:<br>
                                <input type="datetime-local" name="appointment_datetime" 
                                       value="<?php echo esc_attr(date('Y-m-d\TH:i', strtotime($evento->data_ora))); ?>" 
                                       required></label>
                            </p>
                            <p>
                                <label>Note:<br>
                                <textarea name="appointment_notes" rows="3" 
                                          style="width: 100%;"><?php echo esc_textarea($evento->note); ?></textarea></label>
                            </p>
                            <p>
                                <button type="submit" name="edit_appointment" class="button button-primary">
                                    Aggiorna Appuntamento
                                </button>
                                <button type="button" class="button" 
                                        onclick="toggleEditForm(<?php echo esc_attr($evento->id); ?>)">
                                    Annulla
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
                <?php
            }
        } else {
            echo '<p>Nessun appuntamento registrato.</p>';
        }
        ?>
    </div>

    <script>
    function toggleEditForm(id) {
        var form = document.getElementById('edit-form-' + id);
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
    }
    </script>
    <?php
}