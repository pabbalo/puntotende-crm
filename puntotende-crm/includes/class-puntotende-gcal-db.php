<?php

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
}