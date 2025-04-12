<?php
if (!defined('ABSPATH')) {
    exit;
}

function puntotende_crm_show_info($cliente) {
    global $wpdb;
    $table_clienti = $wpdb->prefix . 'puntotende_clienti';

    // Gestione aggiornamento dati cliente
    if (isset($_POST['update_client']) && check_admin_referer('update_client')) {
        // Rimuoviamo TUTTI gli slashes accumulati
        $data = [
            'denominazione' => sanitize_text_field(wp_unslash($_POST['denominazione'])),
            'fase' => sanitize_text_field(wp_unslash($_POST['fase'])),
            'indirizzo' => sanitize_text_field(wp_unslash($_POST['indirizzo'])),
            'comune' => sanitize_text_field(wp_unslash($_POST['comune'])),
            'cap' => sanitize_text_field(wp_unslash($_POST['cap'])),
            'provincia' => sanitize_text_field(wp_unslash($_POST['provincia'])),
            'note_indirizzo' => sanitize_textarea_field(wp_unslash($_POST['note_indirizzo'])),
            'paese' => sanitize_text_field(wp_unslash($_POST['paese'])),
            'email' => sanitize_email(wp_unslash($_POST['email'])),
            'referente' => sanitize_text_field(wp_unslash($_POST['referente'])),
            'telefono' => sanitize_text_field(wp_unslash($_POST['telefono'])),
            'piva_tax' => sanitize_text_field(wp_unslash($_POST['piva_tax'])),
            'codice_fiscale' => sanitize_text_field(wp_unslash($_POST['codice_fiscale'])),
            'pec' => sanitize_email(wp_unslash($_POST['pec'])),
            'codice_sdi' => sanitize_text_field(wp_unslash($_POST['codice_sdi'])),
            'termini_pagamento' => sanitize_textarea_field(wp_unslash($_POST['termini_pagamento'])),
            'iban' => sanitize_text_field(wp_unslash($_POST['iban'])),
            'sconto_predefinito' => floatval($_POST['sconto_predefinito']),
            'note' => sanitize_textarea_field(wp_unslash($_POST['note']))
        ];

        // Usiamo direttamente la query preparata per evitare problemi con gli slashes
        $query = $wpdb->prepare(
            "UPDATE $table_clienti SET
            denominazione = %s,
            fase = %s,
            indirizzo = %s,
            comune = %s,
            cap = %s,
            provincia = %s,
            note_indirizzo = %s,
            paese = %s,
            email = %s,
            referente = %s,
            telefono = %s,
            piva_tax = %s,
            codice_fiscale = %s,
            pec = %s,
            codice_sdi = %s,
            termini_pagamento = %s,
            iban = %s,
            sconto_predefinito = %f,
            note = %s
            WHERE id = %d",
            $data['denominazione'],
            $data['fase'],
            $data['indirizzo'],
            $data['comune'],
            $data['cap'],
            $data['provincia'],
            $data['note_indirizzo'],
            $data['paese'],
            $data['email'],
            $data['referente'],
            $data['telefono'],
            $data['piva_tax'],
            $data['codice_fiscale'],
            $data['pec'],
            $data['codice_sdi'],
            $data['termini_pagamento'],
            $data['iban'],
            $data['sconto_predefinito'],
            $data['note'],
            $cliente->id
        );

        $result = $wpdb->query($query);

        if ($result !== false) {
            // Rileggiamo i dati dal database
            $cliente = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_clienti WHERE id = %d",
                $cliente->id
            ));
            echo '<div class="updated"><p>Cliente aggiornato con successo!</p></div>';
        } else {
            echo '<div class="error"><p>Errore durante l\'aggiornamento: ' . $wpdb->last_error . '</p></div>';
        }
    }

    // Nel form, visualizziamo i dati senza slashes
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
        <h2>Informazioni Cliente</h2>
        <form method="post">
            <?php wp_nonce_field('update_client'); ?>
            
            <div class="form-section">
                <h3>Dati Principali</h3>
                <p>
                    <label>Denominazione:<br>
                    <input type="text" name="denominazione" value="<?php echo esc_attr(stripslashes($cliente->denominazione)); ?>" required style="width: 100%;"></label>
                </p>
                <p>
                    <label>Fase:<br>
                    <select name="fase" style="width: 100%;">
                        <option value="contatto" <?php selected($cliente->fase, 'contatto'); ?>>Contatto</option>
                        <option value="trattativa" <?php selected($cliente->fase, 'trattativa'); ?>>Trattativa</option>
                        <option value="vendita" <?php selected($cliente->fase, 'vendita'); ?>>Vendita</option>
                        <option value="postvendita" <?php selected($cliente->fase, 'postvendita'); ?>>Post Vendita</option>
                    </select></label>
                </p>
            </div>

            <div class="form-section">
                <h3>Indirizzo</h3>
                <p>
                    <label>Indirizzo:<br>
                    <input type="text" name="indirizzo" value="<?php echo esc_attr(stripslashes($cliente->indirizzo)); ?>" style="width: 100%;"></label>
                </p>
                <p>
                    <label>Comune:<br>
                    <input type="text" name="comune" value="<?php echo esc_attr(stripslashes($cliente->comune)); ?>" style="width: 100%;"></label>
                </p>
                <p>
                    <label>CAP:<br>
                    <input type="text" name="cap" value="<?php echo esc_attr(stripslashes($cliente->cap)); ?>" maxlength="5" style="width: 100px;"></label>
                </p>
                <p>
                    <label>Provincia:<br>
                    <input type="text" name="provincia" value="<?php echo esc_attr(stripslashes($cliente->provincia)); ?>" maxlength="2" style="width: 50px;"></label>
                </p>
                <p>
                    <label>Note Indirizzo:<br>
                    <textarea name="note_indirizzo" rows="2" style="width: 100%;"><?php echo esc_textarea(stripslashes($cliente->note_indirizzo)); ?></textarea></label>
                </p>
                <p>
                    <label>Paese:<br>
                    <input type="text" name="paese" value="<?php echo esc_attr(stripslashes($cliente->paese)); ?>" style="width: 100%;"></label>
                </p>
            </div>

            <div class="form-section">
                <h3>Contatti</h3>
                <p>
                    <label>Email:<br>
                    <input type="email" name="email" value="<?php echo esc_attr(stripslashes($cliente->email)); ?>" style="width: 100%;"></label>
                </p>
                <p>
                    <label>Referente:<br>
                    <input type="text" name="referente" value="<?php echo esc_attr(stripslashes($cliente->referente)); ?>" style="width: 100%;"></label>
                </p>
                <p>
                    <label>Telefono:<br>
                    <input type="tel" name="telefono" value="<?php echo esc_attr(stripslashes($cliente->telefono)); ?>" style="width: 100%;"></label>
                </p>
            </div>

            <div class="form-section">
                <h3>Dati Fiscali</h3>
                <p>
                    <label>P.IVA / Tax ID:<br>
                    <input type="text" name="piva_tax" value="<?php echo esc_attr(stripslashes($cliente->piva_tax)); ?>" style="width: 100%;"></label>
                </p>
                <p>
                    <label>Codice Fiscale:<br>
                    <input type="text" name="codice_fiscale" value="<?php echo esc_attr(stripslashes($cliente->codice_fiscale)); ?>" maxlength="16" style="width: 100%;"></label>
                </p>
                <p>
                    <label>PEC:<br>
                    <input type="email" name="pec" value="<?php echo esc_attr(stripslashes($cliente->pec)); ?>" style="width: 100%;"></label>
                </p>
                <p>
                    <label>Codice SDI:<br>
                    <input type="text" name="codice_sdi" value="<?php echo esc_attr(stripslashes($cliente->codice_sdi)); ?>" maxlength="7" style="width: 100px;"></label>
                </p>
            </div>

            <div class="form-section">
                <h3>Dati Pagamento</h3>
                <p>
                    <label>Termini di Pagamento:<br>
                    <textarea name="termini_pagamento" rows="2" style="width: 100%;"><?php echo esc_textarea(stripslashes($cliente->termini_pagamento)); ?></textarea></label>
                </p>
                <p>
                    <label>IBAN:<br>
                    <input type="text" name="iban" value="<?php echo esc_attr(stripslashes($cliente->iban)); ?>" maxlength="34" style="width: 100%;"></label>
                </p>
                <p>
                    <label>Sconto Predefinito (%):<br>
                    <input type="number" name="sconto_predefinito" value="<?php echo esc_attr(floatval($cliente->sconto_predefinito)); ?>" min="0" max="100" step="0.01" style="width: 100px;"></label>
                </p>
            </div>

            <div class="form-section">
                <h3>Note</h3>
                <p>
                    <label>Note Generali:<br>
                    <textarea name="note" rows="4" style="width: 100%;"><?php echo esc_textarea(stripslashes($cliente->note)); ?></textarea></label>
                </p>
            </div>

            <p>
                <button type="submit" name="update_client" class="button button-primary">
                    Aggiorna Dati
                </button>
            </p>
        </form>
    </div>

    <style>
    .form-section {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    .form-section h3 {
        margin-bottom: 15px;
        color: #23282d;
    }
    .form-section:last-child {
        border-bottom: none;
    }
    </style>
    <?php
}