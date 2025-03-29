/**
 * Register all settings for the plugin
 */
public function register_settings() {
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