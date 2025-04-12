<div class="wrap">
    <h1>PuntoTende CRM - Impostazioni Google Calendar</h1>
    <hr>
    
    <form method="post" action="">
        <?php wp_nonce_field('puntotende_gcal_settings', 'puntotende_gcal_nonce'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Client ID Google</th>
                <td>
                    <input type="text" name="gcal_client_id" value="<?php echo esc_attr($saved_id); ?>" class="regular-text" />
                    <p class="description">Inserisci il Client ID ottenuto da Google Cloud Console</p>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row">Client Secret Google</th>
                <td>
                    <input type="password" name="gcal_client_secret" value="<?php echo esc_attr($saved_secret); ?>" class="regular-text" />
                    <p class="description">Inserisci il Client Secret ottenuto da Google Cloud Console</p>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row">Token Webhook</th>
                <td>
                    <input type="text" name="webhook_token" value="<?php echo esc_attr($webhook_token); ?>" class="regular-text" />
                    <p class="description">Imposta un token segreto per verificare le richieste webhook</p>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row">Modalità Debug</th>
                <td>
                    <input type="checkbox" id="debug_mode" name="debug_mode" value="1" <?php checked('1', $debug_mode); ?> />
                    <label for="debug_mode">Attiva log di debug estesi</label>
                    <p class="description">Attiva questa opzione solo quando è necessario il debug. I log estesi possono influire sulle prestazioni del sito.</p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" name="save_gcal_settings" class="button-primary" value="Salva impostazioni" />
        </p>
    </form>
    
    <hr>
    
    <h2>Autorizzazione Google Calendar</h2>
    
    <?php if (!empty($token_data)): ?>
        <div class="notice notice-success inline">
            <p>Connesso a Google Calendar. 
            <?php if (isset($token_data['expires_in'])): ?>
                Il token scade tra <?php echo round($token_data['expires_in']/3600); ?> ore.
            <?php endif; ?>
            </p>
        </div>
        
        <form method="post" action="">
            <?php wp_nonce_field('puntotende_gcal_disconnect', 'puntotende_gcal_disconnect_nonce'); ?>
            <p>
                <input type="submit" name="gcal_disconnect" class="button button-secondary" value="Disconnetti account Google" />
            </p>
        </form>
        
        <p>
            <button id="test_api" class="button">Testa connessione API</button>
            <span id="test_result"></span>
        </p>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#test_api').on('click', function() {
                    var $button = $(this);
                    var $result = $('#test_result');
                    
                    $button.prop('disabled', true);
                    $result.html('Verifica in corso...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_gcal_api',
                            _ajax_nonce: '<?php echo wp_create_nonce('test_gcal_api'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<span style="color: green;">' + response.data.message + '</span>');
                            } else {
                                $result.html('<span style="color: red;">Errore: ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            $result.html('<span style="color: red;">Errore di connessione</span>');
                        },
                        complete: function() {
                            $button.prop('disabled', false);
                        }
                    });
                });
            });
        </script>
        
    <?php else: ?>
        <div class="notice notice-warning inline">
            <p>Non sei connesso a Google Calendar. Configura le credenziali OAuth e clicca sul bottone qui sotto per autorizzare.</p>
        </div>
        <p>
            <a href="<?php echo admin_url('admin-post.php?action=puntotende_gcal_oauth_start'); ?>" class="button button-primary">Connetti a Google Calendar</a>
        </p>
    <?php endif; ?>
    
    <hr>
    
    <h2>Informazioni</h2>
    <p>Per configurare l'integrazione con Google Calendar:</p>
    <ol>
        <li>Accedi alla <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a></li>
        <li>Crea un nuovo progetto o seleziona un progetto esistente</li>
        <li>Vai a "API e servizi" > "Credenziali"</li>
        <li>Crea nuove credenziali OAuth 2.0</li>
        <li>Aggiungi l'URL di callback: <code><?php echo admin_url('admin-post.php?action=puntotende_gcal_oauth_callback'); ?></code></li>
        <li>Copia Client ID e Client Secret e incollali nei campi qui sopra</li>
        <li>Abilita l'API Google Calendar per il tuo progetto</li>
    </ol>
    
    <p>Per le notifiche webhook di Google Calendar, aggiungi questo URL alle origini autorizzate:</p>
    <code><?php echo rest_url($this->webhook_endpoint); ?></code>
    
</div>