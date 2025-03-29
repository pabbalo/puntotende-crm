<?php
/**
 * PuntoTende CRM - Calendar Sync
 * Gestisce la sincronizzazione bidirezionale con Google Calendar
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTCRM_Calendar_Sync {
    protected $last_sync;
    protected $sync_in_progress = false;
    protected $gcal_client = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Aggiungi endpoint REST API
        add_action('rest_api_init', array($this, 'register_api_routes'));
        
        // Aggiungi hook per sincronizzare automaticamente
        add_action('ptcrm_appointment_created', array($this, 'sync_appointment_to_gcal'), 10, 1);
        add_action('ptcrm_appointment_updated', array($this, 'sync_appointment_to_gcal'), 10, 1);
        add_action('ptcrm_appointment_deleted', array($this, 'delete_gcal_event'), 10, 1);
        
        // Cron job per sincronizzazione periodica
        add_action('ptcrm_sync_calendar_events', array($this, 'scheduled_sync'));
        
        // Inizializza l'ultimo timestamp di sincronizzazione
        $this->last_sync = get_option('ptcrm_last_calendar_sync', 0);
    }
    
    /**
     * Registra endpoint API per la sincronizzazione
     */
    public function register_api_routes() {
        register_rest_route('ptcrm/v1', '/sync/calendar', [
            'methods' => 'GET',
            'callback' => array($this, 'get_sync_status'),
            'permission_callback' => function() {
                return current_user_can('read');
            }
        ]);
        
        register_rest_route('ptcrm/v1', '/sync/calendar', [
            'methods' => 'POST',
            'callback' => array($this, 'trigger_sync'),
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);
    }
    
    /**
     * Ottieni lo stato della sincronizzazione
     */
    public function get_sync_status() {
        return [
            'last_sync' => $this->last_sync,
            'last_sync_formatted' => $this->last_sync ? date('Y-m-d H:i:s', $this->last_sync) : 'Mai',
            'sync_in_progress' => $this->sync_in_progress,
            'calendar_connected' => $this->is_gcal_connected()
        ];
    }
    
    /**
     * Avvia la sincronizzazione manualmente
     */
    public function trigger_sync($request) {
        // Verifica se è già in corso una sincronizzazione
        if ($this->sync_in_progress) {
            return new WP_Error(
                'sync_in_progress', 
                'Sincronizzazione già in corso', 
                ['status' => 409]
            );
        }
        
        // Imposta flag di sincronizzazione
        $this->sync_in_progress = true;
        update_option('ptcrm_sync_in_progress', true);
        
        // Avvia sincronizzazione in background
        $this->schedule_background_sync();
        
        return [
            'success' => true,
            'message' => 'Sincronizzazione avviata'
        ];
    }
    
    /**
     * Pianifica sincronizzazione in background usando WP Cron
     */
    private function schedule_background_sync() {
        if (!wp_next_scheduled('ptcrm_sync_calendar_events')) {
            wp_schedule_single_event(time(), 'ptcrm_sync_calendar_events');
        }
    }
    
    /**
     * Esegue la sincronizzazione pianificata
     */
    public function scheduled_sync() {
        try {
            // Sincronizza da Google Calendar a CRM
            $this->sync_from_gcal();
            
            // Sincronizza da CRM a Google Calendar
            $this->sync_to_gcal();
            
            // Aggiorna timestamp ultima sincronizzazione
            $this->last_sync = time();
            update_option('ptcrm_last_calendar_sync', $this->last_sync);
            
            // Log dell'operazione
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log('Sincronizzazione calendario completata', 'info');
            }
        } catch (Exception $e) {
            // Log dell'errore
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log('Errore nella sincronizzazione: ' . $e->getMessage(), 'error');
            }
        } finally {
            // Rimuovi flag di sincronizzazione
            $this->sync_in_progress = false;
            update_option('ptcrm_sync_in_progress', false);
        }
    }
    
    /**
     * Verifica se Google Calendar è connesso
     */
    private function is_gcal_connected() {
        if (function_exists('ptcrm_gcal_check_token')) {
            $token = ptcrm_gcal_check_token();
            return !empty($token);
        }
        return false;
    }
    
    /**
     * Ottieni il client Google Calendar
     */
    private function get_gcal_client() {
        if ($this->gcal_client) {
            return $this->gcal_client;
        }
        
        if (!function_exists('ptcrm_gcal_check_token')) {
            throw new Exception('Google Calendar non disponibile');
        }
        
        $token = ptcrm_gcal_check_token();
        if (!$token) {
            throw new Exception('Google Calendar non connesso');
        }
        
        $client = new Google\Client();
        $client->setAccessToken($token);
        
        $this->gcal_client = new Google\Service\Calendar($client);
        return $this->gcal_client;
    }
    
    /**
     * Sincronizza gli eventi da Google Calendar a CRM
     */
    private function sync_from_gcal() {
        $client = $this->get_gcal_client();
        $calendarId = get_option('ptcrm_gcal_calendar_id', 'primary');
        
        // Ottiene eventi modificati dall'ultima sincronizzazione
        $last_sync_rfc = date('c', $this->last_sync ?: (time() - 86400*30)); // Default: ultimi 30 giorni
        
        $params = [
            'updatedMin' => $last_sync_rfc,
            'singleEvents' => true,
            'maxResults' => 2500
        ];
        
        do {
            $events = $client->events->listEvents($calendarId, $params);
            
            foreach ($events->getItems() as $event) {
                $this->process_gcal_event($event, $calendarId);
            }
            
            $pageToken = $events->getNextPageToken();
            $params['pageToken'] = $pageToken;
        } while ($pageToken);
    }
    
    /**
     * Elabora un singolo evento da Google Calendar
     */
    private function process_gcal_event($event, $calendarId) {
        // Salta eventi cancellati
        if ($event->getStatus() === 'cancelled') {
            $this->delete_crm_appointment_by_gcal_id($event->getId());
            return;
        }
        
        // Cerca se esiste già in CRM
        $appointment_id = $this->get_appointment_by_gcal_id($event->getId());
        
        // Prepara i dati dell'appuntamento
        $appointment_data = [
            'title' => $event->getSummary(),
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'start_time' => $event->getStart()->dateTime ?: $event->getStart()->date,
            'end_time' => $event->getEnd()->dateTime ?: $event->getEnd()->date,
            'gcal_event_id' => $event->getId(),
            'gcal_calendar_id' => $calendarId
        ];
        
        // Cerca di associare un cliente esistente in base alla descrizione o all'email
        $appointment_data['customer_id'] = $this->find_customer_from_event($event);
        
        if ($appointment_id) {
            // Aggiorna appuntamento esistente
            $this->update_crm_appointment($appointment_id, $appointment_data);
        } else {
            // Crea nuovo appuntamento
            $this->create_crm_appointment($appointment_data);
        }
    }
    
    /**
     * Trova un cliente in base ai dati dell'evento
     */
    private function find_customer_from_event($event) {
        $customer_id = 0;
        $description = $event->getDescription();
        
        // Cerca ID cliente nel formato "Customer ID: 123"
        if (preg_match('/Customer ID: (\d+)/', $description, $matches)) {
            $customer_id = intval($matches[1]);
            
            // Verifica che il cliente esista
            if ($this->customer_exists($customer_id)) {
                return $customer_id;
            }
        }
        
        // Cerca per email
        $attendees = $event->getAttendees();
        if ($attendees) {
            foreach ($attendees as $attendee) {
                $email = $attendee->getEmail();
                if ($email) {
                    $customer_id = $this->get_customer_by_email($email);
                    if ($customer_id) {
                        return $customer_id;
                    }
                }
            }
        }
        
        return 0; // Nessun cliente trovato
    }
    
    /**
     * Sincronizza appuntamenti dal CRM a Google Calendar
     */
    private function sync_to_gcal() {
        // Ottieni appuntamenti modificati dall'ultima sincronizzazione
        $args = [
            'post_type' => 'ptcrm_appointment',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_last_modified',
                    'value' => $this->last_sync,
                    'compare' => '>',
                    'type' => 'NUMERIC'
                ]
            ]
        ];
        
        $appointments = get_posts($args);
        
        foreach ($appointments as $appointment) {
            $this->sync_appointment_to_gcal($appointment->ID);
        }
    }
    
    /**
     * Sincronizza un singolo appuntamento con Google Calendar
     */
    public function sync_appointment_to_gcal($appointment_id) {
        if (!$this->is_gcal_connected()) {
            return false;
        }
        
        try {
            $appointment = $this->get_appointment($appointment_id);
            if (!$appointment) {
                return false;
            }
            
            // Controlla se l'appuntamento ha già un ID evento Google Calendar
            $gcal_event_id = get_post_meta($appointment_id, '_gcal_event_id', true);
            
            // Prepara i dati dell'evento
            $event_data = $this->prepare_gcal_event_data($appointment);
            
            // Ottieni il client
            $client = $this->get_gcal_client();
            $calendarId = get_option('ptcrm_gcal_calendar_id', 'primary');
            
            if ($gcal_event_id) {
                // Aggiorna evento esistente
                $event = $client->events->get($calendarId, $gcal_event_id);
                
                // Aggiorna i campi
                $event->setSummary($event_data['summary']);
                $event->setDescription($event_data['description']);
                $event->setStart($event_data['start']);
                $event->setEnd($event_data['end']);
                
                if (!empty($event_data['location'])) {
                    $event->setLocation($event_data['location']);
                }
                
                $updated_event = $client->events->update($calendarId, $gcal_event_id, $event);
                
                if (function_exists('ptcrm_gcal_log')) {
                    ptcrm_gcal_log("Evento Google Calendar aggiornato: {$gcal_event_id}", 'info');
                }
                
                return $updated_event->getId();
            } else {
                // Crea nuovo evento
                $event = new Google\Service\Calendar\Event([
                    'summary' => $event_data['summary'],
                    'description' => $event_data['description'],
                    'start' => $event_data['start'],
                    'end' => $event_data['end']
                ]);
                
                if (!empty($event_data['location'])) {
                    $event->setLocation($event_data['location']);
                }
                
                $created_event = $client->events->insert($calendarId, $event);
                
                // Salva l'ID evento Google Calendar nel post meta
                update_post_meta($appointment_id, '_gcal_event_id', $created_event->getId());
                update_post_meta($appointment_id, '_gcal_calendar_id', $calendarId);
                
                if (function_exists('ptcrm_gcal_log')) {
                    ptcrm_gcal_log("Nuovo evento Google Calendar creato: {$created_event->getId()}", 'info');
                }
                
                return $created_event->getId();
            }
        } catch (Exception $e) {
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log("Errore sincronizzazione appuntamento {$appointment_id}: {$e->getMessage()}", 'error');
            }
            return false;
        }
    }
    
    /**
     * Elimina un evento da Google Calendar
     */
    public function delete_gcal_event($appointment_id) {
        if (!$this->is_gcal_connected()) {
            return false;
        }
        
        try {
            $gcal_event_id = get_post_meta($appointment_id, '_gcal_event_id', true);
            $gcal_calendar_id = get_post_meta($appointment_id, '_gcal_calendar_id', true) ?: get_option('ptcrm_gcal_calendar_id', 'primary');
            
            if (!$gcal_event_id) {
                return false;
            }
            
            $client = $this->get_gcal_client();
            $client->events->delete($gcal_calendar_id, $gcal_event_id);
            
            delete_post_meta($appointment_id, '_gcal_event_id');
            delete_post_meta($appointment_id, '_gcal_calendar_id');
            
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log("Evento Google Calendar eliminato: {$gcal_event_id}", 'info');
            }
            
            return true;
        } catch (Exception $e) {
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log("Errore eliminazione evento Google Calendar: {$e->getMessage()}", 'error');
            }
            return false;
        }
    }
    
    /**
     * Prepara i dati dell'evento per Google Calendar
     */
    private function prepare_gcal_event_data($appointment) {
        // Ottieni i dati del cliente
        $customer = $this->get_customer($appointment['customer_id']);
        $customer_name = $customer ? $customer['name'] : 'Cliente non specificato';
        
        // Descrizione con info cliente
        $description = "Appuntamento con {$customer_name}\n\n";
        $description .= $appointment['description'] ? $appointment['description'] . "\n\n" : '';
        $description .= "Customer ID: {$appointment['customer_id']}\n";
        $description .= "Appuntamento ID: {$appointment['id']}";
        
        // Date di inizio e fine
        $start_time = new DateTime($appointment['start_time']);
        $end_time = new DateTime($appointment['end_time']);
        
        // Verifica se è un giorno intero o un appuntamento orario
        $is_all_day = $start_time->format('H:i:s') === '00:00:00' && $end_time->format('H:i:s') === '00:00:00';
        
        if ($is_all_day) {
            $start = [
                'date' => $start_time->format('Y-m-d')
            ];
            $end = [
                'date' => $end_time->format('Y-m-d')
            ];
        } else {
            $start = [
                'dateTime' => $start_time->format('c')
            ];
            $end = [
                'dateTime' => $end_time->format('c')
            ];
        }
        
        return [
            'summary' => $appointment['title'],
            'description' => $description,
            'location' => $appointment['location'] ?? '',
            'start' => $start,
            'end' => $end
        ];
    }
    
    /**
     * Ottieni appuntamento per ID
     */
    private function get_appointment($appointment_id) {
        // Implementazione specifica per il tuo CRM
        $post = get_post($appointment_id);
        
        if (!$post || $post->post_type !== 'ptcrm_appointment') {
            return null;
        }
        
        $start_time = get_post_meta($appointment_id, '_start_time', true);
        $duration = get_post_meta($appointment_id, '_duration', true) ?: 60;
        
        // Calcola ora fine
        $end_time = date('Y-m-d H:i:s', strtotime($start_time) + ($duration * 60));
        
        return [
            'id' => $appointment_id,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'customer_id' => get_post_meta($appointment_id, '_customer_id', true),
            'location' => get_post_meta($appointment_id, '_location', true),
            'start_time' => $start_time,
            'end_time' => $end_time
        ];
    }
    
    /**
     * Ottieni cliente per ID
     */
    private function get_customer($customer_id) {
        if (!$customer_id) return null;
        
        // Implementazione specifica per il tuo CRM
        $customer_post = get_post($customer_id);
        
        if (!$customer_post || $customer_post->post_type !== 'ptcrm_customer') {
            return null;
        }
        
        return [
            'id' => $customer_id,
            'name' => $customer_post->post_title,
            'email' => get_post_meta($customer_id, '_email', true),
            'phone' => get_post_meta($customer_id, '_phone', true),
            'address' => get_post_meta($customer_id, '_address', true)
        ];
    }
    
    /**
     * Verifica se un cliente esiste
     */
    private function customer_exists($customer_id) {
        $customer = get_post($customer_id);
        return $customer && $customer->post_type === 'ptcrm_customer';
    }
    
    /**
     * Ottieni cliente per email
     */
    private function get_customer_by_email($email) {
        $args = [
            'post_type' => 'ptcrm_customer',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_email',
                    'value' => $email,
                    'compare' => '='
                ]
            ]
        ];
        
        $customers = get_posts($args);
        
        if (!empty($customers)) {
            return $customers[0]->ID;
        }
        
        return 0;
    }
    
    /**
     * Ottieni appuntamento per ID evento Google Calendar
     */
    private function get_appointment_by_gcal_id($gcal_event_id) {
        $args = [
            'post_type' => 'ptcrm_appointment',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_gcal_event_id',
                    'value' => $gcal_event_id,
                    'compare' => '='
                ]
            ]
        ];
        
        $appointments = get_posts($args);
        
        if (!empty($appointments)) {
            return $appointments[0]->ID;
        }
        
        return 0;
    }
    
    /**
     * Elimina appuntamento per ID evento Google Calendar
     */
    private function delete_crm_appointment_by_gcal_id($gcal_event_id) {
        $appointment_id = $this->get_appointment_by_gcal_id($gcal_event_id);
        
        if ($appointment_id) {
            wp_delete_post($appointment_id, true);
            
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log("Appuntamento {$appointment_id} eliminato perché l'evento Google Calendar è stato cancellato", 'info');
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Crea nuovo appuntamento nel CRM
     */
    private function create_crm_appointment($appointment_data) {
        // Implementazione specifica per il tuo CRM
        $post_data = [
            'post_title' => $appointment_data['title'],
            'post_content' => $appointment_data['description'] ?? '',
            'post_type' => 'ptcrm_appointment',
            'post_status' => 'publish'
        ];
        
        $appointment_id = wp_insert_post($post_data);
        
        if ($appointment_id) {
            update_post_meta($appointment_id, '_start_time', $appointment_data['start_time']);
            
            // Calcola durata in minuti
            $start = new DateTime($appointment_data['start_time']);
            $end = new DateTime($appointment_data['end_time']);
            $duration = ($end->getTimestamp() - $start->getTimestamp()) / 60;
            update_post_meta($appointment_id, '_duration', $duration);
            
            // Altri meta
            update_post_meta($appointment_id, '_customer_id', $appointment_data['customer_id']);
            update_post_meta($appointment_id, '_location', $appointment_data['location']);
            update_post_meta($appointment_id, '_gcal_event_id', $appointment_data['gcal_event_id']);
            update_post_meta($appointment_id, '_gcal_calendar_id', $appointment_data['gcal_calendar_id']);
            update_post_meta($appointment_id, '_last_modified', time());
            
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log("Nuovo appuntamento {$appointment_id} creato da Google Calendar", 'info');
            }
            
            return $appointment_id;
        }
        
        return 0;
    }
    
    /**
     * Aggiorna un appuntamento esistente nel CRM
     */
    private function update_crm_appointment($appointment_id, $appointment_data) {
        $post_data = [
            'ID' => $appointment_id,
            'post_title' => $appointment_data['title'],
            'post_content' => $appointment_data['description'] ?? ''
        ];
        
        $result = wp_update_post($post_data);
        
        if ($result) {
            update_post_meta($appointment_id, '_start_time', $appointment_data['start_time']);
            
            // Calcola durata in minuti
            $start = new DateTime($appointment_data['start_time']);
            $end = new DateTime($appointment_data['end_time']);
            $duration = ($end->getTimestamp() - $start->getTimestamp()) / 60;
            update_post_meta($appointment_id, '_duration', $duration);
            
            // Altri meta
            if ($appointment_data['customer_id']) {
                update_post_meta($appointment_id, '_customer_id', $appointment_data['customer_id']);
            }
            
            update_post_meta($appointment_id, '_location', $appointment_data['location']);
            update_post_meta($appointment_id, '_last_modified', time());
            
            if (function_exists('ptcrm_gcal_log')) {
                ptcrm_gcal_log("Appuntamento {$appointment_id} aggiornato da Google Calendar", 'info');
            }
            
            return true;
        }
        
        return false;
    }
}

// Inizializza la classe
new PTCRM_Calendar_Sync();