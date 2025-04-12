<div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
    <h2>Storico Telefonate</h2>
    <?php
    $telefonate = PuntoTende_Events::get_instance()->get_calls($cliente->id);

    if ($telefonate) {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Data e Ora</th>';
        echo '<th>Note</th>';
        echo '<th>Registrata da</th>';
        echo '</tr></thead><tbody>';

        foreach ($telefonate as $tel) {
            $user_info = get_userdata($tel->created_by);
            echo '<tr>';
            echo '<td>' . esc_html(date('d/m/Y H:i', strtotime($tel->data_ora))) . '</td>';
            echo '<td>' . esc_html($tel->note) . '</td>';
            echo '<td>' . esc_html($user_info ? $user_info->display_name : 'N/A') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    } else {
        echo '<p>Nessuna telefonata registrata.</p>';
    }
    ?>
</div>