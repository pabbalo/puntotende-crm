<?php
/**
 * Plugin Name: PuntoTende CRM - Google Calendar
 * Plugin URI: https://www.puntotende.it/
 * Description: Integrazione con Google Calendar (OAuth)
 * Version: 1.0
 * Author: Puntotende
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

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

// Define constants
define('PUNTOTENDE_GCAL_PATH', plugin_dir_path(__FILE__));
define('PUNTOTENDE_GCAL_URL', plugin_dir_url(__FILE__));

// Initialize plugin
function puntotende_gcal_init() {
    // Verifica che la classe Google\Client sia disponibile
    if (!class_exists('Google\Client')) {
        error_log('PuntoTende GCal - Google\Client class non trovata dopo il caricamento dell\'autoloader');
        return;
    }
    
    // Include the main class file
    require_once PUNTOTENDE_GCAL_PATH . 'includes/class-puntotende-gcal.php';
    
    // Initialize the class
    if (class_exists('PuntoTende_GCal')) {
        PuntoTende_GCal::get_instance();
    }
}

// Hook into plugins_loaded to ensure all plugins are loaded before we try to use them
add_action('plugins_loaded', 'puntotende_gcal_init');

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
        echo '<li>Client ID configurato: ' . (get_option('puntotende_gcal_client_id') ? '✓' : '✗') . '</li>';
        echo '<li>Token presente: ' . (get_option('puntotende_gcal_token') ? '✓' : '✗') . '</li>';
        echo '<li>Classe GCal caricata: ' . (class_exists('PuntoTende_GCal') ? '✓' : '✗') . '</li>';
        echo '<li>Istanza GCal disponibile: ' . ($gcal ? '✓' : '✗') . '</li>';
        echo '</ul>';
        echo '</div>';
    }
});