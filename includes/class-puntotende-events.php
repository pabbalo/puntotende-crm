<?php
class PuntoTende_Events {
    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'puntotende_eventi';
    }

    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id INT NOT NULL AUTO_INCREMENT,
            cliente_id INT NOT NULL,
            tipo ENUM('appuntamento','telefonata') NOT NULL,
            data_ora DATETIME NOT NULL,
            durata INT,
            note TEXT,
            google_event_id VARCHAR(255),
            created_by BIGINT(20) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY cliente_id (cliente_id),
            KEY tipo (tipo),
            KEY data_ora (data_ora)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function add_appointment($data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'cliente_id' => $data['cliente_id'],
                'tipo' => 'appuntamento',
                'data_ora' => $data['datetime'],
                'durata' => $data['duration'],
                'note' => $data['notes'],
                'google_event_id' => $data['google_event_id'] ?? '',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s']
        );

        return $result ? $wpdb->insert_id : false;
    }

    public function add_call($data) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'cliente_id' => $data['cliente_id'],
                'tipo' => 'telefonata',
                'data_ora' => current_time('mysql'),
                'note' => $data['notes'],
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );

        return $result ? $wpdb->insert_id : false;
    }

    public function get_appointments($cliente_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE cliente_id = %d AND tipo = 'appuntamento'
             ORDER BY data_ora DESC",
            $cliente_id
        ));
    }

    public function get_calls($cliente_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE cliente_id = %d AND tipo = 'telefonata'
             ORDER BY data_ora DESC",
            $cliente_id
        ));
    }
}