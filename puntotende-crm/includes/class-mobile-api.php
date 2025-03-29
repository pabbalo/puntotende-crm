<?php
/**
 * PuntoTende CRM Mobile API
 * Gestisce gli endpoint REST API per la web app mobile
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTCRM_Mobile_API {
    /**
     * Constructor
     */
    public function __construct() {
        // Registra gli endpoint API
        add_action('rest_api_init', array($this, 'register_api_routes'));
    }

    /**
     * Registra tutti gli endpoint API necessari per l'app mobile
     */
    public function register_api_routes() {
        // Verifica autenticazione
        register_rest_route('ptcrm/v1', '/auth/status', [
            'methods' => 'GET',
            'callback' => array($this, 'get_auth_status'),
            'permission_callback' => '__return_true'
        ]);
        
        // Dashboard
        register_rest_route('ptcrm/v1', '/dashboard', [
            'methods' => 'GET',
            'callback' => array($this, 'get_dashboard_data'),
            'permission_callback' => function() {
                return current_user_can('read');
            }
        ]);
        
        // Implementa gli altri endpoint come necessario...
    }

    /**
     * Verifica lo stato di autenticazione dell'utente
     */
    public function get_auth_status($request) {
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
     * Ottiene i dati per la dashboard
     */
    public function get_dashboard_data($request) {
        // Implementa il recupero dei dati dalla dashboard
        // Questi sono dati di esempio
        return [
            'stats' => [
                'appointments_count' => 24,
                'customers_count' => 18,
                'today_appointments' => 3,
                'month_appointments' => 15
            ],
            'upcoming_appointments' => [
                [
                    'id' => 1,
                    'title' => 'Consulenza tendaggi',
                    'date' => '2025-03-19 10:00:00',
                    'customer_id' => 5,
                    'customer_name' => 'Marco Rossi'
                ],
                [
                    'id' => 2,
                    'title' => 'Sopralluogo',
                    'date' => '2025-03-20 14:30:00',
                    'customer_id' => 8,
                    'customer_name' => 'Laura Bianchi'
                ]
            ],
            'recent_customers' => [
                [
                    'id' => 8,
                    'name' => 'Laura Bianchi',
                    'phone' => '333 1234567'
                ],
                [
                    'id' => 5,
                    'name' => 'Marco Rossi',
                    'phone' => '347 9876543'
                ]
            ]
        ];
    }
}

// Inizializzazione
new PTCRM_Mobile_API();