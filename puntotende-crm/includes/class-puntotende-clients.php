<?php
class PuntoTende_Clients {
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
        $this->table_name = $wpdb->prefix . 'puntotende_clienti';
    }

    public function update_client($id, $data) {
        global $wpdb;
        
        $fields = [
            'denominazione' => sanitize_text_field($data['denominazione']),
            'indirizzo' => sanitize_text_field($data['indirizzo']),
            'comune' => sanitize_text_field($data['comune']),
            'cap' => sanitize_text_field($data['cap']),
            'provincia' => sanitize_text_field($data['provincia']),
            'note_indirizzo' => sanitize_text_field($data['note_indirizzo']),
            'paese' => sanitize_text_field($data['paese']),
            'email' => sanitize_email($data['email']),
            'referente' => sanitize_text_field($data['referente']),
            'telefono' => sanitize_text_field($data['telefono']),
            'piva_tax' => sanitize_text_field($data['piva_tax']),
            'codice_fiscale' => sanitize_text_field($data['codice_fiscale']),
            'pec' => sanitize_text_field($data['pec']),
            'codice_sdi' => sanitize_text_field($data['codice_sdi']),
            'termini_pagamento' => sanitize_text_field($data['termini_pagamento']),
            'iban' => sanitize_text_field($data['iban']),
            'sconto_predefinito' => floatval($data['sconto_predefinito']),
            'note' => sanitize_textarea_field($data['note'])
        ];

        return $wpdb->update(
            $this->table_name,
            $fields,
            ['id' => $id],
            array_fill(0, count($fields), '%s'),
            ['%d']
        );
    }

    public function get_client($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }
}