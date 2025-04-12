<div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
    <h2>Nuovo Appuntamento</h2>
    <form method="post">
        <?php wp_nonce_field('add_appointment'); ?>
        <p>
            <label>Data e Ora:<br>
            <input type="datetime-local" name="appointment_datetime" required></label>
        </p>
        <p>
            <label>Durata (minuti):<br>
            <input type="number" name="appointment_duration" value="60" min="15" step="15"></label>
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