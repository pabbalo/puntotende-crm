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