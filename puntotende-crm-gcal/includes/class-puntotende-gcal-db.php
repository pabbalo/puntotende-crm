?php

class PuntoTende_GCal_DB {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'check_tables']);
    }

    public function check_tables() {
        $this->create_eventi_meta_table();
    }

    private function create_eventi_meta_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'puntotende_eventi_meta';
        
        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                meta_id bigint(20) unsigned NOT NULL auto_increment,
                evento_id bigint(20) unsigned NOT NULL,
                meta_key varchar(255) NOT NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id),
                KEY evento_id (evento_id),
                KEY meta_key (meta_key(191))
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    public static function add_evento_meta($evento_id, $meta_key, $meta_value) {
        global $wpdb;
        
        return $wpdb->insert(
            $wpdb->prefix . 'puntotende_eventi_meta',
            [
                'evento_id' => $evento_id,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ],
            ['%d', '%s', '%s']
        );
    }

    public static function get_evento_meta($evento_id, $meta_key) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}puntotende_eventi_meta 
             WHERE evento_id = %d AND meta_key = %s",
            $evento_id,
            $meta_key
        ));
    }

    public function save_event($post_id, $gcal_event_id) {
        error_log("PuntoTende GCal DB - Salvataggio evento GCal ID: $gcal_event_id per post ID: $post_id");
        return update_post_meta($post_id, 'gcal_event_id', $gcal_event_id);
    }

    public function get_event($post_id) {
        return get_post_meta($post_id, 'gcal_event_id', true);
    }

    public function delete_event($post_id) {
        error_log("PuntoTende GCal DB - Eliminazione evento per post ID: $post_id");
        return delete_post_meta($post_id, 'gcal_event_id');
    }

    public function get_post_by_event_id($gcal_event_id) {
        global $wpdb;
        
        error_log("PuntoTende GCal DB - Ricerca post per GCal ID: $gcal_event_id");
        
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'gcal_event_id' 
             AND meta_value = %s",
            $gcal_event_id
        ));

        if ($post_id) {
            error_log("PuntoTende GCal DB - Trovato post ID: $post_id per GCal ID: $gcal_event_id");
            return get_post($post_id);
        }

        error_log("PuntoTende GCal DB - Nessun post trovato per GCal ID: $gcal_event_id");
        return null;
    }

    public function get_all_gcal_events() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT post_id, meta_value as gcal_event_id 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = 'gcal_event_id'"
        );
    }
}