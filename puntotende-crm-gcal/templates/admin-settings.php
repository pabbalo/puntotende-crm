<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Show success message if we just completed authorization
if (isset($_GET['auth_success']) && $_GET['auth_success'] == '1') {
    ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e('Authorization successful! Your calendar is now connected.', 'puntotende-crm-gcal'); ?></p>
    </div>
    <?php
}

// Notifica disconnessione
if (isset($_GET['disconnected']) && $_GET['disconnected'] == '1') {
    ?>
    <div class="notice notice-info is-dismissible">
        <p><?php _e('Account Google Calendar disconnesso con successo.', 'puntotende-crm-gcal'); ?></p>
    </div>
    <?php
}

// Notifica log cancellati
if (isset($_GET['logs_cleared']) && $_GET['logs_cleared'] == '1') {
    ?>
    <div class="notice notice-info is-dismissible">
        <p><?php _e('Log cancellati con successo.', 'puntotende-crm-gcal'); ?></p>
    </div>
    <?php
}

// Get saved options
$client_id = get_option('ptcrm_gcal_client_id', '');
$client_secret = get_option('ptcrm_gcal_client_secret', '');
$calendar_id = get_option('ptcrm_gcal_calendar_id', '');
$enable_logging = get_option('ptcrm_gcal_enable_logging', false);
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('ptcrm_gcal_settings'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="ptcrm_gcal_client_id"><?php _e('Google Client ID', 'puntotende-crm-gcal'); ?></label>
                </th>
                <td>
                    <input type="text" id="ptcrm_gcal_client_id" name="ptcrm_gcal_client_id" 
                           class="regular-text" value="<?php echo esc_attr($client_id); ?>" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="ptcrm_gcal_client_secret"><?php _e('Google Client Secret', 'puntotende-crm-gcal'); ?></label>
                </th>
                <td>
                    <input type="text" id="ptcrm_gcal_client_secret" name="ptcrm_gcal_client_secret" 
                           class="regular-text" value="<?php echo esc_attr($client_secret); ?>" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="ptcrm_gcal_calendar_id"><?php _e('Google Calendar ID', 'puntotende-crm-gcal'); ?></label>
                </th>
                <td>
                    <?php 
                    $token = get_option('ptcrm_gcal_token');
                    if (!empty($token)) {
                        $calendars = ptcrm_gcal_get_available_calendars();
                        
                        if (!empty($calendars)) {
                            echo '<select id="ptcrm_gcal_calendar_id" name="ptcrm_gcal_calendar_id">';
                            foreach ($calendars as $id => $name) {
                                echo '<option value="' . esc_attr($id) . '" ' . selected($calendar_id, $id, false) . '>';
                                echo esc_html($name) . ' (' . esc_html($id) . ')';
                                echo '</option>';
                            }
                            echo '</select>';
                        } else {
                            echo '<input type="text" id="ptcrm_gcal_calendar_id" name="ptcrm_gcal_calendar_id" 
                                   class="regular-text" value="' . esc_attr($calendar_id) . '" />';
                        }
                    } else {
                        echo '<input type="text" id="ptcrm_gcal_calendar_id" name="ptcrm_gcal_calendar_id" 
                               class="regular-text" value="' . esc_attr($calendar_id) . '" />';
                        echo '<p class="description">';
                        echo __('Connettiti a Google Calendar per vedere i calendari disponibili', 'puntotende-crm-gcal');
                        echo '</p>';
                    }
                    ?>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="ptcrm_gcal_enable_logging"><?php _e('Abilita Log', 'puntotende-crm-gcal'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="ptcrm_gcal_enable_logging" name="ptcrm_gcal_enable_logging" 
                           value="1" <?php checked($enable_logging, true); ?> />
                    <p class="description">
                        <?php _e('Registra le operazioni di integrazione con Google Calendar', 'puntotende-crm-gcal'); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
    
    <?php
    // Mostra lo stato della connessione
    $token = get_option('ptcrm_gcal_token');
    $connected = !empty($token);
    
    echo '<div class="connection-status ' . ($connected ? 'connected' : 'disconnected') . '">';
    echo '<h3>' . __('Stato Connessione', 'puntotende-crm-gcal') . '</h3>';
    
    if ($connected) {
        echo '<div class="notice notice-success inline"><p>';
        echo '<span class="dashicons dashicons-yes-alt"></span> ';
        echo __('Connesso a Google Calendar', 'puntotende-crm-gcal');
        
        // Aggiungi informazioni sull'account se disponibili
        if (!empty($token['email'])) {
            echo ' (' . esc_html($token['email']) . ')';
        }
        
        echo '</p></div>';
        
        // Aggiungi pulsante per disconnettere
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=puntotende-crm-gcal-settings&disconnect=1')) . '" class="button">';
        echo __('Disconnetti', 'puntotende-crm-gcal');
        echo '</a></p>';
    } else {
        echo '<div class="notice notice-warning inline"><p>';
        echo '<span class="dashicons dashicons-warning"></span> ';
        echo __('Non connesso a Google Calendar', 'puntotende-crm-gcal');
        echo '</p></div>';
    }
    
    echo '</div>';
    
    // Mostra pulsante autorizzazione
    if (!empty($client_id) && !empty($client_secret)) {
        $auth_url = admin_url('admin.php?page=puntotende-crm-gcal-settings&auth=1');
        echo '<div class="authorize-section">';
        echo '<h2>' . __('Google Calendar Authorization', 'puntotende-crm-gcal') . '</h2>';
        echo '<a href="' . esc_url($auth_url) . '" class="button button-primary">' . 
             __('Authorize with Google Calendar', 'puntotende-crm-gcal') . '</a>';
        echo '</div>';
    }
    
    // Mostra la sezione log se abilitata
    if ($enable_logging) {
        $logs = get_option('ptcrm_gcal_logs', array());
        
        echo '<div class="gcal-logs-section">';
        echo '<h3>' . __('Log Operazioni', 'puntotende-crm-gcal') . '</h3>';
        
        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr>';
        echo '<th>' . __('Data/Ora', 'puntotende-crm-gcal') . '</th>';
        echo '<th>' . __('Tipo', 'puntotende-crm-gcal') . '</th>';
        echo '<th>' . __('Messaggio', 'puntotende-crm-gcal') . '</th>';
        echo '</tr></thead>';
        
        echo '<tbody>';
        if (empty($logs)) {
            echo '<tr><td colspan="3">' . __('Nessun log disponibile', 'puntotende-crm-gcal') . '</td></tr>';
        } else {
            foreach ($logs as $log) {
                $class = ($log['type'] == 'error') ? 'error' : '';
                
                echo '<tr class="' . $class . '">';
                echo '<td>' . esc_html($log['time']) . '</td>';
                echo '<td>' . esc_html(ucfirst($log['type'])) . '</td>';
                echo '<td>' . esc_html($log['message']) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        
        // Pulsante per cancellare i log
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=puntotende-crm-gcal-settings&clear_logs=1')) . '" class="button">';
        echo __('Cancella Log', 'puntotende-crm-gcal');
        echo '</a></p>';
        
        echo '</div>';
    }
    ?>
</div>