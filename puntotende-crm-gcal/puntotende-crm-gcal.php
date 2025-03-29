<?php
/**
 * Plugin Name: PuntoTende CRM - Google Calendar
 * Plugin URI: https://www.puntotende.it/
 * Description: Integrazione con Google Calendar (OAuth)
 * Version: 1.0.1
 * Author: Puntotende
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('PUNTOTENDE_GCAL_PATH', plugin_dir_path(__FILE__));
define('PUNTOTENDE_GCAL_URL', plugin_dir_url(__FILE__));
define('PUNTOTENDE_GCAL_VERSION', '1.0.1');


// Verifica e carica l'autoloader di Composer
$autoloader = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
    // Debug
    error_log('PuntoTende GCal - Autoloader trovato e caricato');
} else {
    error_log('PuntoTende GCal - Autoloader non trovato in: ' . $autoloader);
    
    // Mostra messaggio di errore nell'admin
    add_action('admin_notices', function() {
        ?>
        <div class="error">
            <p>PuntoTende CRM - Google Calendar: librerie mancanti. Esegui "composer install" nella directory del plugin.</p>
        </div>
        <?php
    });
    return;
}

/**
 * Register plugin settings
 */
function ptcrm_gcal_register_settings() {
    // Register the settings group
    register_setting(
        'ptcrm_gcal_settings', // Option group
        'ptcrm_gcal_client_id', // Option name
        array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );
    
    register_setting(
        'ptcrm_gcal_settings',
        'ptcrm_gcal_client_secret',
        array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );
    
    register_setting(
        'ptcrm_gcal_settings',
        'ptcrm_gcal_calendar_id',
        array(
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );
}
add_action('admin_init', 'ptcrm_gcal_register_settings');

/**
 * Add admin menu item - use add_menu_page if parent menu doesn't exist
 */
function ptcrm_gcal_add_admin_menu() {
    // Check if the parent menu exists - if not, create a top-level menu
    global $submenu;
    $has_parent = false;
    
    if (isset($submenu['puntotende-crm'])) {
        $has_parent = true;
    }
    
    if ($has_parent) {
        // Add as submenu to existing menu
        add_submenu_page(
            'puntotende-crm', // Parent slug
            'Google Calendar', // Page title
            'Google Calendar', // Menu title
            'manage_options', // Capability
            'puntotende-crm-gcal-settings', // Menu slug
            'ptcrm_gcal_admin_page' // Function to display the page
        );
    } else {
        // Create a top-level menu
        add_menu_page(
            'PuntoTende Google Calendar', // Page title
            'PuntoTende GCal', // Menu title
            'manage_options', // Capability
            'puntotende-crm-gcal-settings', // Menu slug
            'ptcrm_gcal_admin_page', // Function to display the page
            'dashicons-calendar-alt' // Icon
        );
    }
}
add_action('admin_menu', 'ptcrm_gcal_add_admin_menu');


/**
 * Handle all admin requests including OAuth flow
 */
function ptcrm_gcal_admin_init() {
    // Only process if we're on our admin page or handling auth
    if (!isset($_GET['page']) || $_GET['page'] !== 'puntotende-crm-gcal-settings') {
        return;
    }
    
    // Check for auth parameter
    if (isset($_GET['auth']) && $_GET['auth'] == '1') {
        // Make sure the user has permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'puntotende-crm-gcal'));
        }
        
        ptcrm_gcal_handle_authorization();
    }
}
add_action('admin_init', 'ptcrm_gcal_admin_init', 1); // Priority 1 to run early

/**
 * Render admin page
 */
function ptcrm_gcal_admin_page() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'puntotende-crm-gcal'));
    }
    
    // Include the settings template
    include(PUNTOTENDE_GCAL_PATH . 'templates/admin-settings.php');
}

/**
 * Handle the Google Calendar authorization flow
 */
function ptcrm_gcal_handle_authorization() {
    try {
        $client_id = get_option('ptcrm_gcal_client_id');
        $client_secret = get_option('ptcrm_gcal_client_secret');
        
        if (empty($client_id) || empty($client_secret)) {
            wp_die(__('Client ID and Client Secret must be configured first.', 'puntotende-crm-gcal'));
        }
        
        // Create Google Client
        if (!class_exists('Google\Client')) {
            wp_die('Google API Client library not found. Please ensure it is installed properly.');
        }
        
        $client = new Google\Client();
        $client->setClientId($client_id);
        $client->setClientSecret($client_secret);
        $client->setRedirectUri(admin_url('admin.php?page=puntotende-crm-gcal-settings&auth=1'));
        
        // Make sure Calendar class exists
        if (class_exists('Google\Service\Calendar')) {
            $client->addScope(Google\Service\Calendar::CALENDAR);
        } else {
            wp_die('Google Calendar API not found. Please ensure the Google API Client is installed properly.');
        }
        
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        
        // If we have an authorization code, exchange it for an access token
        if (isset($_GET['code'])) {
            $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
            
            if (isset($token['error'])) {
                wp_die('Error fetching access token: ' . $token['error_description']);
            }
            
            update_option('ptcrm_gcal_token', $token);
            
            // Redirect to the settings page
            wp_redirect(admin_url('admin.php?page=puntotende-crm-gcal-settings&auth_success=1'));
            exit;
        }
        
        // If we don't have an authorization code, redirect to Google's OAuth page
        $auth_url = $client->createAuthUrl();
        wp_redirect($auth_url);
        exit;
        
    } catch (Exception $e) {
        // Log and display any errors
        error_log('Google Calendar Auth Error: ' . $e->getMessage());
        wp_die('Error during authorization: ' . $e->getMessage());
    }
}

// Initialize plugin
function puntotende_gcal_init() {
    // Verifica che la classe Google\Client sia disponibile
    if (!class_exists('Google\Client')) {
        error_log('PuntoTende GCal - Google\Client class non trovata dopo il caricamento dell\'autoloader');
        return;
    }
    
    // Include the main class file
    if (file_exists(PUNTOTENDE_GCAL_PATH . 'includes/class-puntotende-gcal.php')) {
        require_once PUNTOTENDE_GCAL_PATH . 'includes/class-puntotende-gcal.php';
        
        // Initialize the class
        if (class_exists('PuntoTende_GCal')) {
            PuntoTende_GCal::get_instance();
        }
    } else {
        error_log('PuntoTende GCal - File class-puntotende-gcal.php non trovato');
    }
}



//FUNZIONI NUOVE ------------------------------------------------------------------------------------------

// Aggiungere dopo le altre funzioni principali
/**
 * Verifica e aggiorna il token se necessario
 */
function ptcrm_gcal_check_token() {
    $token = get_option('ptcrm_gcal_token');
    
    if (empty($token)) {
        return false;
    }
    
    // Crea client Google
    $client = new Google\Client();
    $client->setClientId(get_option('ptcrm_gcal_client_id'));
    $client->setClientSecret(get_option('ptcrm_gcal_client_secret'));
    
    // Imposta token esistente
    $client->setAccessToken($token);
    
    // Verifica se il token è scaduto
    if ($client->isAccessTokenExpired()) {
        // Aggiorna il token se abbiamo un refresh token
        if (isset($token['refresh_token'])) {
            $new_token = $client->fetchAccessTokenWithRefreshToken($token['refresh_token']);
            update_option('ptcrm_gcal_token', $new_token);
            return $new_token;
        } else {
            // Nessun refresh token, è necessaria una nuova autorizzazione
            return false;
        }
    }
    
    return $token;
}

/**
 * Registra widget dashboard per eventi Google Calendar
 */
function ptcrm_gcal_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'ptcrm_gcal_dashboard_widget',
        'Prossimi Eventi Google Calendar',
        'ptcrm_gcal_dashboard_widget_content'
    );
}
add_action('wp_dashboard_setup', 'ptcrm_gcal_add_dashboard_widget');

/**
 * Contenuto del widget dashboard
 */
function ptcrm_gcal_dashboard_widget_content() {
    $token = ptcrm_gcal_check_token();
    
    if (!$token) {
        echo '<p>Google Calendar non connesso o token scaduto.</p>';
        return;
    }
    
    try {
        $client = new Google\Client();
        $client->setAccessToken($token);
        
        $service = new Google\Service\Calendar($client);
        $calendarId = get_option('ptcrm_gcal_calendar_id', 'primary');
        
        // Recupera eventi futuri (prossimi 7 giorni)
        $optParams = array(
            'maxResults' => 5,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => date('c'),
            'timeMax' => date('c', strtotime('+7 days'))
        );
        
        $results = $service->events->listEvents($calendarId, $optParams);
        $events = $results->getItems();
        
        if (empty($events)) {
            echo '<p>Nessun evento nei prossimi 7 giorni.</p>';
        } else {
            echo '<ul class="gcal-events-list">';
            foreach ($events as $event) {
                $start = $event->getStart()->dateTime;
                if (empty($start)) {
                    $start = $event->getStart()->date; // Evento tutto il giorno
                }
                
                $start_formatted = date('d/m/Y H:i', strtotime($start));
                echo '<li>';
                echo '<strong>' . esc_html($event->getSummary()) . '</strong><br>';
                echo '<span class="event-time">' . $start_formatted . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
    } catch (Exception $e) {
        echo '<p>Errore nel recupero eventi: ' . esc_html($e->getMessage()) . '</p>';
    }
}

/**
 * Recupera la lista dei calendari disponibili
 */
function ptcrm_gcal_get_available_calendars() {
    $token = ptcrm_gcal_check_token();
    $calendars = array();
    
    if (!$token) {
        return $calendars;
    }
    
    try {
        $client = new Google\Client();
        $client->setAccessToken($token);
        
        $service = new Google\Service\Calendar($client);
        $calendarList = $service->calendarList->listCalendarList();
        
        foreach ($calendarList->getItems() as $calendarListEntry) {
            $calendars[$calendarListEntry->getId()] = $calendarListEntry->getSummary();
        }
    } catch (Exception $e) {
        error_log('Errore recupero calendari: ' . $e->getMessage());
    }
    
    return $calendars;
}

/**
 * Sistema di log per operazioni Google Calendar
 */
function ptcrm_gcal_log($message, $type = 'info') {
    $log_enabled = get_option('ptcrm_gcal_enable_logging', false);
    
    if (!$log_enabled) {
        return;
    }
    
    $log = array(
        'time' => current_time('mysql'),
        'message' => $message,
        'type' => $type
    );
    
    $logs = get_option('ptcrm_gcal_logs', array());
    array_unshift($logs, $log); // Aggiungi all'inizio
    
    // Mantieni solo gli ultimi 100 log
    if (count($logs) > 100) {
        array_pop($logs);
    }
    
    update_option('ptcrm_gcal_logs', $logs);
}

/**
 * Aggiunta della gestione per disconnettere account e cancellare log
 */
function ptcrm_gcal_handle_admin_actions() {
    // Solo per utenti amministratori
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Gestisce disconnessione
    if (isset($_GET['page']) && $_GET['page'] == 'puntotende-crm-gcal-settings' && isset($_GET['disconnect']) && $_GET['disconnect'] == '1') {
        delete_option('ptcrm_gcal_token');
        wp_redirect(admin_url('admin.php?page=puntotende-crm-gcal-settings&disconnected=1'));
        exit;
    }
    
    // Gestisce cancellazione log
    if (isset($_GET['page']) && $_GET['page'] == 'puntotende-crm-gcal-settings' && isset($_GET['clear_logs']) && $_GET['clear_logs'] == '1') {
        update_option('ptcrm_gcal_logs', array());
        wp_redirect(admin_url('admin.php?page=puntotende-crm-gcal-settings&logs_cleared=1'));
        exit;
    }
}
add_action('admin_init', 'ptcrm_gcal_handle_admin_actions');

/**
 * Aggiungi CSS per migliorare l'interfaccia
 */
function ptcrm_gcal_admin_styles() {
    $screen = get_current_screen();
    
    // Carica gli stili solo nella pagina del plugin
    if ($screen && strpos($screen->id, 'puntotende-crm-gcal-settings') !== false) {
        wp_enqueue_style(
            'ptcrm-gcal-admin',
            PUNTOTENDE_GCAL_URL . 'assets/css/admin.css',
            array(),
            PUNTOTENDE_GCAL_VERSION
        );
    }
}
add_action('admin_enqueue_scripts', 'ptcrm_gcal_admin_styles');

// Registra il setting per abilitare i log
function ptcrm_gcal_register_additional_settings() {
    register_setting(
        'ptcrm_gcal_settings',
        'ptcrm_gcal_enable_logging',
        array(
            'type' => 'boolean',
            'default' => false,
        )
    );
}
add_action('admin_init', 'ptcrm_gcal_register_additional_settings');


//FINE FUNZIONI NUOVE ------------------------------------------------------------------------------------------




// Hook into plugins_loaded to ensure all plugins are loaded before we try to use them
add_action('plugins_loaded', 'puntotende_gcal_init');

// Rest of your code remains the same...
// Aggiungi il filtro per la creazione degli eventi
add_filter('puntotende_gcal_create_event', function($result, $event_data) {
    try {
        error_log('PuntoTende GCal - Tentativo di creazione evento via filtro');
        
        // Ottieni l'istanza della classe GCal
        $gcal = PuntoTende_GCal::get_instance();
        
        // Verifica che l'istanza esista
        if (!$gcal) {
            throw new Exception('Impossibile ottenere l\'istanza di PuntoTende_GCal');
        }
        
        // Verifica che il metodo create_event esista
        if (!method_exists($gcal, 'create_event')) {
            throw new Exception('Il metodo create_event non esiste nella classe PuntoTende_GCal');
        }
        
        // Crea l'evento
        $event_id = $gcal->create_event($event_data);
        
        error_log('PuntoTende GCal - Evento creato con ID: ' . ($event_id ?: 'nessun ID'));
        
        return $event_id;
    } catch (Exception $e) {
        error_log('PuntoTende GCal - Errore creazione evento: ' . $e->getMessage());
        return false;
    }
}, 10, 2);

// Aggiungi il filtro per l'aggiornamento degli eventi
add_filter('puntotende_gcal_update_event', function($result, $event_data) {
    try {
        $gcal = PuntoTende_GCal::get_instance();
        return $gcal->update_event($event_data);
    } catch (Exception $e) {
        error_log('PuntoTende GCal - Errore aggiornamento evento: ' . $e->getMessage());
        return false;
    }
}, 10, 2);

// Aggiungi il filtro per l'eliminazione degli eventi
add_filter('puntotende_gcal_delete_event', function($result, $event_id) {
    try {
        $gcal = PuntoTende_GCal::get_instance();
        return $gcal->delete_event($event_id);
    } catch (Exception $e) {
        error_log('PuntoTende GCal - Errore eliminazione evento: ' . $e->getMessage());
        return false;
    }
}, 10, 2);

// Aggiungi hook per debug
add_action('admin_notices', function() {
    if (isset($_GET['debug_gcal']) && current_user_can('manage_options')) {
        $gcal = PuntoTende_GCal::get_instance();
        echo '<div class="notice notice-info">';
        echo '<p>Debug Google Calendar:</p>';
        echo '<ul>';
        echo '<li>Client ID configurato: ' . (get_option('ptcrm_gcal_client_id') ? '✓' : '✗') . '</li>';
        echo '<li>Token presente: ' . (get_option('ptcrm_gcal_token') ? '✓' : '✗') . '</li>';
        echo '<li>Classe GCal caricata: ' . (class_exists('PuntoTende_GCal') ? '✓' : '✗') . '</li>';
        echo '<li>Istanza GCal disponibile: ' . ($gcal ? '✓' : '✗') . '</li>';
        echo '</ul>';
        echo '</div>';
    }
});