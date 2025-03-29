<?php
/**
 * Endpoint API per l'app mobile PuntoTende CRM
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra tutti gli endpoint API necessari per l'app mobile
 */
function ptcrm_register_mobile_api_routes() {
    // Autenticazione e verifica utente
    register_rest_route('ptcrm/v1', '/auth/status', [
        'methods' => 'GET',
        'callback' => 'ptcrm_api_auth_status',
        'permission_callback' => '__return_true'
    ]);
    
    // Endpoint per dashboard
    register_rest_route('ptcrm/v1', '/dashboard', [
        'methods' => 'GET',
        'callback' => 'ptcrm_api_get_dashboard',
        'permission_callback' => function() {
            return current_user_can('read');
        }
    ]);
    
    // Clienti
    register_rest_route('ptcrm/v1', '/customers', [
        'methods' => 'GET',
        'callback' => 'ptcrm_api_get_customers',
        'permission_callback' => function() {
            return current_user_can('read');
        }
    ]);
    
    register_rest_route('ptcrm/v1', '/customers/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'ptcrm_api_get_single_customer',
        'permission_callback' => function() {
            return current_user_can('read');
        }
    ]);
    
    // Appuntamenti
    register_rest_route('ptcrm/v1', '/appointments', [
        'methods' => 'GET',
        'callback' => 'ptcrm_api_get_appointments',
        'permission_callback' => function() {
            return current_user_can('read');
        }
    ]);
    
    register_rest_route('ptcrm/v1', '/appointments', [
        'methods' => 'POST',
        'callback' => 'ptcrm_api_create_appointment',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        }
    ]);
    
    // Google Calendar (usando gli endpoint che abbiamo già creato)
    register_rest_route('ptcrm/v1', '/calendar/events', [
        'methods' => 'GET',
        'callback' => 'ptcrm_api_get_calendar_events',
        'permission_callback' => function() {
            return current_user_can('read');
        }
    ]);
}
add_action('rest_api_init', 'ptcrm_register_mobile_api_routes');

/**
 * Verifica stato autenticazione
 */
function ptcrm_api_auth_status() {
    $current_user = wp_get_current_user();
    $logged_in = is_user_logged_in();
    
    return [
        'logged_in' => $logged_in,
        'user' => $logged_in ? [
            'id' => $current_user->ID,
            'name' => $current_user->display_name,
            'avatar' => get_avatar_url($current_user->ID)
        ] : null
    ];
}

/**
 * Ottiene dati dashboard
 */
function ptcrm_api_get_dashboard() {
    // Recupera dati di riepilogo
    $upcoming_appointments = ptcrm_get_upcoming_appointments(5);
    $recent_customers = ptcrm_get_recent_customers(5);
    $stats = ptcrm_get_stats();
    
    return [
        'upcoming_appointments' => $upcoming_appointments,
        'recent_customers' => $recent_customers,
        'stats' => $stats
    ];
}

/**
 * Ottiene lista clienti
 */
function ptcrm_api_get_customers($request) {
    $search = sanitize_text_field($request->get_param('search') ?? '');
    $page = (int)($request->get_param('page') ?? 1);
    $per_page = (int)($request->get_param('per_page') ?? 20);
    
    // Ottieni i clienti dal database
    $customers = ptcrm_get_customers([
        'search' => $search,
        'page' => $page,
        'per_page' => $per_page
    ]);
    
    return $customers;
}

/**
 * Ottiene singolo cliente
 */
function ptcrm_api_get_single_customer($request) {
    $id = (int)$request['id'];
    
    $customer = ptcrm_get_customer($id);
    if (!$customer) {
        return new WP_Error('customer_not_found', 'Cliente non trovato', ['status' => 404]);
    }
    
    // Ottieni anche gli appuntamenti di questo cliente
    $appointments = ptcrm_get_customer_appointments($id);
    
    return [
        'customer' => $customer,
        'appointments' => $appointments
    ];
}

/**
 * Ottiene appuntamenti
 */
function ptcrm_api_get_appointments($request) {
    $start_date = sanitize_text_field($request->get_param('start') ?? '');
    $end_date = sanitize_text_field($request->get_param('end') ?? '');
    $customer_id = (int)($request->get_param('customer_id') ?? 0);
    
    $appointments = ptcrm_get_appointments([
        'start_date' => $start_date,
        'end_date' => $end_date,
        'customer_id' => $customer_id
    ]);
    
    return $appointments;
}

/**
 * Crea nuovo appuntamento
 */
function ptcrm_api_create_appointment($request) {
    $data = $request->get_json_params();
    
    // Valida i dati
    if (empty($data['title']) || empty($data['date']) || empty($data['customer_id'])) {
        return new WP_Error('missing_fields', 'Campi obbligatori mancanti', ['status' => 400]);
    }
    
    // Crea l'appuntamento
    $appointment_id = ptcrm_create_appointment([
        'title' => sanitize_text_field($data['title']),
        'date' => sanitize_text_field($data['date']),
        'customer_id' => (int)$data['customer_id'],
        'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
        'duration' => isset($data['duration']) ? (int)$data['duration'] : 60,
    ]);
    
    if (!$appointment_id) {
        return new WP_Error('appointment_error', 'Errore nella creazione dell\'appuntamento', ['status' => 500]);
    }
    
    // Se abbiamo l'integrazione con Google Calendar, crea anche l'evento
    if (function_exists('ptcrm_gcal_check_token')) {
        $token = ptcrm_gcal_check_token();
        if ($token) {
            // Converte l'appuntamento in evento Google Calendar
            $gcal_event = ptcrm_convert_appointment_to_gcal_event($appointment_id);
            
            // Usa il filtro che abbiamo creato nel plugin Google Calendar
            $event_id = apply_filters('puntotende_gcal_create_event', false, $gcal_event);
            
            // Se la creazione ha avuto successo, salva l'ID evento nell'appuntamento
            if ($event_id) {
                update_post_meta($appointment_id, '_gcal_event_id', $event_id);
            }
        }
    }
    
    return [
        'id' => $appointment_id,
        'message' => 'Appuntamento creato con successo'
    ];
}

/**
 * Ottiene eventi Google Calendar
 */
function ptcrm_api_get_calendar_events($request) {
    if (!function_exists('ptcrm_gcal_check_token')) {
        return new WP_Error('gcal_not_available', 'Integrazione Google Calendar non disponibile', ['status' => 404]);
    }
    
    $token = ptcrm_gcal_check_token();
    if (!$token) {
        return new WP_Error('gcal_not_connected', 'Google Calendar non connesso', ['status' => 403]);
    }
    
    try {
        $client = new Google\Client();
        $client->setAccessToken($token);
        
        $service = new Google\Service\Calendar($client);
        $calendarId = get_option('ptcrm_gcal_calendar_id', 'primary');
        
        // Parametri opzionali
        $start = $request->get_param('start') ?? date('c');
        $end = $request->get_param('end') ?? date('c', strtotime('+30 days'));
        
        $optParams = [
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => $start,
            'timeMax' => $end
        ];
        
        $results = $service->events->listEvents($calendarId, $optParams);
        $events = [];
        
        foreach ($results->getItems() as $event) {
            $events[] = [
                'id' => $event->getId(),
                'title' => $event->getSummary(),
                'start' => $event->getStart()->dateTime ?: $event->getStart()->date,
                'end' => $event->getEnd()->dateTime ?: $event->getEnd()->date,
                'description' => $event->getDescription(),
                'location' => $event->getLocation(),
                'url' => $event->getHtmlLink()
            ];
        }
        
        return $events;
    } catch (Exception $e) {
        return new WP_Error('gcal_error', $e->getMessage(), ['status' => 500]);
    }
}

/**
 * Converte un appuntamento in evento Google Calendar
 */
function ptcrm_convert_appointment_to_gcal_event($appointment_id) {
    $appointment = ptcrm_get_appointment($appointment_id);
    $customer = ptcrm_get_customer($appointment['customer_id']);
    
    // Costruisci i dati evento
    $start_time = new DateTime($appointment['date']);
    $end_time = clone $start_time;
    $end_time->add(new DateInterval('PT' . $appointment['duration'] . 'M')); // Aggiungi durata in minuti
    
    $event_data = [
        'summary' => $appointment['title'],
        'description' => "Appuntamento con {$customer['name']}\n\n{$appointment['notes']}",
        'start' => [
            'dateTime' => $start_time->format('c')
        ],
        'end' => [
            'dateTime' => $end_time->format('c')
        ]
    ];
    
    if (!empty($customer['address'])) {
        $event_data['location'] = $customer['address'];
    }
    
    return $event_data;
}