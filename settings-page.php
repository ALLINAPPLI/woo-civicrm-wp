<?php
// Enhanced protection against direct access
if (!defined('ABSPATH')) {
    // Show detailed diagnostic page instead of just exiting
    http_response_code(403);
    echo '<!DOCTYPE html>
    <html><head><meta charset="utf-8"><title>WordPress Plugin Error</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; color: #444; max-width: 800px; margin: 0 auto; padding: 20px; }
        h1 { color: #d63638; }
        .diagnostic { background: #f0f0f1; padding: 15px; border-left: 4px solid #d63638; margin-bottom: 20px; }
        code { background: #e0e0e0; padding: 2px 4px; }
        /* Connection status indicator */
        .connection-indicator { 
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 5px;
            box-shadow: 0 0 3px rgba(0,0,0,0.3);
        }
        .connection-indicator.connected { 
            background: #52c41a;
            box-shadow: 0 0 8px #52c41a;
        }
        .connection-indicator.disconnected { 
            background: #ff4d4f;
            box-shadow: 0 0 8px #ff4d4f;
        }
        .connection-indicator.connecting {
            background: #faad14;
            box-shadow: 0 0 8px #faad14;
            animation: pulse 1.5s infinite;
        }
        .connection-indicator.unknown {
            background: #bfbfbf;
        }
        
        @keyframes pulse {
            0% { opacity: 0.6; }
            50% { opacity: 1; }
            100% { opacity: 0.6; }
        }
        
        .connection-status {
            font-weight: 500;
            margin-bottom: 15px;
            padding: 8px 12px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            width: fit-content;
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
        }
        
        .connection-status-text {
            font-weight: 600;
        }
        
        /* Ensure form elements are visible */
        input, select, button { 
            opacity: 1 !important;
        }
    </style></head><body>
    <h1>Direct Access Not Allowed</h1>
    <div class="diagnostic">
        <p>This file should not be accessed directly. It should be loaded through the WordPress admin interface.</p>
        <p>Current file path: ' . __FILE__ . '</p>
        <p>To fix this issue:</p>
        <ol>
            <li>Access your WordPress admin at <code>https://your-site.com/wp-admin</code></li>
            <li>Navigate to WC CiviCRM Settings from the admin menu</li>
        </ol>
    </div>
    </body></html>';
    exit;
}

class WC_CiviCRM_Settings
{
    use WC_CiviCRM_API_Request;

    public $available_fields = [];
    private $civicrm_url;
    private $auth_token;
    private $site_key;
    private $logger;
    private $field_mappings;

    public function __construct()
    {
        // Get credentials
        $this->civicrm_url = get_option('wc_civicrm_url', '');
        $this->auth_token = get_option('wc_civicrm_auth_token', '');
        $this->site_key = get_option('wc_civicrm_site_key', '');
        
        // Check if field mappings are saved in the database
        $this->field_mappings = get_option('wc_civicrm_field_mappings', []);
        
        // Register AJAX handlers
        add_action('wp_ajax_fetch_financial_types', array($this, 'ajax_fetch_financial_types'));
        add_action('wp_ajax_test_civicrm_connection', array($this, 'test_connection'));
        add_action('wp_ajax_test_contact_creation', array($this, 'test_contact_creation'));
        add_action('wp_ajax_test_contribution_creation', array($this, 'test_contribution_creation'));
        add_action('wp_ajax_fetch_civicrm_fields', array($this, 'fetch_fields'));
        
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts($hook)
    {
        if (strpos($hook, 'wc-civicrm') === false) {
            return;
        }

        // Define plugin directory with fallback for file structure
        $plugin_url = plugin_dir_url(__FILE__);
        
        // Check if we're inside a subdirectory
        if (strpos($plugin_url, '/wp-content/plugins/woo-civicrm-wp/') === false) {
            $plugin_url = plugin_dir_url(dirname(__FILE__));
        }

        wp_enqueue_style(
            'wc-civicrm-admin-styles',
            $plugin_url . 'assets/css/admin-styles.css',
            [],
            '1.0'
        );

        wp_enqueue_script(
            'wc-civicrm-admin-scripts',
            $plugin_url . 'assets/js/admin-scripts.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('wc-civicrm-admin-scripts', 'wc_civicrm_admin_params', [
            'test_connection_nonce' => wp_create_nonce('test_civicrm_connection'),
            'test_contact_creation_nonce' => wp_create_nonce('test_contact_creation'),
            'test_contribution_creation_nonce' => wp_create_nonce('test_contribution_creation'),
            'fetch_fields_nonce' => wp_create_nonce('fetch_civicrm_fields'),
            'fetch_financial_types_nonce' => wp_create_nonce('fetch_financial_types')
        ]);
    }

    /**
     * Render connection status test button and result container
     */
    public function connection_status_callback()
    {
?>
        <div class="wc-civicrm-card">
            <div class="connection-test-container">
                <div class="connection-status">
                    <span class="connection-indicator" id="connection-indicator"></span>
                    <span class="connection-status-text" id="connection-status-text">Unknown</span>
                </div>
                <button id="test-civicrm-connection" class="button button-primary">
                    <span class="dashicons dashicons-database-view"></span>
                    <?php _e('Test CiviCRM Connection', 'woo-civicrm-wp'); ?>
                </button>
                <div id="connection-test-result" class="test-result"></div>
            </div>
        </div>
<?php
    }

    /**
     * Render contact creation test button and result container
     */
    public function contact_creation_callback()
    {
    ?>
        <div class="wc-civicrm-card">
            <div class="contact-creation-test-container">
                <button id="test-contact-creation" class="button button-primary">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php _e('Test Contact Creation', 'woo-civicrm-wp'); ?>
                </button>
                <div id="contact-creation-test-result" class="test-result"></div>
            </div>
        </div>
    <?php
    }

    /**
     * Render contribution creation test button and result container
     */
    public function contribution_creation_callback()
    {
    ?>
        <div class="wc-civicrm-card">
            <div class="contribution-creation-test-container">
                <button id="test-contribution-creation" class="button button-primary">
                    <span class="dashicons dashicons-money-alt"></span>
                    <?php _e('Test Contribution Creation', 'woo-civicrm-wp'); ?>
                </button>
                <div id="contribution-creation-test-result" class="test-result"></div>
            </div>
        </div>
    <?php
    }

    public function register_settings()
    {
        register_setting('wc_civicrm_settings', 'wc_civicrm_url');
        register_setting('wc_civicrm_settings', 'wc_civicrm_auth_token');
        register_setting('wc_civicrm_settings', 'wc_civicrm_field_mappings', [
            'sanitize_callback' => [$this, 'sanitize_field_mappings']
        ]);
        register_setting('wc_civicrm_settings', 'wc_civicrm_debug_mode');
        register_setting('wc_civicrm_settings', 'wc_civicrm_connection_status');
        register_setting('wc_civicrm_settings', 'wc_civicrm_contribution_type_id');

        // Add settings sections
        add_settings_section(
            'wc_civicrm_connection_section',
            'Connection Settings',
            [$this, 'section_callback'],
            'wc-civicrm-settings'
        );
    }

    public function sanitize_field_mappings($mappings)
    {
        if (!is_array($mappings)) {
            return [];
        }

        $sanitized = [];
        foreach ($mappings as $key => $mapping) {
            if (empty($mapping['wc_field']) || empty($mapping['civicrm'])) {
                continue;
            }

            $sanitized[$mapping['wc_field']] = [
                'civicrm' => sanitize_text_field($mapping['civicrm']),
                'type' => sanitize_text_field($mapping['type'])
            ];
        }

        return $sanitized;
    }

    public function section_callback()
    {
        // Validate WordPress environment
        if (!function_exists('add_action')) {
            $this->render_diagnostic_page();
            return;
        }

        // Enable error reporting for debugging
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        
        // Set credentials for connection test
        $this->civicrm_url = get_option('wc_civicrm_url');
        $this->auth_token = get_option('wc_civicrm_auth_token');
        
        // Auto-test connection on page load if credentials exist
        if (!empty($this->civicrm_url) && !empty($this->auth_token)) {
            $connection_test = $this->test_civicrm_connection();
            $status_class = $connection_test['status'];
            $status_text = $connection_test['success'] ? 'Connected' : 'Disconnected';
            
            // Store the result for future reference
            update_option('wc_civicrm_connection_status', [
                'status' => $status_class,
                'last_checked' => time(),
                'message' => $connection_test['message']
            ]);
        } else {
            // Get current connection status if we can't test now
            $connection_status = $this->check_connection_status();
            $status_class = $connection_status['status'];
            $status_text = ucfirst($connection_status['status']);
            if ($status_class === 'unknown') {
                $status_text = 'Unknown';
            }
        }
        
        // Minimal critical CSS for tabs to work
        ?>
        <style>
            /* Basic tab styling */
            .wc-civicrm-tabs { 
                display: flex;
                margin-bottom: 0;
                border-bottom: 1px solid #ccc;
            }
            .wc-civicrm-tab-button { 
                background: #f1f1f1;
                border: 1px solid #ccc;
                border-bottom: none;
                padding: 10px 15px;
                margin-right: 5px;
                margin-bottom: -1px;
                cursor: pointer;
            }
            .wc-civicrm-tab-button.active { 
                background: #fff;
                border-bottom-color: #fff;
            }
            .wc-civicrm-tab-content { 
                display: none;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccc;
                border-top: none;
            }
            /* Force first tab to be visible by default */
            #tab-connection { 
                display: block;
            }
        </style>
        
        <div class="wrap wc-civicrm-admin-wrapper">
            <h1>WooCommerce CiviCRM Integration</h1>
            <p>Configure your WooCommerce and CiviCRM integration settings.</p>
            
            <form method="post" action="options.php">
                <?php 
                // Make sure WordPress admin is loaded
                if (function_exists('settings_fields')) {
                    settings_fields('wc_civicrm_settings');
                } else {
                    echo '<input type="hidden" name="option_page" value="wc_civicrm_settings" />';
                    
                    // Create our own nonce field if wp_nonce_field isn't available
                    if (function_exists('wp_nonce_field')) {
                        wp_nonce_field('wc_civicrm_settings-options');
                    } else {
                        echo '<input type="hidden" name="_wpnonce" value="' . md5('wc_civicrm_settings-options' . time()) . '" />';
                    }
                }
                ?>
                
                <div class="wc-civicrm-tabs-wrapper">
                    <nav class="wc-civicrm-tabs">
                        <button type="button" class="wc-civicrm-tab-button active" data-tab="tab-connection">Connection</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-mappings">Field Mappings</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-contribution">Contribution</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-testing">Testing</button>
                        <button type="button" class="wc-civicrm-tab-button" data-tab="tab-debug">Debug</button>
                    </nav>
                    
                    <!-- First tab: Connection settings -->
                    <div id="tab-connection" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>CiviCRM Connection Settings</h2>
                            <p>Configure the connection between WooCommerce and CiviCRM.</p>
                            <div class="connection-status">
                                <span class="connection-indicator <?php echo $status_class; ?>" id="main-connection-indicator"></span>
                                <span class="connection-status-text" id="main-connection-status-text"><?php echo $status_text; ?></span>
                            </div>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">CiviCRM API URL</th>
                                    <td><?php $this->url_callback(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">API Authentication Token</th>
                                    <td><?php $this->auth_token_callback(); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Connection Test</th>
                                    <td>
                                        <div class="connection-test-container">
                                            <button id="test-civicrm-connection-main" class="button button-primary">
                                                <span class="dashicons dashicons-database-view"></span>
                                                <?php _e('Test CiviCRM Connection', 'woo-civicrm-wp'); ?>
                                            </button>
                                            <div id="connection-test-result-main" class="test-result"></div>
                                        </div>
                                        <p class="description">Test your CiviCRM connection settings before saving.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Second tab: Field Mappings -->
                    <div id="tab-mappings" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>Field Mappings</h2>
                            <?php $this->field_mappings_callback(); ?>
                        </div>
                    </div>
                    
                    <!-- Third tab: Contribution Settings -->
                    <div id="tab-contribution" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>CiviCRM Contribution Settings</h2>
                            <p>Configure how WooCommerce orders are mapped to CiviCRM contributions.</p>
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Financial Type</th>
                                    <td><?php $this->contribution_type_callback(); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Fourth tab: Testing -->
                    <div id="tab-testing" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>CiviCRM Integration Tests</h2>
                            <p>Verify your CiviCRM integration settings by running the tests below.</p>
                            
                            <div class="wc-civicrm-test-grid">
                                <?php 
                                $this->connection_status_callback();
                                $this->contact_creation_callback();
                                $this->contribution_creation_callback();
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fifth tab: Debug -->
                    <div id="tab-debug" class="wc-civicrm-tab-content">
                        <div class="wc-civicrm-section">
                            <h2>Debug Settings</h2>
                            <p>Configure debug and logging options.</p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Debug Mode</th>
                                    <td><?php $this->debug_mode_callback(); ?></td>
                                </tr>
                            </table>

                            <h2>WooCommerce Payment Methods</h2>
                            <p>Ids machines des gateways WooCommerce (utiles pour le mapping vers <code>payment_instrument_id</code> CiviCRM).</p>
                            <?php $this->debug_wc_payment_methods_callback(); ?>
                        </div>
                    </div>
                </div>
                
                <?php 
                if (function_exists('submit_button')) {
                    submit_button('Save Settings');
                } else {
                    echo '<input type="submit" class="submit-button" value="Save Settings" />';
                }
                ?>
            </form>
        </div>
        
        <!-- Very simple tab script -->
        <script>
            jQuery(document).ready(function($) {
                // Hide all tab content initially except the first
                $(".wc-civicrm-tab-content").not("#tab-connection").hide();
                
                // Simple tab switching
                $(".wc-civicrm-tab-button").on("click", function() {
                    // Hide all tabs
                    $(".wc-civicrm-tab-content").hide();
                    
                    // Remove active class from all buttons
                    $(".wc-civicrm-tab-button").removeClass("active");
                    
                    // Show the selected tab
                    $(this).addClass("active");
                    var tabId = $(this).data("tab");
                    $("#" + tabId).show();
                });
                
                // Make sure the first tab is visible by default
                $("#tab-connection").show();
                $(".wc-civicrm-tab-button[data-tab='tab-connection']").addClass("active");
                
                // Password toggle functionality
                $(".toggle-password").on("click", function() {
                    var targetId = $(this).data("target");
                    var input = $("#" + targetId);
                    var icon = $(this).find(".dashicons");
                    
                    if (input.attr("type") === "password") {
                        input.attr("type", "text");
                        if (icon.length) {
                            icon.removeClass("dashicons-visibility").addClass("dashicons-hidden");
                        }
                    } else {
                        input.attr("type", "password");
                        if (icon.length) {
                            icon.removeClass("dashicons-hidden").addClass("dashicons-visibility");
                        }
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Display diagnostic page for troubleshooting
     */
    private function render_diagnostic_page() {
        // Check if we're in WordPress context
        $in_wordpress = defined('ABSPATH') && function_exists('get_bloginfo');
        
        // Basic diagnostic information
        $php_version = phpversion();
        $server_info = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
        $current_path = __FILE__;
        
        // Get list of WordPress functions if available
        $wp_functions = [];
        if ($in_wordpress) {
            $wp_functions[] = 'WordPress Version: ' . get_bloginfo('version');
            $wp_functions[] = 'WordPress URL: ' . get_bloginfo('url');
            $wp_functions[] = 'WordPress Directory: ' . ABSPATH;
        } else {
            // Check for common WordPress functions
            $core_functions = [
                'add_action', 'add_filter', 'wp_enqueue_script', 'settings_fields',
                'get_option', 'update_option', 'wp_nonce_field', 'wp_create_nonce'
            ];
            
            foreach ($core_functions as $function) {
                $wp_functions[] = $function . ': ' . (function_exists($function) ? 'Available' : 'Not Available');
            }
        }
        
        // Check included files
        $included_files = get_included_files();
        $wp_core_files = array_filter($included_files, function($file) {
            return strpos($file, 'wp-includes') !== false || strpos($file, 'wp-admin') !== false;
        });
        
        // Output diagnostic page
        ?>
        <div class="diagnostic-page" style="padding:20px; max-width:1200px; margin:0 auto; font-family:sans-serif;">
            <h1 style="color:#d63638;">WordPress Integration Diagnostic Page</h1>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>Page Access Information</h2>
                <p><strong>This page has been accessed directly, outside of the normal WordPress admin context.</strong></p>
                <p>To fix this issue:</p>
                <ol>
                    <li>Access your WordPress admin at <code>https://your-site.com/wp-admin</code></li>
                    <li>Navigate to WC CiviCRM Settings from the admin menu</li>
                </ol>
            </div>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>System Information</h2>
                <ul>
                    <li>PHP Version: <?php echo $php_version; ?></li>
                    <li>Server: <?php echo $server_info; ?></li>
                    <li>Current File Path: <?php echo $current_path; ?></li>
                    <li>WordPress Context: <?php echo $in_wordpress ? 'Available' : 'Not Available'; ?></li>
                </ul>
            </div>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>WordPress Function Availability</h2>
                <ul>
                    <?php foreach ($wp_functions as $function_info): ?>
                        <li><?php echo $function_info; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <div style="background:#f0f0f1; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <h2>WordPress Core Files Included</h2>
                <p>Found <?php echo count($wp_core_files); ?> WordPress core files included:</p>
                <ol style="max-height:300px; overflow-y:auto;">
                    <?php foreach (array_slice($wp_core_files, 0, 10) as $file): ?>
                        <li><?php echo $file; ?></li>
                    <?php endforeach; ?>
                    <?php if (count($wp_core_files) > 10): ?>
                        <li>... and <?php echo count($wp_core_files) - 10; ?> more</li>
                    <?php endif; ?>
                </ol>
            </div>
            
            <div style="margin-top:30px; border-top:1px solid #ddd; padding-top:20px;">
                <h2>How to Resolve This Issue</h2>
                <ol>
                    <li>Make sure the plugin is properly installed in your WordPress plugins directory</li>
                    <li>Ensure the plugin is activated in your WordPress admin</li>
                    <li>Access the settings page through the WordPress admin menu, not directly via URL</li>
                    <li>If using a custom setup, make sure WordPress is fully loaded before accessing this page</li>
                </ol>
            </div>
        </div>
        <?php
    }

    public function url_callback()
    {
        $url = get_option('wc_civicrm_url');
    ?>
        <div class="wc-civicrm-field-wrapper">
            <input type="url" name="wc_civicrm_url" value="<?php echo esc_attr($url); ?>" class="regular-text" placeholder="https://example.com">
            <p class="description">Enter your CiviCRM site URL (e.g., https://example.com)</p>
        </div>
    <?php
    }

    public function auth_token_callback()
    {
        $token = get_option('wc_civicrm_auth_token');
    ?>
        <div class="wc-civicrm-field-wrapper">
            <div class="password-field-container">
                <input type="password" id="civicrm-auth-token" name="wc_civicrm_auth_token" value="<?php echo esc_attr($token); ?>" class="regular-text">
                <button type="button" class="button toggle-password" data-target="civicrm-auth-token">
                    <span class="dashicons dashicons-visibility"></span>
                </button>
            </div>
            <p class="description">Enter your CiviCRM API authentication token</p>
        </div>
    <?php
    }

    public function debug_mode_callback()
    {
        $debug_mode = get_option('wc_civicrm_debug_mode', false);
    ?>
        <div class="wc-civicrm-field-wrapper">
            <label class="toggle-switch">
                <input type="checkbox" name="wc_civicrm_debug_mode" value="1" <?php checked($debug_mode, true); ?>>
                <span class="toggle-slider"></span>
            </label>
            <span class="toggle-label">Enable detailed logging</span>
            <p class="description">When enabled, all API requests and responses will be logged with full details.</p>
        </div>
    <?php
    }

    /**
     * Debug: list WooCommerce payment gateway machine ids and titles.
     */
    public function debug_wc_payment_methods_callback()
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            echo '<div class="notice notice-warning inline"><p>WooCommerce ou les payment gateways sont indisponibles.</p></div>';
            return;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if (empty($gateways)) {
            echo '<div class="notice notice-warning inline"><p>Aucune méthode de paiement WooCommerce trouvée.</p></div>';
            return;
        }
        ?>
        <div class="wc-civicrm-card">
            <table class="wp-list-table widefat fixed striped" style="max-width:720px">
                <thead>
                    <tr>
                        <th>Id machine</th>
                        <th>Titre</th>
                        <th>Activée</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gateways as $id => $gateway) : ?>
                        <tr>
                            <td><code><?php echo esc_html($id); ?></code></td>
                            <td><?php echo esc_html($gateway->get_title()); ?></td>
                            <td><?php echo ($gateway->enabled === 'yes') ? 'oui' : 'non'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function field_mappings_callback()
    {
        $mappings = get_option('wc_civicrm_field_mappings', []);
        $wc_fields = $this->get_woocommerce_fields();
    ?>
        <div class="wc-civicrm-field-mappings-wrapper">
            <div class="field-mappings-header">
                <h3>Field Mappings</h3>
                <p>Map WooCommerce fields to CiviCRM fields. Use the button below to fetch available fields from CiviCRM.</p>
                <div class="field-mappings-actions">
                    <button type="button" id="fetch-fields" class="button button-secondary">
                        <span class="dashicons dashicons-update"></span> Fetch Available Fields
                    </button>
                    <span id="fetch-result"></span>
                </div>
            </div>

            <div class="field-mappings-table-container">
                <table class="wp-list-table widefat fixed striped" id="field-mappings-table">
                    <thead>
                        <tr>
                            <th class="column-wc-field">WooCommerce Field</th>
                            <th class="column-civicrm-field">CiviCRM Field</th>
                            <th class="column-field-type">Field Type</th>
                            <th class="column-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $wc_field => $mapping):
                            $civicrm_field = is_array($mapping) ? $mapping['civicrm'] : $mapping;
                            $field_type = is_array($mapping) ? $mapping['type'] : '';
                        ?>
                            <tr>
                                <td>
                                    <select name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][wc_field]" class="regular-text">
                                        <option value="">Select WooCommerce Field</option>
                                        <?php foreach ($wc_fields as $field => $label): ?>
                                            <option value="<?php echo esc_attr($field); ?>" <?php selected($field, $wc_field); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][civicrm]"
                                        class="regular-text civicrm-field-select"
                                        data-original-value="<?php echo esc_attr($civicrm_field); ?>"
                                        data-original-type="<?php echo esc_attr($field_type); ?>">
                                        <option value="">Select CiviCRM Field</option>
                                        <option value="<?php echo esc_attr($civicrm_field); ?>" selected>
                                            <?php echo esc_html($civicrm_field); ?>
                                        </option>
                                    </select>
                                    <input type="hidden" name="wc_civicrm_field_mappings[<?php echo esc_attr($wc_field); ?>][type]"
                                        class="field-type-input" value="<?php echo esc_attr($field_type); ?>">
                                </td>
                                <td class="field-type">
                                    <span class="field-type-badge"><?php echo esc_html($field_type); ?></span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small remove-mapping">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4">
                                <button type="button" id="add-mapping" class="button">
                                    <span class="dashicons dashicons-plus"></span> Add New Mapping
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Connection Status Check
                function updateConnectionStatus(status, statusText) {
                    // Update main connection indicator
                    $('#main-connection-indicator')
                        .removeClass('connected disconnected connecting')
                        .addClass(status);
                        
                    $('#main-connection-status-text').text(statusText);
                    
                    // Update the connection status container
                    $('.wc-civicrm-connection-status')
                        .removeClass('connected disconnected')
                        .addClass(status);
                    
                    // Update test tab connection indicator
                    $('#connection-indicator')
                        .removeClass('connected disconnected connecting')
                        .addClass(status);
                        
                    $('#connection-status-text').text(statusText);
                }
                
                // Check initial connection status (if known)
                var initialStatus = <?php echo json_encode([
                    'status' => $status_class ?? 'unknown',
                    'status_text' => $status_text ?? 'Unknown'
                ]); ?>;
                
                // Update indicator with our server-side test result
                updateConnectionStatus(initialStatus.status, initialStatus.status_text);
                
                // Test connection handling
                $('#test-civicrm-connection, #test-civicrm-connection-main').on('click', function() {
                    var button = $(this);
                    var resultDiv = button.attr('id') === 'test-civicrm-connection' ? 
                                    $('#connection-test-result') : 
                                    $('#connection-test-result-main');
                    
                    // Get current URL and token values from form fields (in case they've been modified)
                    var url = $('input[name="wc_civicrm_url"]').val();
                    var token = $('input[name="wc_civicrm_auth_token"]').val();
                    
                    // Basic validation
                    if (!url || !token) {
                        resultDiv.addClass('error').html(
                            '<span class="dashicons dashicons-warning"></span> ' + 
                            'Please enter both CiviCRM URL and Authentication Token'
                        );
                        return;
                    }
                    
                    // Set status to connecting/testing
                    updateConnectionStatus('connecting', 'Testing connection...');
                    
                    button.addClass('updating-message').prop('disabled', true);
                    resultDiv.removeClass('success error').html('Testing connection...');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_civicrm_connection',
                            nonce: wc_civicrm_admin_params.test_connection_nonce,
                            url: url,  // Send current field values
                            token: token
                        },
                        success: function(response) {
                            button.removeClass('updating-message').prop('disabled', false);
                            
                            if (response.success) {
                                resultDiv.addClass('success').html(
                                    '<span class="dashicons dashicons-yes-alt"></span> ' + 
                                    response.data.message
                                );
                                updateConnectionStatus('connected', 'Connected');
                            } else {
                                resultDiv.addClass('error').html(
                                    '<span class="dashicons dashicons-warning"></span> ' + 
                                    response.data.message
                                );
                                updateConnectionStatus('disconnected', 'Disconnected');
                            }
                        },
                        error: function(xhr) {
                            button.removeClass('updating-message').prop('disabled', false);
                            
                            var errorMsg = 'Connection test failed';
                            if (xhr.responseJSON && xhr.responseJSON.data) {
                                errorMsg = xhr.responseJSON.data.message;
                            }
                            
                            resultDiv.addClass('error').html(
                                '<span class="dashicons dashicons-warning"></span> ' + 
                                errorMsg
                            );
                            updateConnectionStatus('disconnected', 'Disconnected');
                        }
                    });
                });
                
                function getNewRow() {
                    const wc_fields = <?php echo json_encode($wc_fields); ?>;
                    let options = '<option value="">Select WooCommerce Field</option>';
                    for (const [value, label] of Object.entries(wc_fields)) {
                        options += `<option value="${value}">${label}</option>`;
                    }

                    const rowId = 'new_' + Math.random().toString(36).substr(2, 9);
                    return `
                        <tr>
                            <td>
                                <select name="wc_civicrm_field_mappings[${rowId}][wc_field]" class="regular-text">
                                    ${options}
                                </select>
                            </td>
                            <td>
                                <select name="wc_civicrm_field_mappings[${rowId}][civicrm]" 
                                        class="regular-text civicrm-field-select"
                                        data-original-value=""
                                        data-original-type="">
                                    <option value="">Select CiviCRM Field</option>
                                </select>
                                <input type="hidden" name="wc_civicrm_field_mappings[${rowId}][type]" 
                                       class="field-type-input" value="">
                            </td>
                            <td class="field-type">
                                <span class="field-type-badge"></span>
                            </td>
                            <td>
                                <button type="button" class="button button-small remove-mapping">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </td>
                        </tr>`;
                }

                $('#add-mapping').click(function() {
                    $('#field-mappings-table tbody').append(getNewRow());
                });

                $(document).on('click', '.remove-mapping', function() {
                    $(this).closest('tr').remove();
                });
                
                // Fetch fields handling
                $('#fetch-fields').on('click', function() {
                    var button = $(this);
                    var resultSpan = $('#fetch-result');
                    
                    button.addClass('updating-message').prop('disabled', true);
                    resultSpan.html('<span class="spinner is-active"></span> Fetching fields...');
                    
                    // First ensure we have a valid connection
                    var url = $('input[name="wc_civicrm_url"]').val();
                    var token = $('input[name="wc_civicrm_auth_token"]').val();
                    
                    if (!url || !token) {
                        resultSpan.html('<span class="dashicons dashicons-warning error"></span> Please enter CiviCRM URL and API token first');
                        button.removeClass('updating-message').prop('disabled', false);
                        return;
                    }
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fetch_civicrm_fields',
                            _ajax_nonce: wc_civicrm_admin_params.fetch_fields_nonce
                        },
                        success: function(response) {
                            button.removeClass('updating-message').prop('disabled', false);
                            
                            console.log('Field fetch response:', response);
                            
                            if (response.success) {
                                resultSpan.html('<span class="dashicons dashicons-yes-alt success"></span> Fields fetched successfully');
                                
                                // Update field selects with fetched data
                                updateFieldSelects(response.data);
                            } else {
                                resultSpan.html('<span class="dashicons dashicons-warning error"></span> ' + response.data);
                            }
                            
                            // Hide message after 5 seconds
                            setTimeout(function() {
                                resultSpan.html('');
                            }, 5000);
                        },
                        error: function(xhr, status, error) {
                            button.removeClass('updating-message').prop('disabled', false);
                            console.error('Field fetch error:', xhr, status, error);
                            
                            resultSpan.html('<span class="dashicons dashicons-warning error"></span> Network error: ' + error);
                            
                            // Hide message after 5 seconds
                            setTimeout(function() {
                                resultSpan.html('');
                            }, 5000);
                        }
                    });
                });
                
                function updateFieldSelects(fields) {
                    console.log('Updating field selects with data:', fields);
                    
                    const contactFields = fields.contact_fields || [];
                    const contributionFields = fields.contribution_fields || [];
                    
                    if (contactFields.length === 0 && contributionFields.length === 0) {
                        alert('No fields were returned from CiviCRM. Please check your connection and try again.');
                        return;
                    }
                    
                    $('.civicrm-field-select').each(function() {
                        const select = $(this);
                        const originalValue = select.data('original-value');
                        const originalType = select.data('original-type');
                        const typeInput = select.siblings('.field-type-input');
                        
                        // Clear existing options except the first one
                        select.find('option:not(:first)').remove();
                        
                        // Add option groups
                        if (contactFields.length > 0) {
                            const contactGroup = $('<optgroup label="Contact Fields"></optgroup>');
                            select.append(contactGroup);
                            
                            contactFields.forEach(function(field) {
                                contactGroup.append(
                                    $('<option></option>')
                                        .val(field.name)
                                        .attr('data-type', 'Contact')
                                        .text(field.label)
                                );
                            });
                        }
                        
                        if (contributionFields.length > 0) {
                            const contributionGroup = $('<optgroup label="Contribution Fields"></optgroup>');
                            select.append(contributionGroup);
                            
                            contributionFields.forEach(function(field) {
                                contributionGroup.append(
                                    $('<option></option>')
                                        .val(field.name)
                                        .attr('data-type', 'Contribution')
                                        .text(field.label)
                                );
                            });
                        }
                        
                        // Re-select original value if it exists
                        if (originalValue) {
                            select.val(originalValue);
                        }
                        
                        // Set change event to update field type
                        select.off('change').on('change', function() {
                            const selected = $(this).find('option:selected');
                            const fieldType = selected.data('type') || '';
                            typeInput.val(fieldType);
                            select.closest('tr').find('.field-type-badge').text(fieldType);
                        });
                    });
                    
                    // Auto-fetch on page load if no mappings exist yet
                    if ($('#field-mappings-table tbody tr').length === 0) {
                        $('#add-mapping').click();
                    }
                }
                
                // Auto-fetch fields when connection test succeeds
                $(document).on('connection:success', function() {
                    // Wait a moment and then fetch fields
                    setTimeout(function() {
                        $('#fetch-fields').click();
                    }, 1000);
                });
            });
        </script>
<?php
    }

    public function get_woocommerce_fields()
    {
        return [
            // Basic Info
            'billing_first_name' => 'Billing First Name',
            'billing_last_name' => 'Billing Last Name',
            'billing_company' => 'Billing Company',
            'billing_email' => 'Billing Email',
            'billing_phone' => 'Billing Phone',

            // Billing Address
            'billing_address_1' => 'Billing Address 1',
            'billing_address_2' => 'Billing Address 2',
            'billing_city' => 'Billing City',
            'billing_state' => 'Billing State',
            'billing_postcode' => 'Billing Postcode',
            'billing_country' => 'Billing Country',

            // Shipping Address
            'shipping_first_name' => 'Shipping First Name',
            'shipping_last_name' => 'Shipping Last Name',
            'shipping_company' => 'Shipping Company',
            'shipping_address_1' => 'Shipping Address 1',
            'shipping_address_2' => 'Shipping Address 2',
            'shipping_city' => 'Shipping City',
            'shipping_state' => 'Shipping State',
            'shipping_postcode' => 'Shipping Postcode',
            'shipping_country' => 'Shipping Country',

            // Order Information
            'order_number' => 'Order Number',
            'order_currency' => 'Order Currency',
            'order_total' => 'Order Total',
            'order_status' => 'Order Status',
            'payment_method' => 'Payment Method',
            'order_notes' => 'Order Notes'
        ];
    }

    public function test_connection()
    {
        // Verify nonce and user capabilities
        if (!wp_verify_nonce($_POST['nonce'], 'test_civicrm_connection') || !current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Unauthorized access',
                'debug_info' => 'Invalid nonce or insufficient permissions'
            ], 403);
            exit;
        }

        // Use provided URL and token from the form if available
        // This allows testing connection before saving settings
        if (isset($_POST['url']) && !empty($_POST['url'])) {
            $this->civicrm_url = sanitize_text_field($_POST['url']);
        } else {
            $this->civicrm_url = get_option('wc_civicrm_url');
        }
        
        if (isset($_POST['token']) && !empty($_POST['token'])) {
            $this->auth_token = sanitize_text_field($_POST['token']);
        } else {
            $this->auth_token = get_option('wc_civicrm_auth_token');
        }

        // Use our dedicated test function
        $result = $this->test_civicrm_connection();
        
        // Log the test result
        if (class_exists('WC_CiviCRM_Logger')) {
            if ($result['success']) {
                WC_CiviCRM_Logger::log_success('connection_test', [
                    'message' => 'CiviCRM connection test successful',
                    'url' => $this->civicrm_url
                ]);
            } else {
                WC_CiviCRM_Logger::log_error('connection_test', [
                    'message' => 'CiviCRM connection test failed',
                    'error' => $result['message'],
                    'url' => $this->civicrm_url
                ]);
            }
        }
        
        // Store connection status for future reference
        // Only update if using saved credentials, not temp ones from the form
        if (!isset($_POST['url']) && !isset($_POST['token'])) {
            update_option('wc_civicrm_connection_status', [
                'status' => $result['status'],
                'last_checked' => time(),
                'message' => $result['message']
            ]);
        }
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'status' => $result['status'],
                'status_text' => 'Connected',
                'details' => $result['data'] ?? []
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
                'status' => $result['status'],
                'status_text' => 'Disconnected',
                'debug_info' => $result['exception'] ?? $result['http_error'] ?? 'Connection failed'
            ], 400);
        }
        exit;
    }

    public function fetch_available_fields()
    {
        $fields = [
            'Contact' => [],
            'Contribution' => []
        ];
        
        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');
            
            // Validate credentials
            if (empty($this->civicrm_url) || empty($this->auth_token)) {
                error_log('CiviCRM Field Fetch Error: CiviCRM URL or Authentication Token is missing');
                return $fields;
            }

            // Direct API call for Contact fields using cURL
            $contact_fields = $this->fetch_entity_fields('Contact');
            if (!empty($contact_fields) && is_array($contact_fields)) {
                $fields['Contact'] = $contact_fields;
            }
            
            // Direct API call for Contribution fields using cURL
            $contribution_fields = $this->fetch_entity_fields('Contribution');
            if (!empty($contribution_fields) && is_array($contribution_fields)) {
                $fields['Contribution'] = $contribution_fields;
            }
            
            // Store the fields for later use
            $this->available_fields = $fields;
            
            // Debug output
            error_log('CiviCRM Field Fetch: Successfully fetched fields');
            error_log('Contact fields count: ' . count($fields['Contact']));
            error_log('Contribution fields count: ' . count($fields['Contribution']));
            error_log('First few Contact fields: ' . json_encode(array_slice($fields['Contact'], 0, 3)));
            error_log('First few Contribution fields: ' . json_encode(array_slice($fields['Contribution'], 0, 3)));
            
            return $fields;
            
        } catch (Exception $e) {
            // Log the error
            error_log('CiviCRM Field Fetch Error: ' . $e->getMessage());
            error_log('Error trace: ' . $e->getTraceAsString());
            
            // Initialize with empty arrays
            $this->available_fields = $fields;
            
            return $fields;
        }
    }
    
    /**
     * Helper method to fetch fields for a specific entity using direct cURL
     */
    private function fetch_entity_fields($entity)
    {
        $result = [];
        
        try {
            // Prepare endpoint for field fetching
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/' . $entity . '/getFields';
            
            error_log("Fetching $entity fields from endpoint: $endpoint");
            
            // Set up cURL
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->auth_token
            ]);
            
            // Set request parameters
            $params = [
                'select' => ['name', 'label', 'data_type'],
                'where' => [],
                'checkPermissions' => false
            ];
            
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            
            // Enable more verbose error reporting
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
            
            // Execute request
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Check for errors
            if (curl_errno($ch)) {
                rewind($verbose);
                $verboseLog = stream_get_contents($verbose);
                error_log("cURL verbose output for $entity: " . $verboseLog);
                throw new Exception('cURL error for ' . $entity . ': ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            // Log the raw response for debugging
            error_log("API response for $entity fields (HTTP $http_code): " . substr($response, 0, 500) . (strlen($response) > 500 ? '...' : ''));
            
            // Process response
            if ($http_code >= 200 && $http_code < 300) {
                $data = json_decode($response, true);
                
                if (is_array($data) && isset($data['values']) && is_array($data['values'])) {
                    foreach ($data['values'] as $field) {
                        if (is_array($field) && !empty($field['name'])) {
                            $result[] = [
                                'name' => $field['name'],
                                'label' => $field['label'] ?? $field['name'],
                                'type' => $entity
                            ];
                        }
                    }
                    
                    error_log("Successfully processed $entity fields, found: " . count($result));
                } else {
                    error_log("Invalid response format for $entity fields: " . json_encode($data));
                    
                    // Try alternative API version or format
                    if (strpos($response, 'api_version') !== false) {
                        // This might be API v3 response
                        $alternativeData = json_decode($response, true);
                        if (isset($alternativeData['values']) && is_array($alternativeData['values'])) {
                            foreach ($alternativeData['values'] as $key => $field) {
                                if (is_array($field)) {
                                    $result[] = [
                                        'name' => $key,
                                        'label' => $field['title'] ?? $field['name'] ?? $key,
                                        'type' => $entity
                                    ];
                                }
                            }
                            error_log("Fallback: processed $entity fields using alternative format, found: " . count($result));
                        }
                    }
                }
            } else {
                error_log("CiviCRM API Error: HTTP $http_code for $entity fields");
                error_log("Response: " . substr($response, 0, 200) . (strlen($response) > 200 ? '...' : ''));
                
                // Try fallback to API v3 if API v4 failed
                if ($http_code == 404 || $http_code == 400) {
                    error_log("Attempting fallback to API v3 for $entity");
                    $result = $this->fetch_entity_fields_v3($entity);
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching $entity fields: " . $e->getMessage());
        }
        
        // If we couldn't get fields from API, provide fallback defaults
        if (empty($result)) {
            $result = $this->get_default_fields($entity);
            error_log("Using default fields for $entity");
        }
        
        return $result;
    }
    
    /**
     * Try to fetch fields using CiviCRM API v3 as fallback
     */
    private function fetch_entity_fields_v3($entity) 
    {
        $result = [];
        
        try {
            // API v3 endpoint
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/rest?entity=' . $entity . '&action=getfields&json=1&api_version=3';
            
            error_log("Fetching $entity fields from v3 endpoint: $endpoint");
            
            // Set up cURL
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->auth_token
            ]);
            
            // Execute request
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            curl_close($ch);
            
            if ($http_code >= 200 && $http_code < 300) {
                $data = json_decode($response, true);
                
                if (isset($data['values']) && is_array($data['values'])) {
                    foreach ($data['values'] as $key => $field) {
                        if (is_array($field)) {
                            $result[] = [
                                'name' => $key,
                                'label' => $field['title'] ?? $field['name'] ?? $key,
                                'type' => $entity
                            ];
                        }
                    }
                    error_log("Successfully processed $entity fields via API v3, found: " . count($result));
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching $entity fields via API v3: " . $e->getMessage());
        }
        
        return $result;
    }

    /**
     * Get default field list as fallback when API fails
     */
    private function get_default_fields($entity)
    {
        if ($entity === 'Contact') {
            return [
                ['name' => 'contact_type', 'label' => 'Contact Type', 'type' => 'Contact'],
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'Contact'],
                ['name' => 'last_name', 'label' => 'Last Name', 'type' => 'Contact'],
                ['name' => 'email', 'label' => 'Email', 'type' => 'Contact'],
                ['name' => 'phone', 'label' => 'Phone', 'type' => 'Contact'],
                ['name' => 'street_address', 'label' => 'Street Address', 'type' => 'Contact'],
                ['name' => 'city', 'label' => 'City', 'type' => 'Contact'],
                ['name' => 'postal_code', 'label' => 'Postal Code', 'type' => 'Contact'],
                ['name' => 'state_province_id', 'label' => 'State/Province', 'type' => 'Contact'],
                ['name' => 'country_id', 'label' => 'Country', 'type' => 'Contact']
            ];
        } elseif ($entity === 'Contribution') {
            return [
                ['name' => 'financial_type_id', 'label' => 'Financial Type', 'type' => 'Contribution'],
                ['name' => 'total_amount', 'label' => 'Total Amount', 'type' => 'Contribution'],
                ['name' => 'currency', 'label' => 'Currency', 'type' => 'Contribution'],
                ['name' => 'contribution_status_id', 'label' => 'Contribution Status', 'type' => 'Contribution'],
                ['name' => 'payment_instrument_id', 'label' => 'Payment Method', 'type' => 'Contribution'],
                ['name' => 'source', 'label' => 'Source', 'type' => 'Contribution'],
                ['name' => 'receive_date', 'label' => 'Receive Date', 'type' => 'Contribution'],
                ['name' => 'invoice_id', 'label' => 'Invoice ID', 'type' => 'Contribution']
            ];
        }
        
        return [];
    }

    public function fetch_fields()
    {
        check_ajax_referer('fetch_civicrm_fields');

        try {
            // Log start of fetching
            error_log('Starting to fetch CiviCRM fields via AJAX handler');
            
            // Fetch fields
            $fields = $this->fetch_available_fields();
            
            // Log results
            error_log('Fetch complete. Contact fields: ' . count($fields['Contact']) . ', Contribution fields: ' . count($fields['Contribution']));
            
            // Return success with fields data
            wp_send_json_success([
                'contact_fields' => $fields['Contact'] ?? [],
                'contribution_fields' => $fields['Contribution'] ?? []
            ]);
        } catch (Exception $e) {
            error_log('Exception in fetch_fields: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error('Failed to fetch fields: ' . $e->getMessage());
        }
    }

    public function test_contact_creation()
    {
        // Verify nonce and user capabilities
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_contact_creation') || !current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Unauthorized access',
                'debug_info' => 'Invalid nonce or insufficient permissions'
            ], 403);
            exit;
        }

        try {
            // Set credentials
            $this->civicrm_url = get_option('wc_civicrm_url');
            $this->auth_token = get_option('wc_civicrm_auth_token');

            // Validate credentials are not empty
            if (empty($this->civicrm_url) || empty($this->auth_token)) {
                throw new Exception('CiviCRM URL or Authentication Token is missing');
            }

            // Prepare endpoint for contact creation
            $contact_endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contact/create';
            $email_endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Email/create';

            // Prepare test contact data
            $test_contact_data = [
                'contact_type' => 'Individual',
                'first_name' => 'Test',
                'last_name' => 'Contact_' . time(),
                'email' => 'test_contact_' . time() . '@example.com',
                'job_title' => 'Test Engineer'
            ];

            // Prepare the contact creation request
            $contact_fields = [
                'values' => [
                    'contact_type' => $test_contact_data['contact_type'],
                    'first_name' => $test_contact_data['first_name'],
                    'last_name' => $test_contact_data['last_name'],
                    'job_title' => $test_contact_data['job_title']
                ],
                'checkPermissions' => false,
                '_endpoint' => $contact_endpoint
            ];

            // Create contact first
            $contact_response = $this->send_civicrm_request('Contact', 'create', $contact_fields);

            // Validate the contact response
            if (!isset($contact_response['values']) || empty($contact_response['values'])) {
                throw new Exception('Failed to create test contact');
            }

            $contact_id = $contact_response['values'][0]['id'];

            // Create email
            $email_fields = [
                'values' => [
                    'contact_id' => $contact_id,
                    'email' => $test_contact_data['email']
                ],
                'checkPermissions' => false,
                '_endpoint' => $email_endpoint
            ];

            $email_response = $this->send_civicrm_request('Email', 'create', $email_fields);

            
            // Log responses for debugging
            error_log('Contact Creation Response: ' . json_encode($contact_response, JSON_PRETTY_PRINT));
            error_log('Email Creation Response: ' . json_encode($email_response, JSON_PRETTY_PRINT));
         
            wp_send_json_success([
                'message' => 'Test contact created successfully',
                'contact_details' => [
                    'id' => $contact_id,
                    'first_name' => $test_contact_data['first_name'],
                    'last_name' => $test_contact_data['last_name'],
                    'email_primary' => $test_contact_data['email']
                ]
            ]);
        } catch (Exception $e) {
            // Log detailed error information
            error_log('CiviCRM Test Contact Creation Failed: ' . $e->getMessage());

            WC_CiviCRM_Logger::log_error('test_contact_creation', [
                'message' => 'Test Contact Creation Failed',
                'error' => $e->getMessage(),
                'url' => $this->civicrm_url
            ]);

            wp_send_json_error([
                'message' => 'Contact creation failed: ' . $e->getMessage(),
                'debug_info' => 'Please check your CiviCRM API configuration'
            ], 400);
        }
        exit;
    }

    /**
     * Test contribution creation in CiviCRM
     */
    public function test_contribution_creation() {
        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action']);
            return;
        }

        // Verify nonce
        check_ajax_referer('test_contribution_creation', 'nonce');

        // Check if test contact ID is available
        $contact_id = 298731;
        if (!$contact_id) {
            wp_send_json_error(['message' => 'Test contact not found. Please create a test contact first.']);
            return;
        }

        // Get selected financial type (default to 1 - Donation)
        $financial_type_id = 9;
        
        // Get financial type name for display
        $financial_types = $this->get_saved_financial_types();
        $financial_type_name = 'Donation';
        
        foreach ($financial_types as $type) {
            if ($type['id'] == $financial_type_id) {
                $financial_type_name = $type['name'];
                break;
            }
        }

        // Prepare test contribution data
        $test_contribution_data = [
            'contact_id' => (int)$contact_id,
            'financial_type_id' => (int)$financial_type_id,
            'total_amount' => 10.00,
            'currency' => 'USD',
            'source' => 'WooCommerce CiviCRM Test',
            'receive_date' => date('Y-m-d H:i:s'),
            'payment_instrument_id' => 2, // Credit Card
            'is_pay_later' => 0,
            'is_test' => 1,
            'contribution_status_id' => 1 // Completed
        ];

        // Set up endpoint for contribution creation
        $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contribution/create';

        try {
            // Use the trait's send_civicrm_request method
            $response = $this->send_civicrm_request('Contribution', 'create', [
                'values' => $test_contribution_data,
                'checkPermissions' => false,
                '_endpoint' => $endpoint // Use the specific endpoint
            ]);

            // Check for API errors
            if (isset($response['error_message'])) {
                $this->log_error('Failed to create test contribution: ' . $response['error_message']);
                wp_send_json_error(['message' => 'Failed to create test contribution: ' . $response['error_message']]);
                return;
            }

            // Validate the response
            if (!isset($response['values']) || empty($response['values'])) {
                $this->log_error('Invalid response when creating test contribution');
                wp_send_json_error(['message' => 'Invalid response from CiviCRM']);
                return;
            }

            // Get created contribution details
            $created_contribution = $response['values'][0];

            wp_send_json_success([
                'message' => 'Test contribution created successfully',
                'contribution_details' => [
                    'id' => $created_contribution['id'],
                    'amount' => $test_contribution_data['total_amount'],
                    'currency' => $test_contribution_data['currency'],
                    'financial_type_id' => $financial_type_id,
                    'financial_type_name' => $financial_type_name,
                    'contact_id' => $test_contribution_data['contact_id']
                ]
            ]);
        } catch (Exception $e) {
            $this->log_error('Exception creating test contribution: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
            return;
        }
    }
    
    /**
     * Get test contact ID from transient
     * 
     * @return int|bool Contact ID or false if not found
     */
    private function get_test_contact_id() {
        return get_transient('wc_civicrm_test_contact_id');
    }

    /**
     * Check connection status on page load
     */
    public function check_connection_status() {
        $status = get_option('wc_civicrm_connection_status', [
            'status' => 'unknown',
            'last_checked' => 0,
            'message' => 'Connection status unknown'
        ]);
        
        // Make sure we have an array - if it's a string or any other type, use default values
        if (!is_array($status)) {
            $status = [
                'status' => 'unknown',
                'last_checked' => 0,
                'message' => 'Connection status data is invalid'
            ];
        }
        
        // Make sure all required keys exist
        if (!isset($status['status'])) $status['status'] = 'unknown';
        if (!isset($status['last_checked'])) $status['last_checked'] = 0;
        if (!isset($status['message'])) $status['message'] = 'Connection status incomplete';
        
        // If status is more than 15 minutes old, set to unknown
        if (time() - intval($status['last_checked']) > 900) {
            $status['status'] = 'unknown';
            $status['message'] = 'Connection status has not been checked recently';
        }
        
        return $status;
    }

    public function contribution_type_callback()
    {
        $selected_type = get_option('wc_civicrm_contribution_type_id', 1);
        $financial_types = $this->get_saved_financial_types();
        ?>
        <div class="wc-civicrm-field-wrapper">
            <select name="wc_civicrm_contribution_type_id" id="wc-civicrm-contribution-type">
                <?php if (empty($financial_types)): ?>
                    <option value="1">Donation</option>
                <?php else: ?>
                    <?php foreach ($financial_types as $type): ?>
                        <option value="<?php echo esc_attr($type['id']); ?>" <?php selected($selected_type, $type['id']); ?>>
                            <?php echo esc_html($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <p class="description">Select the financial type to use for contributions created from WooCommerce orders.</p>
            <button type="button" id="refresh-financial-types" class="button button-secondary">
                <span class="dashicons dashicons-update"></span> Refresh Types
            </button>
            <div id="financial-types-message"></div>
        </div>
        <?php
    }

    /**
     * Fetch available financial types from CiviCRM
     * 
     * @return array List of financial types with id and name
     */
    public function get_financial_types() {
        // Check if credentials are set - site_key is optional
        if (empty($this->civicrm_url) || empty($this->auth_token)) {
            return [
                'error' => true,
                'message' => 'CiviCRM credentials not set'
            ];
        }

        // Set up endpoint and headers
        $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/FinancialType/get';
        $headers = [
            'X-Civi-Auth' => 'Bearer ' . $this->auth_token,
            'X-Requested-With' => 'XMLHttpRequest',
            'Content-Type' => 'application/json'
        ];

        // Set up data
        $data = [
            'select' => ['id', 'name'],
            'where' => [['is_active', '=', true]],
            'checkPermissions' => false
        ];
        
        // Add site_key only if it's available
        if (!empty($this->site_key)) {
            $data['site_key'] = $this->site_key;
        }

        // Send request
        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => json_encode($data),
            'timeout' => 30
        ]);

        // Check for errors
        if (is_wp_error($response)) {
            $this->log_error('Failed to fetch financial types: ' . $response->get_error_message());
            return [
                'error' => true,
                'message' => 'Failed to connect to CiviCRM: ' . $response->get_error_message()
            ];
        }

        // Check status code
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->log_error('Failed to fetch financial types. Status code: ' . $status_code);
            return [
                'error' => true,
                'message' => 'Failed to fetch financial types. Status code: ' . $status_code
            ];
        }

        // Get response body
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);

        if (!$decoded_body || !isset($decoded_body['values'])) {
            $this->log_error('Invalid response when fetching financial types');
            return [
                'error' => true,
                'message' => 'Invalid response from CiviCRM'
            ];
        }

        return $decoded_body['values'];
    }

    /**
     * AJAX handler for fetching financial types
     */
    public function ajax_fetch_financial_types() {
        // Check nonce
        check_ajax_referer('fetch_financial_types', 'nonce');

        // Check user capability
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action']);
            return;
        }

        // Get financial types
        $financial_types = $this->get_financial_types();

        // Check for errors
        if (isset($financial_types['error'])) {
            wp_send_json_error(['message' => $financial_types['message']]);
            return;
        }

        // Format response data
        $options = [];
        foreach ($financial_types as $type) {
            $options[] = [
                'id' => $type['id'],
                'name' => $type['name']
            ];
        }

        // Save to option for future use
        update_option('wc_civicrm_financial_types', $options);

        wp_send_json_success([
            'message' => 'Financial types refreshed successfully',
            'types' => $options
        ]);
    }

    /**
     * Log error message
     */
    private function log_error($message) {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_error('settings_error', ['message' => $message]);
        } else {
            error_log('WC_CiviCRM Settings Error: ' . $message);
        }
    }

    /**
     * Get financial types from option or fetch if empty
     * 
     * @return array List of financial types
     */
    public function get_saved_financial_types() {
        $types = get_option('wc_civicrm_financial_types', []);
        
        // If empty and we have credentials, try to fetch
        if (empty($types) && !empty($this->civicrm_url) && !empty($this->auth_token)) {
            $fetched_types = $this->get_financial_types();
            if (!isset($fetched_types['error']) && !empty($fetched_types)) {
                $types = [];
                foreach ($fetched_types as $type) {
                    $types[] = [
                        'id' => $type['id'],
                        'name' => $type['name']
                    ];
                }
                update_option('wc_civicrm_financial_types', $types);
            }
        }
        
        return $types;
    }
}

// Initialize settings
new WC_CiviCRM_Settings();