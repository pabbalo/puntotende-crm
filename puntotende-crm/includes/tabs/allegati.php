<?php
if (!defined('ABSPATH')) {
    exit;
}

function puntotende_crm_show_allegati($cliente) {
    global $wpdb;
    $table_allegati = $wpdb->prefix . 'puntotende_allegati';

    // Gestione caricamento allegato
    if (isset($_POST['add_allegato']) && check_admin_referer('add_allegato')) {
        if (!empty($_FILES['allegato']['name'])) {
            $uploaded = media_handle_upload('allegato', 0);

            if (is_wp_error($uploaded)) {
                echo '<div class="error"><p>Errore nel caricamento dell\'allegato: ' . $uploaded->get_error_message() . '</p></div>';
            } else {
                $wpdb->insert($table_allegati, [
                    'cliente_id' => $cliente->id,
                    'file_id' => $uploaded,
                    'data_caricamento' => current_time('mysql')
                ]);

                if ($wpdb->last_error) {
                    echo '<div class="error"><p>Errore nel salvataggio dell\'allegato: ' . esc_html($wpdb->last_error) . '</p></div>';
                } else {
                    echo '<div class="updated"><p>Allegato caricato con successo!</p></div>';
                }
            }
        }
    }

    // Gestione eliminazione allegato
    if (isset($_POST['delete_allegato']) && check_admin_referer('delete_allegato')) {
        $allegato_id = intval($_POST['allegato_id']);
        $allegato = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_allegati WHERE id = %d AND cliente_id = %d", $allegato_id, $cliente->id));

        if ($allegato) {
            wp_delete_attachment($allegato->file_id, true);
            $wpdb->delete($table_allegati, ['id' => $allegato_id]);

            if ($wpdb->last_error) {
                echo '<div class="error"><p>Errore nell\'eliminazione dell\'allegato: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Allegato eliminato con successo!</p></div>';
            }
        }
    }
?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Carica Allegato</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('add_allegato'); ?>
            <p>
                <label>Seleziona File:<br>
                <input type="file" name="allegato" required></label>
            </p>
            <p>
                <button type="submit" name="add_allegato" class="button button-primary">
                    Carica Allegato
                </button>
            </p>
        </form>
    </div>

    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Allegati</h2>
        <?php
        $allegati = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_allegati WHERE cliente_id = %d ORDER BY data_caricamento DESC", $cliente->id));

        if ($allegati) {
            foreach ($allegati as $allegato) {
                $file_url = wp_get_attachment_url($allegato->file_id);
                ?>
                <div class="allegato-card" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                    <p>
                        <a href="<?php echo esc_url($file_url); ?>" target="_blank">Visualizza Allegato</a>
                        <small>Caricato il: <?php echo esc_html(date('d/m/Y', strtotime($allegato->data_caricamento))); ?></small>
                    </p>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field('delete_allegato'); ?>
                        <input type="hidden" name="allegato_id" value="<?php echo esc_attr($allegato->id); ?>">
                        <button type="submit" name="delete_allegato" class="button">
                            Elimina Allegato
                        </button>
                    </form>
                </div>
                <?php
            }
        } else {
            echo '<p>Nessun allegato caricato.</p>';
        }
        ?>
    </div>
<?php
}