<?php
if (!defined('ABSPATH')) {
    exit;
}

function puntotende_update_database() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'puntotende_eventi';
    
    // Controlla se la colonna esiste già
    $row = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'gcal_event_id'");
    if (empty($row)) {
        // Aggiungi la colonna
        $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN gcal_event_id VARCHAR(255) NULL AFTER note");
        error_log('PuntoTende CRM - Colonna gcal_event_id aggiunta con successo');
    }
}

// Esegui l'aggiornamento del database
puntotende_update_database();