<div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
    <h2>Informazioni Cliente</h2>
    <form method="post">
        <?php wp_nonce_field('update_client'); ?>
        <p>
            <label>Denominazione:<br>
            <input type="text" name="denominazione" value="<?php echo esc_attr($cliente->denominazione); ?>" required style="width:100%;"></label>
        </p>
        <p>
            <label>Indirizzo:<br>
            <input type="text" name="indirizzo" value="<?php echo esc_attr($cliente->indirizzo); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Comune:<br>
            <input type="text" name="comune" value="<?php echo esc_attr($cliente->comune); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>CAP:<br>
            <input type="text" name="cap" value="<?php echo esc_attr($cliente->cap); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Provincia:<br>
            <input type="text" name="provincia" value="<?php echo esc_attr($cliente->provincia); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Note indirizzo:<br>
            <input type="text" name="note_indirizzo" value="<?php echo esc_attr($cliente->note_indirizzo); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Paese:<br>
            <input type="text" name="paese" value="<?php echo esc_attr($cliente->paese); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Email:<br>
            <input type="email" name="email" value="<?php echo esc_attr($cliente->email); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Referente:<br>
            <input type="text" name="referente" value="<?php echo esc_attr($cliente->referente); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Telefono:<br>
            <input type="text" name="telefono" value="<?php echo esc_attr($cliente->telefono); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>P.IVA:<br>
            <input type="text" name="piva_tax" value="<?php echo esc_attr($cliente->piva_tax); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Codice Fiscale:<br>
            <input type="text" name="codice_fiscale" value="<?php echo esc_attr($cliente->codice_fiscale); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>PEC:<br>
            <input type="text" name="pec" value="<?php echo esc_attr($cliente->pec); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Codice SDI:<br>
            <input type="text" name="codice_sdi" value="<?php echo esc_attr($cliente->codice_sdi); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Termini Pagamento:<br>
            <input type="text" name="termini_pagamento" value="<?php echo esc_attr($cliente->termini_pagamento); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>IBAN:<br>
            <input type="text" name="iban" value="<?php echo esc_attr($cliente->iban); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Sconto predefinito (%):<br>
            <input type="number" step="0.01" name="sconto_predefinito" value="<?php echo esc_attr($cliente->sconto_predefinito); ?>" style="width:100%;"></label>
        </p>
        <p>
            <label>Note:<br>
            <textarea name="note" rows="3" style="width:100%;"><?php echo esc_textarea($cliente->note); ?></textarea></label>
        </p>
        <p>
            <button type="submit" name="update_client" class="button button-primary">
                Aggiorna Dati
            </button>
        </p>
    </form>
</div>