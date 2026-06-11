<?php
/**
 * Plugin Name: FORK WooCommerce to CiviCRM Integration
 * Description: FORK AIA Automatically creates CiviCRM orders from WooCommerce orders
 * Version: 1.0.3
 * Author: Loic Moncany
 * Author URI: https://iclick.space
 * License: GPLv2 or later
 * Text Domain: wc-civicrm
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 */

if (!defined('WPINC')) {
    die;
}

// Load required files
$plugin_dir = plugin_dir_path(__FILE__);
// Main plugin file should require all dependencies at the start
require_once plugin_dir_path(__FILE__) . 'logging.php';
require_once plugin_dir_path(__FILE__) . 'send-civicrm-request.php';
require_once plugin_dir_path(__FILE__) . 'settings-page.php';

// Check for required plugins
register_activation_hook(__FILE__, function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('This plugin requires WooCommerce to be installed and active.', 'wc-civicrm'));
    }

    // Set default field mappings if not exists
    if (!get_option('wc_civicrm_field_mappings')) {
        // Customer Information
        $default_mappings = [
            // Basic Info - Contact Fields
            'billing_first_name' => ['field' => 'first_name', 'type' => 'Contact'],
            'billing_last_name' => ['field' => 'last_name', 'type' => 'Contact'],
            'billing_company' => ['field' => 'organization_name', 'type' => 'Contact'],
            'billing_email' => ['field' => 'email', 'type' => 'Contact'],
            'billing_phone' => ['field' => 'phone', 'type' => 'Contact'],

            // Billing Address - Contact Fields
            'billing_address_1' => ['field' => 'street_address', 'type' => 'Contact'],
            'billing_address_2' => ['field' => 'supplemental_address_1', 'type' => 'Contact'],
            'billing_city' => ['field' => 'city', 'type' => 'Contact'],
            'billing_state' => ['field' => 'state_province', 'type' => 'Contact'],
            'billing_postcode' => ['field' => 'postal_code', 'type' => 'Contact'],
            'billing_country' => ['field' => 'country', 'type' => 'Contact'],

            // Shipping Address - Contact Fields
            'shipping_first_name' => ['field' => 'shipping_first_name', 'type' => 'Contact'],
            'shipping_last_name' => ['field' => 'shipping_last_name', 'type' => 'Contact'],
            'shipping_company' => ['field' => 'shipping_organization', 'type' => 'Contact'],
            'shipping_address_1' => ['field' => 'shipping_street_address', 'type' => 'Contact'],
            'shipping_address_2' => ['field' => 'shipping_supplemental_address_1', 'type' => 'Contact'],
            'shipping_city' => ['field' => 'shipping_city', 'type' => 'Contact'],
            'shipping_state' => ['field' => 'shipping_state_province', 'type' => 'Contact'],
            'shipping_postcode' => ['field' => 'shipping_postal_code', 'type' => 'Contact'],
            'shipping_country' => ['field' => 'shipping_country', 'type' => 'Contact'],

            // Order Information - Contribution Fields
            'order_number' => ['field' => 'external_identifier', 'type' => 'Contribution'],
            'order_currency' => ['field' => 'currency', 'type' => 'Contribution'],
            'order_total' => ['field' => 'total_amount', 'type' => 'Contribution'],
            'order_status' => ['field' => 'contribution_status', 'type' => 'Contribution'],
            'payment_method' => ['field' => 'payment_instrument', 'type' => 'Contribution'],
            'order_notes' => ['field' => 'note', 'type' => 'Contribution']
        ];

        update_option('wc_civicrm_field_mappings', $default_mappings);
    }
});

// Initialize plugin only if WooCommerce is active
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        // Check for required files
        $plugin_dir = plugin_dir_path(__FILE__);
        $required_files = [
            $plugin_dir . 'logging.php' => 'Logging',
            $plugin_dir . 'send-civicrm-request.php' => 'API Request',
            $plugin_dir . 'settings-page.php' => 'Settings Page'
        ];

        $missing_files = [];
        foreach ($required_files as $file => $name) {
            if (!file_exists($file)) {
                $missing_files[] = $name;
            }
        }

        if (!empty($missing_files)) {
            add_action('admin_notices', function () use ($missing_files) {
                $list = implode(', ', $missing_files);
                echo '<div class="error"><p>Required files missing in WooCommerce to CiviCRM plugin: ' . $list . '</p></div>';
            });
            return;
        }

        // Load settings page after trait and logger are loaded
        require_once $plugin_dir . 'settings-page.php';

        // Initialize plugin after all dependencies are loaded
        new WooCommerceCiviCRMIntegration();
    } else {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>WooCommerce to CiviCRM plugin requires WooCommerce to be installed and active.</p></div>';
        });
    }
});


class WooCommerceCiviCRMIntegration
{
    use WC_CiviCRM_API_Request {
        WC_CiviCRM_API_Request::send_civicrm_request as protected;
    }

    private $civicrm_url;
    private $auth_token;
    private $field_mappings;

    private function is_civicrm_configured()
    {
        return !empty($this->civicrm_url) && !empty($this->auth_token);
    }

    public function get_api_endpoint($entity, $action)
    {
        // Construct the CiviCRM API4 endpoint based on the entity and action
        return rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/' . $entity . '/' . $action;
    }

    private function load_settings()
    {
        $this->civicrm_url = get_option('wc_civicrm_url');
        $this->auth_token = get_option('wc_civicrm_auth_token');
        $this->field_mappings = get_option('wc_civicrm_field_mappings', []);
    }

    public function __construct()
    {
        $this->load_settings();

        // Order processing hooks
        add_action('woocommerce_order_status_changed', [$this, 'debug_order_status'], 10, 4);
        add_action('woocommerce_checkout_order_processed', [$this, 'debug_order_created'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'handle_order_completed']);
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_completed']);
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 4);

        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_test_civicrm_connection', [$this, 'ajax_test_connection']);

        require_once plugin_dir_path(__FILE__) . 'send-civicrm-request.php';
    }

    public function handle_status_change($order_id, $old_status, $new_status, $order)
    {
        WC_CiviCRM_Logger::log_success('status_change', [
            'message' => 'Order status change detected',
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'settings' => [
                'has_url' => !empty($this->civicrm_url),
                'has_token' => !empty($this->auth_token),
                'has_mappings' => !empty($this->field_mappings)
            ]
        ]);

        if ($new_status === 'completed') {
            $this->handle_order_completed($order_id);
        }
    }

    public function ajax_test_connection()
    {
        check_ajax_referer('test_civicrm_connection');

        try {
            WC_CiviCRM_Logger::log_success('connection_test', [
                'message' => 'Testing CiviCRM connection'
            ]);

            $result = $this->check_civicrm_connection();

            if ($result) {
                WC_CiviCRM_Logger::log_success('connection_test', [
                    'message' => 'Connection test successful'
                ]);
                wp_send_json_success('Connection successful');
            } else {
                WC_CiviCRM_Logger::log_error('connection_test', [
                    'message' => 'Connection test failed',
                    'error' => 'API check returned false'
                ]);
                wp_send_json_error('Connection failed - API check returned false');
            }
        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('connection_test', [
                'message' => 'Connection test failed',
                'error' => $e->getMessage()
            ]);
            wp_send_json_error('Connection failed: ' . $e->getMessage());
        }
    }

    public function debug_order_status($order_id, $old_status, $new_status, $order)
    {
        WC_CiviCRM_Logger::log_success('debug_order_status', [
            'message' => 'Order status changed',
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'settings' => [
                'has_url' => !empty($this->civicrm_url),
                'has_token' => !empty($this->auth_token),
                'has_mappings' => !empty($this->field_mappings)
            ]
        ]);
    }

    public function debug_order_created($order_id)
    {
        WC_CiviCRM_Logger::log_success('debug_order_created', [
            'message' => 'New order created',
            'order_id' => $order_id,
            'settings' => [
                'has_url' => !empty($this->civicrm_url),
                'has_token' => !empty($this->auth_token),
                'has_mappings' => !empty($this->field_mappings)
            ]
        ]);
    }

    /**
     * Handle completed WooCommerce orders
     *
     * @param int $order_id The WooCommerce order ID
     */
    public function handle_order_completed($order_id) {
        // Check if CiviCRM settings are configured - site_key is optional
        if (empty(get_option('wc_civicrm_url')) || empty(get_option('wc_civicrm_auth_token'))) {
            $this->log_error("CiviCRM settings not configured. Order sync skipped for order #$order_id");
            return;
        }

        // Get the WooCommerce order
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_error("Could not find order #$order_id");
            return;
        }

        // Check if this order has already been synced to CiviCRM
        $is_synced = get_post_meta($order_id, '_civicrm_synced', true);
        if ($is_synced) {
            $this->log_debug("Order #$order_id already synced to CiviCRM. Skipping.");
            return;
        }

        // Extract order data for contact creation
        $order_data = $this->extract_order_data($order);
        
        // Get or create contact
        $contact_id = $this->get_or_create_contact($order_data);
        if (!$contact_id) {
            $this->log_error("Failed to get or create contact for order #$order_id");
            return;
        }
        
        // Get the configured financial type ID - validation will happen in create_civicrm_contribution
        $financial_type_id = (int)get_option('wc_civicrm_contribution_type_id', 1);
        $this->log_debug("Using financial type ID: $financial_type_id for order #$order_id");

        // Prepare contribution data
        $contribution_data = [
            'contact_id' => (int)$contact_id,
            'financial_type_id' => (int)$financial_type_id,
            'total_amount' => (float)$order->get_total(),
            'currency' => $order->get_currency(),
            'source' => 'WooCommerce Order #' . $order_id,
            'receive_date' => $order->get_date_created()->date('Y-m-d H:i:s'),
            'payment_instrument_id' => 2, // modif Dewy ASPAS changement du payment_instrument_id à 2 -> Carte Bancaire
            'is_pay_later' => 0,
            'is_test' => 0,
            'contribution_status_id' => 1, // Completed
        ];
        
        // Create contribution in CiviCRM
        $contribution_id = $this->create_civicrm_contribution($contribution_data);
        if (!$contribution_id) {
            $this->log_error("Failed to create contribution for order #$order_id");
            return;
        }
        
        // Mark order as synced
        update_post_meta($order_id, '_civicrm_synced', 1);
        update_post_meta($order_id, '_civicrm_contact_id', $contact_id);
        update_post_meta($order_id, '_civicrm_contribution_id', $contribution_id);
        
        $this->log_debug("Order #$order_id synced to CiviCRM. Contact ID: $contact_id, Contribution ID: $contribution_id");
    }

    // Helper method for logging errors
    private function log_error($message) {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_error('plugin_error', ['message' => $message]);
        } else {
            error_log('WC_CiviCRM Error: ' . $message);
        }
    }
    
    // Helper method for logging debug messages
    private function log_debug($message) {
        if (class_exists('WC_CiviCRM_Logger')) {
            WC_CiviCRM_Logger::log_success('plugin_debug', ['message' => $message]);
        } else {
            error_log('WC_CiviCRM Debug: ' . $message);
        }
    }

    // Helper method to map WooCommerce payment methods to CiviCRM payment instruments
    private function map_payment_method($wc_payment_method)
    {
        // Default to 1 (Credit Card) if no mapping is found
        $payment_method_map = [
            'stripe' => 1,  // Credit Card
            'paypal' => 2,  // Check (adjust based on your CiviCRM configuration)
            'bacs' => 4,    // Bank Transfer
            'cod' => 5,     // Cash
            // Add more mappings as needed
        ];

        return $payment_method_map[$wc_payment_method] ?? 1;
    }

    private function extract_order_data($order)
    {
        $data = [
            // Basic Info
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_company' => $order->get_billing_company(),
            'billing_email' => $order->get_billing_email(),
            'billing_phone' => $order->get_billing_phone(),

            // Billing Address
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_city' => $order->get_billing_city(),
            'billing_state' => $order->get_billing_state(),
            'billing_postcode' => $order->get_billing_postcode(),
            'billing_country' => $order->get_billing_country(),

            // Shipping Address
            'shipping_first_name' => $order->get_shipping_first_name(),
            'shipping_last_name' => $order->get_shipping_last_name(),
            'shipping_company' => $order->get_shipping_company(),
            'shipping_address_1' => $order->get_shipping_address_1(),
            'shipping_address_2' => $order->get_shipping_address_2(),
            'shipping_city' => $order->get_shipping_city(),
            'shipping_state' => $order->get_shipping_state(),
            'shipping_postcode' => $order->get_shipping_postcode(),
            'shipping_country' => $order->get_shipping_country(),

            // Order Information
            'order_number' => $order->get_order_number(),
            'order_currency' => $order->get_currency(),
            'order_total' => $order->get_total(),
            'order_status' => $order->get_status(),
            'payment_method' => $order->get_payment_method(),
            'order_notes' => $order->get_customer_note(),

            // Additional fields for internal use
            'transaction_id' => $order->get_transaction_id(),
            'order_id' => $order->get_id()
        ];



        // Filter out empty values
        return array_filter($data, function ($value) {
            return !empty($value) || $value === 0 || $value === '0';
        });
    }

    private function create_civicrm_contribution($contribution_data)
    {
        try {
            // Make sure we have credentials
            if (!$this->is_civicrm_configured()) {
                $this->log_error("CiviCRM credentials not configured");
                return false;
            }
            
            // Log the data we're about to send (with sensitive data redacted)
            $debug_data = $contribution_data;
            if (isset($debug_data['contact_id'])) {
                $debug_data['contact_id'] = 'REDACTED-' . substr($debug_data['contact_id'], -4);
            }
            $this->log_debug("Contribution data: " . json_encode($debug_data));
            
            // Make sure the data types are correct for CiviCRM
            // Convert string values to appropriate types
            if (isset($contribution_data['contact_id']) && is_string($contribution_data['contact_id'])) {
                $contribution_data['contact_id'] = (int)$contribution_data['contact_id'];
            }
            
            if (isset($contribution_data['financial_type_id']) && is_string($contribution_data['financial_type_id'])) {
                $contribution_data['financial_type_id'] = (int)$contribution_data['financial_type_id'];
            }
            
            if (isset($contribution_data['total_amount']) && is_string($contribution_data['total_amount'])) {
                $contribution_data['total_amount'] = (float)$contribution_data['total_amount'];
            }
            
            // Set directly the endpoint - match what works in test_contribution_creation
            $endpoint = rtrim($this->civicrm_url, '/') . '/civicrm/ajax/api4/Contribution/create';
            
            // Simplify the contribution data to match what works in test method
            $simplified_data = [
                'contact_id' => $contribution_data['contact_id'],
                'financial_type_id' => $contribution_data['financial_type_id'],
                'total_amount' => $contribution_data['total_amount'],
                'currency' => $contribution_data['currency'],
                // Simpler source text to avoid potential character issues
                'source' => ($contribution_data['source'] ?? ''), // modif Dewy pour PRESTA ASPAS
                // Use current date if not provided
                'receive_date' => $contribution_data['receive_date'] ?? date('Y-m-d H:i:s'),
                // Default values that work in test method
                'payment_instrument_id' => 2, // modif Dewy ASPAS changement du payment_instrument_id à 2 -> Carte Bancaire
                'contribution_status_id' => 1, // Completed
                'is_test' => 0
            ];

            error_log('message contribution data: ' . json_encode($contribution_data));
            error_log('message simplified data: ' . json_encode($simplified_data));

            $this->log_debug("Using simplified contribution data: " . json_encode($simplified_data));
            
            // Try to create the contribution
            $result = $this->send_civicrm_request('Contribution', 'create', [
                'values' => $simplified_data,
                'checkPermissions' => false,
                '_endpoint' => $endpoint // Force specific endpoint
            ]);
            
            // Check for errors in the response
            if (!$result || isset($result['error_message'])) {
                $error = isset($result['error_message']) ? $result['error_message'] : 'Unknown error';
                $this->log_error("Failed to create contribution: $error");
                
                // If it's a constraint violation, try with known working defaults
                if (isset($result['error_message']) && strpos($result['error_message'], 'constraint violation') !== false) {
                    $this->log_debug("Constraint violation - trying with minimal data");
                    
                    // Create minimal test data with proven working values
                    $minimal_data = [
                        'contact_id' => $simplified_data['contact_id'],
                        'financial_type_id' => 1, // Use Don (most likely to work)
                        'total_amount' => $simplified_data['total_amount'],
                        'currency' => $simplified_data['currency'],
                        'source' => 'WooCommerce Test',
                        'receive_date' => date('Y-m-d H:i:s'),
                        'payment_instrument_id' => 2, // modif Dewy ASPAS changement du payment_instrument_id à 2 -> Carte Bancaire
                        'contribution_status_id' => 1,
                        'is_test' => 0
                    ];
                    
                    $this->log_debug("Trying with minimal data: " . json_encode($minimal_data));
                    
                    // Try with minimal data
                    $result = $this->send_civicrm_request('Contribution', 'create', [
                        'values' => $minimal_data,
                        'checkPermissions' => false,
                        '_endpoint' => $endpoint
                    ]);
                    
                    if (!$result || isset($result['error_message'])) {
                        $this->log_error("Minimal data attempt also failed: " . 
                                        (isset($result['error_message']) ? $result['error_message'] : 'Unknown error'));
                        return false;
                    }
                } else {
                    return false;
                }
            }
            
            // Validate that we got values back
            if (empty($result['values']) || empty($result['values'][0]['id'])) {
                $this->log_error("No contribution ID returned: " . json_encode($result));
                return false;
            }
            
            // Get the contribution ID
            $contribution_id = $result['values'][0]['id'];
            $this->log_debug("Contribution created successfully. ID: " . $contribution_id);
            
            return $contribution_id;
        } catch (Exception $e) {
            $this->log_error("Exception creating contribution: " . $e->getMessage());
            return false;
        }
    }

    private function get_or_create_contact($order_data)
    {
        if (!$this->is_civicrm_configured()) {
            WC_CiviCRM_Logger::log_error('civicrm_not_configured', [
                'message' => 'CiviCRM settings are not fully configured',
                'order_data' => $order_data
            ]);
            throw new Exception('CiviCRM integration is not properly configured');
        }

        error_log('message order data: ' . json_encode($order_data));

        try {
            // Validate required order data
            $required_order_fields = ['billing_email', 'billing_first_name', 'billing_last_name'];
            $missing_fields = array_filter($required_order_fields, function ($field) use ($order_data) {
                return empty($order_data[$field]);
            });

            if (!empty($missing_fields)) {
                throw new Exception('Missing required fields: ' . implode(', ', $missing_fields));
            }

            // Determine contact type
            $contact_type = !empty($order_data['billing_company']) ? 'Organization' : 'Individual';

            // Prepare contact data
            $contact_data = [
                'contact_type' => $contact_type,
                'first_name' => $order_data['billing_first_name'],
                'last_name' => $order_data['billing_last_name']
            ];


            // Search for existing contact by email first
            // debut modif Dewy pour PRESTA ASPAS
            $email_search_params = [
                'select' => ['id', 'contact_id.contact_type', 'contact_id.first_name', 'contact_id.last_name', 'contact_id'],
                'where' => [
                    ['email', '=', $order_data['billing_email']]
                ],
                'limit' => 1,
                'checkPermissions' => false
            ];

            $existing_contacts_by_email = $this->send_civicrm_request('Email', 'get', $email_search_params);

            // If contact found by email, return or update
            if (!empty($existing_contacts_by_email['values'])) {
                $existing_contact = $existing_contacts_by_email['values'][0];

                // Log found contact details
                WC_CiviCRM_Logger::log_success('contact_found_by_email', [
                    'message' => 'Existing contact located by email',
                    'contact_id' => $existing_contact['contact_id'],
                    'existing_name' => ($existing_contact['contact_id.first_name'] ?? '') . ' ' . ($existing_contact['contact_id.last_name'] ?? '')
                ]);

                //error_log('message existing contact id : ' . json_encode($existing_contact['contact_id']));
                //error_log('message existing contact: ' . json_encode($existing_contact));

                return $existing_contact['contact_id'];
            }
            // fin modif Dewy pour PRESTA ASPAS

            // If no contact found by email, search by name
            $name_search_params = [
                'select' => ['id', 'contact_type', 'first_name', 'last_name'],
                'where' => [
                    ['first_name', '=', $order_data['billing_first_name']],
                    ['last_name', '=', $order_data['billing_last_name']]
                ],
                'limit' => 1,
                'checkPermissions' => false
            ];

            $existing_contacts_by_name = $this->send_civicrm_request('Contact', 'get', $name_search_params);

            // If contact found by name, return or update
            if (!empty($existing_contacts_by_name['values'])) {
                $existing_contact = $existing_contacts_by_name['values'][0];

                // Log found contact details
                WC_CiviCRM_Logger::log_success('contact_found_by_name', [
                    'message' => 'Existing contact located by name',
                    'contact_id' => $existing_contact['id'],
                    'existing_name' => ($existing_contact['first_name'] ?? '') . ' ' . ($existing_contact['last_name'] ?? '')
                ]);

                return $existing_contact['id'];
            }

            // Create new contact first, without the email
            $create_params = [
                'values' => array_merge($contact_data, [
                    // Add address if available
                    'address' => [
                        'street_address' => $order_data['billing_address_1'] ?? '',
                        'supplemental_address_1' => $order_data['billing_address_2'] ?? '',
                        'city' => $order_data['billing_city'] ?? '',
                        'postal_code' => $order_data['billing_postcode'] ?? '',
                        'is_primary' => 1,
                        'location_type_id' => 1 // Typically 'Home' location type
                    ]
                ]),
                'checkPermissions' => false
            ];

            // Remove null values
            $create_params['values'] = array_filter($create_params['values']);

            // Create new contact
            $create_response = $this->send_civicrm_request('Contact', 'create', $create_params);

            // Validate creation response
            if (empty($create_response['values']) || empty($create_response['values'][0]['id'])) {
                throw new Exception('Failed to create contact: No ID returned');
            }

            $contact_id = $create_response['values'][0]['id'];

            // Now create the email in a separate request
            $email_params = [
                'values' => [
                    'contact_id' => $contact_id,
                    'email' => $order_data['billing_email'],
                    'is_primary' => 1,
                    'location_type_id' => 1 // Typically 'Home' location type
                ],
                'checkPermissions' => false
            ];
            
            $email_response = $this->send_civicrm_request('Email', 'create', $email_params);
            
            // Create phone if available
            if (!empty($order_data['billing_phone'])) {
                $phone_params = [
                    'values' => [
                        'contact_id' => $contact_id,
                        'phone' => $order_data['billing_phone'],
                        'is_primary' => 1,
                        'phone_type_id' => 1, // Typically 'Phone' type
                        'location_type_id' => 1 // Typically 'Home' location type
                    ],
                    'checkPermissions' => false
                ];
                
                $this->send_civicrm_request('Phone', 'create', $phone_params);
            }

            // Log new contact creation with more details
            WC_CiviCRM_Logger::log_success('contact_creation', [
                'message' => 'New contact created',
                'contact_id' => $contact_id,
                'email' => $order_data['billing_email'],
                'name' => $order_data['billing_first_name'] . ' ' . $order_data['billing_last_name']
            ]);

            return $contact_id;
        } catch (Exception $e) {
            // Comprehensive error logging
            WC_CiviCRM_Logger::log_error('contact_process_error', [
                'message' => 'Contact processing failed',
                'error' => $e->getMessage(),
                'order_data' => $order_data
            ]);

            throw $e;
        }
    }


    public function add_admin_pages()
    {
        // Add main menu
        add_menu_page(
            'WooCommerce CiviCRM',
            'WC CiviCRM',
            'manage_options',
            'wc-civicrm-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic'
        );

        // Add Settings submenu
        add_submenu_page(
            'wc-civicrm-settings',
            'WooCommerce CiviCRM Settings',
            'Settings',
            'manage_options',
            'wc-civicrm-settings'
        );

        // Add Logs submenu
        add_submenu_page(
            'wc-civicrm-settings',
            'CiviCRM Integration Logs',
            'Logs',
            'manage_options',
            'wc-civicrm-logs',
            function () {
                if (!class_exists('WC_CiviCRM_Logs_Page')) {
                    require_once plugin_dir_path(__FILE__) . 'logs-page.php';
                }
                $logs_page = new WC_CiviCRM_Logs_Page();
                $logs_page->render();
            }
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wc_civicrm_settings');
                do_settings_sections('wc-civicrm-settings');
                // submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function check_civicrm_connection()
    {
        try {
            // Use System.get to check API access
            $response = $this->send_civicrm_request('System', 'get', [
                'select' => ['id'],
                'where' => [],
                'checkPermissions' => false
            ]);

            // API4 returns an array of results
            return is_array($response);
        } catch (Exception $e) {
            WC_CiviCRM_Logger::log_error('connection_test', [
                'message' => 'Connection test failed',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function show_admin_notices()
    {
        if (!empty($_GET['wc_civicrm_notice'])) {
            $notice = sanitize_text_field($_GET['wc_civicrm_notice']);
            $class = strpos($notice, 'error') !== false ? 'error' : 'updated';
        ?>
            <div class="notice <?php echo esc_attr($class); ?>">
                <p><?php echo esc_html(urldecode($notice)); ?></p>
            </div>
<?php
        }
    }

    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'wc-civicrm') !== false) {
            // Check if the file exists before enqueueing
            $css_path = plugin_dir_path(__FILE__) . 'assets/css/admin.css';
            $js_path = plugin_dir_path(__FILE__) . 'assets/js/admin.js';
            
            // Only enqueue if files exist or use fallback
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'wc-civicrm-admin',
                    plugins_url('assets/css/admin.css', __FILE__),
                    [],
                    '1.0'
                );
            } else {
                // Log the missing file
                $this->log_debug("Admin CSS file not found at: $css_path");
            }
            
            if (file_exists($js_path)) {
                wp_enqueue_script(
                    'wc-civicrm-admin',
                    plugins_url('assets/js/admin.js', __FILE__),
                    ['jquery'],
                    '1.0',
                    true
                );
            } else {
                // Log the missing file
                $this->log_debug("Admin JS file not found at: $js_path");
            }
        }
    }

    /**
     * Fetch and update the list of available financial types from CiviCRM
     * 
     * @return array|false List of financial types or false on error
     */
    public function refresh_financial_types() {
        if (!$this->is_civicrm_configured()) {
            return false;
        }
        
        try {
            $response = $this->send_civicrm_request('FinancialType', 'get', [
                'select' => ['id', 'name', 'description', 'is_active'],
                'checkPermissions' => false
            ]);
            
            if (!isset($response['values']) || !is_array($response['values'])) {
                return false;
            }
            
            $types = [];
            foreach ($response['values'] as $type) {
                $types[] = [
                    'id' => $type['id'],
                    'name' => $type['name'],
                    'description' => $type['description'] ?? '',
                    'is_active' => $type['is_active'] ?? true
                ];
            }
            
            // Save the list to WordPress options
            update_option('wc_civicrm_financial_types', $types);
            
            $this->log_debug('Financial types refreshed from CiviCRM. Found ' . count($types) . ' types.');
            
            return $types;
        } catch (Exception $e) {
            $this->log_error('Error refreshing financial types: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a financial type ID exists in CiviCRM
     * 
     * @param int $financial_type_id The ID to check
     * @return bool True if exists, false otherwise
     */
    public function financial_type_exists($financial_type_id) {
        $financial_types = get_option('wc_civicrm_financial_types', []);
        
        // If empty, try to fetch
        if (empty($financial_types)) {
            $financial_types = $this->refresh_financial_types();
            if (!$financial_types) {
                return false;
            }
        }
        
        // Check if the ID exists in our cached list
        foreach ($financial_types as $type) {
            if ((int)$type['id'] === (int)$financial_type_id) {
                return true;
            }
        }
        
        // ID not found in cached list, try to fetch directly (might be a new type)
        try {
            $response = $this->send_civicrm_request('FinancialType', 'get', [
                'select' => ['id'],
                'where' => [['id', '=', (int)$financial_type_id]],
                'checkPermissions' => false
            ]);
            
            return !empty($response['values']);
        } catch (Exception $e) {
            $this->log_error('Error checking financial type: ' . $e->getMessage());
            return false;
        }
    }
}
