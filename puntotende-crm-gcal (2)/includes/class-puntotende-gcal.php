<?php
class PuntoTende_GCal {
    private static $instance = null;
    private $client = null;
    private $webhook_endpoint = 'puntotende/v1/gcal-webhook';
    private $debug_mode = false;

    public static function get_instance() {
        if (!class_exists('Google\\Client')) {
            error_log('PuntoTende GCal - Tentativo di inizializzazione senza Google\\Client');
            return null;
        }
        
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function log($message) {
        if ($this->debug_mode) {
            error_log('PuntoTende GCal - ' . $message);
        }
    }

    private function __construct() {
        // Leggi l'opzione di debug
        $this->debug_mode = get_option('puntotende_gcal_debug_mode', false);
        
        add_action('admin_menu', [$this, 'add_menu'], 20);
        add_action('admin_notices', [$this, 'check_dependencies']);
        add_action('admin_notices', [$this, 'show_token_expired_notice']);
        add_action('admin_post_puntotende_gcal_oauth_start', [$this, 'oauth_start']);
        add_action('admin_post_puntotende_gcal_oauth_callback', [$this, 'oauth_callback']);
        add_action('wp_ajax_test_gcal_api', [$this, 'handle_test_api']);
        
        // Registra l'endpoint webhook con priorità alta
        add_action('rest_api_init', function() {
            register_rest_route('puntotende/v1', '/gcal-webhook', [
                'methods' => ['GET', 'POST', 'DELETE'],
                'callback' => [$this, 'handle_webhook'],
                'permission_callback' => [$this, 'validate_webhook_request']
            ]);
        }, 1);

        // Hook per sincronizzazione eventi
        add_action('before_delete_post', [$this, 'sync_delete_event'], 10, 1);
        add_filter('puntotende_gcal_delete_event', [$this, 'handle_delete_event'], 10, 2);
        add_action('puntotende_gcal_renew_watch', [$this, 'renew_watch'], 10, 1);
    }

    private function process_event_update($event) {
        $event_id = $event->getId();
        $status = $event->getStatus();
        
        $this->log("Processando evento {$event_id} con status {$status}");
    
        // Cerca l'appuntamento nel database
        global $wpdb;
        $appointment = $wpdb->get_row($wpdb->prepare(
            "SELECT e.* FROM {$wpdb->prefix}puntotende_eventi e 
             INNER JOIN {$wpdb->prefix}puntotende_eventi_meta em 
             ON e.id = em.evento_id 
             WHERE em.meta_key = 'gcal_event_id' 
             AND em.meta_value = %s",
            $event_id
        ));
    
        if ($appointment) {
            if ($status === 'cancelled') {
                // Elimina l'appuntamento
                $wpdb->delete(
                    $wpdb->prefix . 'puntotende_eventi',
                    ['id' => $appointment->id],
                    ['%d']
                );
                
                // Elimina i metadata
                $wpdb->delete(
                    $wpdb->prefix . 'puntotende_eventi_meta',
                    ['evento_id' => $appointment->id],
                    ['%d']
                );
                
                $this->log("Appuntamento {$appointment->id} eliminato");
            } else if ($status === 'confirmed') {
                // Aggiorna l'appuntamento
                $start = $event->getStart()->getDateTime();
                $wpdb->update(
                    $wpdb->prefix . 'puntotende_eventi',
                    [
                        'data_ora' => $start,
                        'note' => $event->getDescription(),
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $appointment->id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                $this->log("Appuntamento {$appointment->id} aggiornato");
            }
        }
    }

    private function init_client() {
        try {
            if ($this->client) {
                return true;
            }
    
            $this->client = new \Google\Client();
            $this->client->setClientId(get_option('puntotende_gcal_client_id'));
            $this->client->setClientSecret(get_option('puntotende_gcal_client_secret'));
            $this->client->setAccessType('offline');
            $this->client->setPrompt('consent'); // Aggiunto per forzare il prompt di consenso che fornisce sempre un refresh token
    
            $token = json_decode(get_option('puntotende_gcal_token', ''), true);
            if (empty($token)) {
                $this->log('Token mancante');
                return false;
            }
    
            $this->client->setAccessToken($token);
            
            if ($this->client->isAccessTokenExpired()) {
                if ($this->client->getRefreshToken()) {
                    try {
                        $this->log('Tentativo di refresh del token...');
                        $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                        $new_token = $this->client->getAccessToken();
                        
                        // Assicurati di preservare il refresh token originale se non è incluso nel nuovo token
                        if (!isset($new_token['refresh_token']) && isset($token['refresh_token'])) {
                            $new_token['refresh_token'] = $token['refresh_token'];
                        }
                        
                        update_option('puntotende_gcal_token', json_encode($new_token));
                        $this->log('Token aggiornato con successo');
                    } catch (\Exception $e) {
                        $this->log('Errore refresh token: ' . $e->getMessage());
                        
                        // Se l'errore è "invalid_grant", è probabile che il refresh token sia scaduto
                        if (strpos($e->getMessage(), 'invalid_grant') !== false) {
                            delete_option('puntotende_gcal_token'); // Rimuovi il token per forzare una nuova autorizzazione
                            $this->log('Token rimosso a causa di "invalid_grant"');
                        }
                        
                        // Mostra notifica admin
                        update_option('puntotende_gcal_token_expired', '1');
                        return false;
                    }
                } else {
                    $this->log('Token scaduto e nessun refresh token disponibile');
                    
                    // Mostra notifica admin
                    update_option('puntotende_gcal_token_expired', '1');
                    return false;
                }
            }
    
            return true;
        } catch (\Exception $e) {
            $this->log('Errore client: ' . $e->getMessage());
            return false;
        }
    }

    public function create_event($event_data) {
        try {
            $this->log('Tentativo creazione evento: ' . print_r($event_data, true));

            if (!$this->init_client()) {
                throw new \Exception('Client non inizializzato');
            }

            $service = new \Google\Service\Calendar($this->client);
            $event = $this->prepare_event($event_data);
            
            $created_event = $service->events->insert('primary', $event);
            $this->log('Evento creato con ID: ' . $created_event->id);
            
            if ($this->setup_watch($created_event->id)) {
                $this->log('Watch configurato con successo');
            }
            
            return $created_event->id;
        } catch (\Exception $e) {
            $this->log('Errore creazione: ' . $e->getMessage());
            return false;
        }
    }

    public function update_event($event_data) {
        try {
            $this->log('Tentativo aggiornamento evento: ' . print_r($event_data, true));
    
            if (!$this->init_client()) {
                throw new \Exception('Client non inizializzato');
            }
            
            if (empty($event_data['id'])) {
                throw new \Exception('ID evento mancante per aggiornamento');
            }
    
            $service = new \Google\Service\Calendar($this->client);
            
            // Prepara l'evento aggiornato
            $event = $this->prepare_event($event_data);
            
            // Aggiorna l'evento
            $updated_event = $service->events->update('primary', $event_data['id'], $event);
            $this->log('Evento aggiornato con ID: ' . $updated_event->id);
            
            return $updated_event->id;
        } catch (\Exception $e) {
            $this->log('Errore aggiornamento: ' . $e->getMessage());
            return false;
        }
    }

    private function prepare_event($data) {
        return new \Google\Service\Calendar\Event([
            'summary' => $data['title'],
            'description' => $data['description'],
            'start' => [
                'dateTime' => date('c', strtotime($data['start_time'])),
                'timeZone' => 'Europe/Rome',
            ],
            'end' => [
                'dateTime' => date('c', strtotime($data['end_time'])),
                'timeZone' => 'Europe/Rome',
            ],
            'location' => $data['location'] ?? '',
            'extendedProperties' => [
                'private' => [
                    'event_id' => (string)$data['event_id'],
                    'wp_post_id' => (string)$data['event_id'] // Aggiunto per riferimento diretto
                ]
            ]
        ]);
    }

    private function setup_watch($event_id) {
        try {
            if (!$this->init_client()) {
                return false;
            }

            $service = new \Google\Service\Calendar($this->client);
            
            // Estendi la durata a 7 giorni invece di 1
            $expiration = strtotime('+7 days') * 1000;
            
            $channel = new \Google\Service\Calendar\Channel([
                'id' => wp_generate_uuid4(),
                'type' => 'web_hook',
                'address' => rest_url($this->webhook_endpoint),
                'expiration' => $expiration,
                'params' => [
                    'ttl' => '604800', // 7 giorni in secondi
                    'eventId' => $event_id
                ]
            ]);

            try {
                $watchResponse = $service->events->watch('primary', $channel);
                
                // Salva i dettagli del watch
                $watch_data = [
                    'channel_id' => $watchResponse->getId(),
                    'resource_id' => $watchResponse->getResourceId(),
                    'expiration' => $watchResponse->getExpiration(),
                    'created_at' => time()
                ];
                
                update_option('puntotende_gcal_watch_' . $event_id, $watch_data);
                
                // Programma il rinnovo del watch
                $this->schedule_watch_renewal($event_id, $watch_data);
                
                $this->log('Watch configurato per evento: ' . $event_id);
                return true;
            } catch (\Exception $e) {
                $this->log('Errore watch: ' . $e->getMessage());
                return false;
            }
        } catch (\Exception $e) {
            $this->log('Errore setup watch: ' . $e->getMessage());
            return false;
        }
    }

    private function schedule_watch_renewal($event_id, $watch_data) {
        // Calcola il timestamp per il rinnovo (1 giorno prima della scadenza)
        $renewal_time = ($watch_data['expiration'] / 1000) - 86400;
        
        // Aggiungi un'opzione che indica quando deve essere rinnovato il watch
        update_option('puntotende_gcal_renew_' . $event_id, $renewal_time);
        
        // Se stai usando WP-Cron
        if (!wp_next_scheduled('puntotende_gcal_renew_watch', array($event_id))) {
            wp_schedule_single_event($renewal_time, 'puntotende_gcal_renew_watch', array($event_id));
        }
    }
    
    public function renew_watch($event_id) {
        $watch_data = get_option('puntotende_gcal_watch_' . $event_id);
        if (!$watch_data) {
            $this->log('Dati watch non trovati per evento: ' . $event_id);
            return;
        }
        
        // Ferma il vecchio watch
        $this->stop_watch($watch_data['channel_id'], $watch_data['resource_id']);
        
        // Crea un nuovo watch
        $this->setup_watch($event_id);
        
        $this->log('Watch rinnovato per evento: ' . $event_id);
    }
    
    private function stop_watch($channel_id, $resource_id) {
        try {
            if (!$this->init_client()) {
                return false;
            }
            
            $service = new \Google\Service\Calendar($this->client);
            $service->channels->stop(new \Google\Service\Calendar\Channel([
                'id' => $channel_id,
                'resourceId' => $resource_id
            ]));
            
            return true;
        } catch (\Exception $e) {
            $this->log('Errore stop watch: ' . $e->getMessage());
            return false;
        }
    }

    private function debug_request($request, $prefix = '') {
        if ($this->debug_mode) {
            $this->log($prefix . ' - Headers: ' . print_r($request->get_headers(), true));
            $this->log($prefix . ' - Body: ' . print_r($request->get_body(), true));
            $this->log($prefix . ' - Method: ' . $request->get_method());
            $this->log($prefix . ' - Params: ' . print_r($request->get_params(), true));
        }
    }
    
    public function validate_webhook_request($request) {
        $headers = $request->get_headers();
        $webhook_token = get_option('puntotende_gcal_webhook_token', '');
        
        // Verifica se c'è un header X-Goog-Channel-ID (presente nelle richieste autentiche di Google)
        if (isset($headers['x_goog_channel_id'])) {
            return true;
        }
        
        // Verifica che il token sia configurato
        if (empty($webhook_token)) {
            $this->log('Webhook token non configurato');
            return false;
        }
        
        // Verifica tramite query parameter (soluzione alternativa)
        $params = $request->get_params();
        if (isset($params['token']) && $params['token'] === $webhook_token) {
            return true;
        }
        
        $this->log('Tentativo di accesso non autorizzato al webhook');
        return false;
    }

    public function handle_webhook($request) {
        $this->debug_request($request, 'Webhook');
        
        $headers = $request->get_headers();
        $state = isset($headers['x_goog_resource_state']) ? $headers['x_goog_resource_state'][0] : '';
        $resource_id = isset($headers['x_goog_resource_id']) ? $headers['x_goog_resource_id'][0] : '';
        
        $this->log("Webhook state: $state, resource_id: $resource_id");

        try {
            if (!$this->init_client()) {
                throw new \Exception('Client non inizializzato');
            }

            $service = new \Google\Service\Calendar($this->client);

            if ($state === 'sync') {
                // Salva il sync token se disponibile
                $events = $service->events->listEvents('primary', ['showDeleted' => true]);
                if ($events->getNextSyncToken()) {
                    update_option('puntotende_gcal_sync_token', $events->getNextSyncToken());
                }
            } else if ($state === 'exists' || $state === 'delete') {
                // Cerca eventi modificati o eliminati
                try {
                    $sync_token = get_option('puntotende_gcal_sync_token');
                    $params = ['showDeleted' => true];
                    
                    if ($sync_token) {
                        $params['syncToken'] = $sync_token;
                    }

                    $events = $service->events->listEvents('primary', $params);
                    
                    foreach ($events->getItems() as $event) {
                        $this->process_event_update($event);
                    }

                    // Aggiorna il sync token
                    if ($events->getNextSyncToken()) {
                        update_option('puntotende_gcal_sync_token', $events->getNextSyncToken());
                    }
                } catch (\Google\Service\Exception $e) {
                    if ($e->getCode() == 410) {
                        // Token scaduto, rimuovilo
                        delete_option('puntotende_gcal_sync_token');
                    }
                    $this->log('Errore sync: ' . $e->getMessage());
                }
            }
            
            return new WP_REST_Response(['status' => 'success'], 200);
        } catch (\Exception $e) {
            $this->log('Errore webhook: ' . $e->getMessage());
            return new WP_REST_Response(['status' => 'error'], 500);
        }
    }

    public function sync_delete_event($post_id) {
        if (get_post_type($post_id) !== 'appointment') {
            return;
        }

        $gcal_event_id = get_post_meta($post_id, 'gcal_event_id', true);
        if (!empty($gcal_event_id)) {
            do_action('puntotende_gcal_delete_event', $gcal_event_id);
        }
    }

    public function delete_event($event_id) {
        try {
            $this->log('[delete_event] Tentativo eliminazione: ' . $event_id);
            
            if (!$this->init_client() || empty($event_id)) {
                return false;
            }

            $service = new \Google\Service\Calendar($this->client);
            
            try {
                $service->events->delete('primary', $event_id);
                $this->log('[delete_event] Eliminazione completata');
                return true;
            } catch (\Google\Service\Exception $e) {
                $error = json_decode($e->getMessage());
                
                if ($error && isset($error->error->code) && $error->error->code === 410) {
                    $this->log('[delete_event] Evento già eliminato (410)');
                    return true;
                }
                
                throw $e;
            }
        } catch (\Exception $e) {
            $this->log('[delete_event] Errore: ' . $e->getMessage());
            return false;
        }
    }

    public function handle_delete_event($result, $event_id) {
        return $this->delete_event($event_id);
    }

    public function show_token_expired_notice() {
        if (get_option('puntotende_gcal_token_expired') && current_user_can('manage_options')) {
            ?>
            <div class="error">
                <p><strong>PuntoTende Google Calendar:</strong> Il token di accesso è scaduto e non è stato possibile rinnovarlo automaticamente. <a href="<?php echo admin_url('admin.php?page=puntotende-gcal-settings'); ?>">Riconnetti l'account Google</a>.</p>
            </div>
            <?php
            delete_option('puntotende_gcal_token_expired');
        }
    }

    public function check_dependencies() {
        if (!class_exists('Google\\Client')) {
            ?>
            <div class="error">
                <p>PuntoTende CRM - Google Calendar richiede la libreria Google API Client.</p>
                <p>Per installarla, esegui questo comando nella directory del plugin:</p>
                <code>composer require google/apiclient:^2.0</code>
            </div>
            <?php
        }
    }

    public function add_menu() {
        add_submenu_page(
            'puntotende-crm',            // parent slug
            'Google Calendar',           // page title
            'Google Calendar',           // menu title
            'manage_options',            // capability
            'puntotende-gcal-settings',  // menu slug
            [$this, 'render_settings_page'] // callback
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai i permessi per accedere a questa pagina.');
        }
    
        // Crea la directory templates se non esiste
        $templates_dir = PUNTOTENDE_GCAL_PATH . 'templates';
        if (!is_dir($templates_dir)) {
            wp_mkdir_p($templates_dir);
        }
        
        // Crea il file template se non esiste
        $template_file = $templates_dir . '/admin-settings.php';
        if (!file_exists($template_file)) {
            $template_content = '<!-- Questo file è generato automaticamente -->';
            $template_content .= file_get_contents(PUNTOTENDE_GCAL_PATH . 'includes/default-admin-template.php');
            file_put_contents($template_file, $template_content);
        }
    
        // Processa le form
        if (isset($_POST['save_gcal_settings'])) {
            check_admin_referer('puntotende_gcal_settings', 'puntotende_gcal_nonce');
    
            $client_id = sanitize_text_field($_POST['gcal_client_id']);
            $client_secret = sanitize_text_field($_POST['gcal_client_secret']);
            $webhook_token = sanitize_text_field($_POST['webhook_token']);
            $debug_mode = isset($_POST['debug_mode']) ? '1' : '0';
            
            update_option('puntotende_gcal_client_id', $client_id);
            update_option('puntotende_gcal_client_secret', $client_secret);
            update_option('puntotende_gcal_webhook_token', $webhook_token);
            update_option('puntotende_gcal_debug_mode', $debug_mode);
    
            echo '<div class="updated"><p>Impostazioni salvate!</p></div>';
            
            // Aggiorna la modalità di debug nell'istanza corrente
            $this->debug_mode = ($debug_mode === '1');
        }
    
        if (isset($_POST['gcal_disconnect']) && check_admin_referer('puntotende_gcal_disconnect', 'puntotende_gcal_disconnect_nonce')) {
            delete_option('puntotende_gcal_token');
            echo '<div class="updated"><p>Account Google disconnesso con successo!</p></div>';
        }
    
        // Prepara i dati per il template
        $saved_id = get_option('puntotende_gcal_client_id', '');
        $saved_secret = get_option('puntotende_gcal_client_secret', '');
        $webhook_token = get_option('puntotende_gcal_webhook_token', '');
        $token_json = get_option('puntotende_gcal_token', '');
        $token_data = json_decode($token_json, true);
        $debug_mode = get_option('puntotende_gcal_debug_mode', '0');
    
        // Visualizza il template
        if (file_exists($template_file)) {
            include($template_file);
        } else {
            echo '<div class="error"><p>Template delle impostazioni non trovato.</p></div>';
            
            // Mostra un form di base come fallback
            echo '<div class="wrap">';
            echo '<h1>PuntoTende CRM - Impostazioni Google Calendar</h1>';
            echo '<form method="post" action="">';
            wp_nonce_field('puntotende_gcal_settings', 'puntotende_gcal_nonce');
            echo '<p><label>Client ID: <input type="text" name="gcal_client_id" value="' . esc_attr($saved_id) . '" /></label></p>';
            echo '<p><label>Client Secret: <input type="password" name="gcal_client_secret" value="' . esc_attr($saved_secret) . '" /></label></p>';
            echo '<p><label>Webhook Token: <input type="text" name="webhook_token" value="' . esc_attr($webhook_token) . '" /></label></p>';
            echo '<p><label><input type="checkbox" name="debug_mode" value="1" ' . checked('1', $debug_mode, false) . ' /> Modalità Debug</label></p>';
            echo '<p><input type="submit" name="save_gcal_settings" class="button-primary" value="Salva impostazioni" /></p>';
            echo '</form>';
            echo '</div>';
        }
    }

    public function oauth_start() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai i permessi per accedere a questa funzione.');
        }
    
        $client = new \Google\Client();
        $client->setClientId(get_option('puntotende_gcal_client_id'));
        $client->setClientSecret(get_option('puntotende_gcal_client_secret'));
        $client->setRedirectUri(admin_url('admin-post.php?action=puntotende_gcal_oauth_callback'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force'); // Forza sempre l'approvazione per ottenere un refresh token
        $client->setPrompt('consent'); // Mostra sempre la schermata di consenso
        $client->setIncludeGrantedScopes(true); // Include tutti gli scope già concessi
        $client->setScopes([\Google\Service\Calendar::CALENDAR]);
    
        $auth_url = $client->createAuthUrl();
        wp_redirect($auth_url);
        exit;
    }

    public function oauth_callback() {
        if (!current_user_can('manage_options')) {
            wp_die('Non hai i permessi per accedere a questa funzione.');
        }

        if (!isset($_GET['code'])) {
            wp_die('Codice di autorizzazione mancante.');
        }

        try {
            $client = new \Google\Client();
            $client->setClientId(get_option('puntotende_gcal_client_id'));
            $client->setClientSecret(get_option('puntotende_gcal_client_secret'));
            $client->setRedirectUri(admin_url('admin-post.php?action=puntotende_gcal_oauth_callback'));

            $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

            if (!isset($token['error'])) {
                update_option('puntotende_gcal_token', json_encode($token));
                wp_redirect(admin_url('admin.php?page=puntotende-gcal-settings&auth=success'));
                exit;
            } else {
                wp_die('Errore durante l\'autorizzazione: ' . $token['error']);
            }
        } catch (\Exception $e) {
            wp_die('Errore durante l\'autorizzazione: ' . $e->getMessage());
        }
    }

    public function handle_test_api() {
        try {
            check_ajax_referer('test_gcal_api');
            
            if (!current_user_can('manage_options')) {
                throw new \Exception('Permessi insufficienti');
            }

            if (!$this->init_client()) {
                throw new \Exception('Inizializzazione client fallita');
            }

            $service = new \Google\Service\Calendar($this->client);
            $calendarList = $service->calendarList->listCalendarList();

            wp_send_json_success([
                'message' => 'Connessione riuscita! Calendari trovati: ' . count($calendarList->getItems())
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
}