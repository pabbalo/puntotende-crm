<?php
class PuntoTende_GCal {
    private static $instance = null;
    private $client = null;
    private $webhook_endpoint = 'puntotende/v1/gcal-webhook';

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

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 20);
        add_action('admin_notices', [$this, 'check_dependencies']);
        add_action('admin_post_puntotende_gcal_oauth_start', [$this, 'oauth_start']);
        add_action('admin_post_puntotende_gcal_oauth_callback', [$this, 'oauth_callback']);
        add_action('wp_ajax_test_gcal_api', [$this, 'handle_test_api']);
        
        // Registra l'endpoint webhook con priorità alta
        add_action('rest_api_init', function() {
            register_rest_route('puntotende/v1', '/gcal-webhook', [
                'methods' => ['GET', 'POST', 'DELETE'],
                'callback' => [$this, 'handle_webhook'],
                'permission_callback' => '__return_true' // Per debug
            ]);
        }, 1);

        // Hook per sincronizzazione eventi
        add_action('before_delete_post', [$this, 'sync_delete_event'], 10, 1);
        add_filter('puntotende_gcal_delete_event', [$this, 'handle_delete_event'], 10, 2);
    }
    private function process_event_update($event) {
        $event_id = $event->getId();
        $status = $event->getStatus();
        
        error_log("PuntoTende GCal - Processando evento {$event_id} con status {$status}");
    
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
                
                error_log("PuntoTende GCal - Appuntamento {$appointment->id} eliminato");
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
                error_log("PuntoTende GCal - Appuntamento {$appointment->id} aggiornato");
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

            $token = json_decode(get_option('puntotende_gcal_token', ''), true);
            if (empty($token)) {
                return false;
            }

            $this->client->setAccessToken($token);
            
            if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
                $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                update_option('puntotende_gcal_token', json_encode($this->client->getAccessToken()));
            }

            return true;
        } catch (\Exception $e) {
            error_log('PuntoTende GCal - Errore client: ' . $e->getMessage());
            return false;
        }
    }

    public function create_event($event_data) {
        try {
            error_log('PuntoTende GCal - Tentativo creazione evento: ' . print_r($event_data, true));

            if (!$this->init_client()) {
                throw new \Exception('Client non inizializzato');
            }

            $service = new \Google\Service\Calendar($this->client);
            $event = $this->prepare_event($event_data);
            
            $created_event = $service->events->insert('primary', $event);
            error_log('PuntoTende GCal - Evento creato con ID: ' . $created_event->id);
            
            if ($this->setup_watch($created_event->id)) {
                error_log('PuntoTende GCal - Watch configurato con successo');
            }
            
            return $created_event->id;
        } catch (\Exception $e) {
            error_log('PuntoTende GCal - Errore creazione: ' . $e->getMessage());
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
        
        $channel = new \Google\Service\Calendar\Channel([
            'id' => wp_generate_uuid4(),
            'type' => 'web_hook',
            'address' => rest_url($this->webhook_endpoint),
            'expiration' => strtotime('+1 day') * 1000,
            'params' => [
                'ttl' => '86400',
                'eventId' => $event_id
            ]
        ]);

        try {
            $watchResponse = $service->events->watch('primary', $channel);
            
            // Salva i dettagli del watch
            update_option('puntotende_gcal_watch_' . $event_id, [
                'channel_id' => $watchResponse->getId(),
                'resource_id' => $watchResponse->getResourceId(),
                'expiration' => $watchResponse->getExpiration()
            ]);
            
            error_log('PuntoTende GCal - Watch configurato per evento: ' . $event_id);
            return true;
        } catch (\Exception $e) {
            error_log('PuntoTende GCal - Errore watch: ' . $e->getMessage());
            return false;
        }
    } catch (\Exception $e) {
        error_log('PuntoTende GCal - Errore setup watch: ' . $e->getMessage());
        return false;
    }
}


    private function debug_request($request, $prefix = '') {
        error_log($prefix . ' - Headers: ' . print_r($request->get_headers(), true));
        error_log($prefix . ' - Body: ' . print_r($request->get_body(), true));
        error_log($prefix . ' - Method: ' . $request->get_method());
        error_log($prefix . ' - Params: ' . print_r($request->get_params(), true));
    }

    public function handle_webhook($request) {
    $this->debug_request($request, 'PuntoTende GCal - Webhook');
    
    $headers = $request->get_headers();
    $state = isset($headers['x_goog_resource_state']) ? $headers['x_goog_resource_state'][0] : '';
    $resource_id = isset($headers['x_goog_resource_id']) ? $headers['x_goog_resource_id'][0] : '';
    
    error_log("PuntoTende GCal - Webhook state: $state, resource_id: $resource_id");

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
                error_log('PuntoTende GCal - Errore sync: ' . $e->getMessage());
            }
        }
        
        return new WP_REST_Response(['status' => 'success'], 200);
    } catch (\Exception $e) {
        error_log('PuntoTende GCal - Errore webhook: ' . $e->getMessage());
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
            error_log('PuntoTende GCal - [delete_event] Tentativo eliminazione: ' . $event_id);
            
            if (!$this->init_client() || empty($event_id)) {
                return false;
            }

            $service = new \Google\Service\Calendar($this->client);
            
            try {
                $service->events->delete('primary', $event_id);
                error_log('PuntoTende GCal - [delete_event] Eliminazione completata');
                return true;
            } catch (\Google\Service\Exception $e) {
                $error = json_decode($e->getMessage());
                
                if ($error && isset($error->error->code) && $error->error->code === 410) {
                    error_log('PuntoTende GCal - [delete_event] Evento già eliminato (410)');
                    return true;
                }
                
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('PuntoTende GCal - [delete_event] Errore: ' . $e->getMessage());
            return false;
        }
    }

    // ... (resto dei metodi invariati come nel tuo codice originale)

    // Manteniamo handle_delete_event come wrapper per retrocompatibilità
    public function handle_delete_event($result, $event_id) {
        return $this->delete_event($event_id);
    }


    // Includi qui il resto dei metodi della classe originale che non sono stati modificati
    // Metodi per l'admin e OAuth mantenuti dal file originale
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

        if (isset($_POST['save_gcal_settings'])) {
            check_admin_referer('puntotende_gcal_settings', 'puntotende_gcal_nonce');

            $client_id = sanitize_text_field($_POST['gcal_client_id']);
            $client_secret = sanitize_text_field($_POST['gcal_client_secret']);
            $webhook_token = sanitize_text_field($_POST['webhook_token']);
            
            update_option('puntotende_gcal_client_id', $client_id);
            update_option('puntotende_gcal_client_secret', $client_secret);
            update_option('puntotende_gcal_webhook_token', $webhook_token);

            echo '<div class="updated"><p>Impostazioni salvate!</p></div>';
        }

        if (isset($_POST['gcal_disconnect']) && check_admin_referer('puntotende_gcal_disconnect', 'puntotende_gcal_disconnect_nonce')) {
            delete_option('puntotende_gcal_token');
            echo '<div class="updated"><p>Account Google disconnesso con successo!</p></div>';
        }

        $saved_id = get_option('puntotende_gcal_client_id', '');
        $saved_secret = get_option('puntotende_gcal_client_secret', '');
        $webhook_token = get_option('puntotende_gcal_webhook_token', '');
        $token_json = get_option('puntotende_gcal_token', '');
        $token_data = json_decode($token_json, true);

        include(PUNTOTENDE_GCAL_PATH . 'templates/admin-settings.php');
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
        $client->setPrompt('consent');
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