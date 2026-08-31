<?php
/**
 * Rend le prénom, le nom et l'email de facturation obligatoires
 * pour la synchro WooCommerce → CiviCRM.
 *
 * Couvre : checkout classique, Checkout Blocks (Store API), admin, REST API.
 */

if (!defined('WPINC')) {
    die;
}

class WC_CiviCRM_Required_Billing_Fields
{
    public function __construct()
    {
        // Checkout classique (shortcode)
        add_filter('woocommerce_default_address_fields', [$this, 'require_name_fields'], 99);
        add_filter('woocommerce_billing_fields', [$this, 'require_billing_fields'], 99);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_classic_checkout'], 10, 2);

        // Checkout Blocks / Store API
        add_action(
            'woocommerce_store_api_checkout_update_order_from_request',
            [$this, 'validate_store_api_order'],
            10,
            2
        );

        // Édition de commande dans l'admin WooCommerce
        add_filter('woocommerce_admin_billing_fields', [$this, 'require_admin_billing_fields'], 99, 3);
        add_action('woocommerce_process_shop_order_meta', [$this, 'validate_admin_order'], 5, 2);

        // REST API (HPOS / intégrations)
        add_filter('woocommerce_rest_pre_insert_shop_order_object', [$this, 'validate_rest_order'], 10, 3);
    }

    /**
     * Force prénom et nom obligatoires (champs d'adresse WooCommerce).
     *
     * @param array $fields
     * @return array
     */
    public function require_name_fields($fields)
    {
        foreach (['first_name', 'last_name'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key]['required'] = true;
            }
        }

        return $fields;
    }

    /**
     * Force prénom, nom et email obligatoires sur le checkout classique.
     *
     * @param array $fields
     * @return array
     */
    public function require_billing_fields($fields)
    {
        foreach (['billing_first_name', 'billing_last_name', 'billing_email'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key]['required'] = true;
            }
        }

        return $fields;
    }

    /**
     * Validation serveur du checkout classique.
     *
     * @param array    $data
     * @param WP_Error $errors
     */
    public function validate_classic_checkout($data, $errors)
    {
        foreach ($this->collect_identity_errors(
            isset($data['billing_first_name']) ? (string) $data['billing_first_name'] : '',
            isset($data['billing_last_name']) ? (string) $data['billing_last_name'] : '',
            isset($data['billing_email']) ? (string) $data['billing_email'] : ''
        ) as $message) {
            $errors->add('wc_civicrm_billing_identity', $message);
        }
    }

    /**
     * Validation Store API (Checkout Blocks).
     *
     * @param WC_Order        $order
     * @param WP_REST_Request $request
     */
    public function validate_store_api_order($order, $request)
    {
        $messages = $this->collect_identity_errors(
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_email()
        );

        if (empty($messages)) {
            return;
        }

        $message = implode(' ', $messages);

        if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'wc_civicrm_missing_billing_identity',
                $message,
                400
            );
        }

        throw new Exception($message);
    }

    /**
     * Marque les champs admin comme requis visuellement (astérisque).
     * Pas d'attribut HTML5 required : les champs sont souvent masqués
     * tant que l'adresse n'est pas en édition, ce qui bloquerait l'enregistrement.
     *
     * @param array          $fields
     * @param WC_Order|false $order
     * @param string         $context
     * @return array
     */
    public function require_admin_billing_fields($fields, $order = false, $context = 'edit')
    {
        foreach (['first_name', 'last_name', 'email'] as $key) {
            if (!isset($fields[$key])) {
                continue;
            }

            $label = isset($fields[$key]['label']) ? $fields[$key]['label'] : $key;
            if (strpos($label, '*') === false) {
                $fields[$key]['label'] = $label . ' *';
            }
        }

        return $fields;
    }

    /**
     * Validation à l'enregistrement d'une commande dans l'admin.
     *
     * @param int            $order_id
     * @param WP_Post|WC_Order $order
     */
    public function validate_admin_order($order_id, $order = null)
    {
        if (!is_admin() || !class_exists('WC_Admin_Meta_Boxes')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce déjà vérifié par WooCommerce.
        $first = isset($_POST['_billing_first_name']) ? trim((string) wp_unslash($_POST['_billing_first_name'])) : '';
        $last  = isset($_POST['_billing_last_name']) ? trim((string) wp_unslash($_POST['_billing_last_name'])) : '';
        $email = isset($_POST['_billing_email']) ? trim((string) wp_unslash($_POST['_billing_email'])) : '';

        // Si le formulaire n'envoie pas ces champs (autre écran / action), on ne bloque pas.
        if (
            !isset($_POST['_billing_first_name'])
            && !isset($_POST['_billing_last_name'])
            && !isset($_POST['_billing_email'])
        ) {
            return;
        }

        foreach ($this->collect_identity_errors($first, $last, $email) as $message) {
            WC_Admin_Meta_Boxes::add_error($message);
        }
    }

    /**
     * Validation REST API avant insertion / mise à jour.
     *
     * Appliquée à la création, et aux mises à jour qui envoient un bloc billing.
     *
     * @param WC_Order        $order
     * @param WP_REST_Request $request
     * @param bool            $creating
     * @return WC_Order
     */
    public function validate_rest_order($order, $request, $creating)
    {
        $billing = $request->get_param('billing');
        $billing_posted = is_array($billing) && (
            array_key_exists('first_name', $billing)
            || array_key_exists('last_name', $billing)
            || array_key_exists('email', $billing)
        );

        if (!$creating && !$billing_posted) {
            return $order;
        }

        $messages = $this->collect_identity_errors(
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $order->get_billing_email()
        );

        if (empty($messages)) {
            return $order;
        }

        $message = implode(' ', $messages);

        if (class_exists('WC_REST_Exception')) {
            throw new WC_REST_Exception('wc_civicrm_missing_billing_identity', $message, 400);
        }

        throw new Exception($message);
    }

    /**
     * @param string $first_name
     * @param string $last_name
     * @param string $email
     * @return string[]
     */
    private function collect_identity_errors($first_name, $last_name, $email)
    {
        $first_name = trim($first_name);
        $last_name  = trim($last_name);
        $email      = trim($email);
        $errors     = [];

        if ($first_name === '') {
            $errors[] = __('Le prénom de facturation est obligatoire.', 'wc-civicrm');
        }

        if ($last_name === '') {
            $errors[] = __('Le nom de facturation est obligatoire.', 'wc-civicrm');
        }

        if ($email === '') {
            $errors[] = __('L’email de facturation est obligatoire.', 'wc-civicrm');
        } elseif (!is_email($email)) {
            $errors[] = __('L’email de facturation n’est pas valide.', 'wc-civicrm');
        }

        return $errors;
    }
}
