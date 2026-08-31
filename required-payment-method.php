<?php
/**
 * Empêche de terminer une commande WooCommerce (et donc la synchro CiviCRM)
 * si le moyen de paiement est ND (vide) ou Autre (other).
 *
 * Couvre : formulaire admin (ajout / édition), lots / actions rapides,
 * REST API, et filet dans handle_order_completed().
 */

if (!defined('WPINC')) {
    die;
}

class WC_CiviCRM_Required_Payment_Method
{
    /**
     * Gateway WooCommerce inutilisable pour une contribution CiviCRM.
     *
     * @param string $method Id machine (ex. stripe) ou vide / other
     */
    public static function is_unusable($method)
    {
        $method = (string) $method;

        return $method === '' || $method === 'other';
    }

    public static function error_message()
    {
        return __('Impossible de terminer la commande : choisissez un moyen de paiement (pas ND ni Autre).', 'wc-civicrm');
    }

    public function __construct()
    {
        // Formulaire admin : avant WC_Meta_Box_Order_Data::save (prio 40)
        add_action('woocommerce_process_shop_order_meta', [$this, 'block_admin_completed'], 5, 2);

        // Lots, actions rapides, update_status() hors formulaire
        add_action('woocommerce_before_order_object_save', [$this, 'revert_completed_without_payment'], 10, 1);

        // REST API
        add_filter('woocommerce_rest_pre_insert_shop_order_object', [$this, 'validate_rest_order'], 10, 3);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_order_admin_script']);
    }

    /**
     * Si le staff passe la commande en completed avec ND / Autre,
     * on réécrit le statut POST pour que WooCommerce n’applique pas completed.
     *
     * @param int                  $order_id
     * @param WP_Post|WC_Order|null $order
     */
    public function block_admin_completed($order_id, $order = null)
    {
        if (!is_admin()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce déjà vérifié par WooCommerce.
        if (!isset($_POST['order_status'], $_POST['_payment_method'])) {
            return;
        }

        $status = wc_clean(wp_unslash($_POST['order_status']));
        $method = wc_clean(wp_unslash($_POST['_payment_method']));

        if (!$this->is_completed_status($status) || !self::is_unusable($method)) {
            return;
        }

        $wc_order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
        $keep     = $wc_order instanceof WC_Order ? $wc_order->get_status() : 'pending';
        $keep     = $this->usable_fallback_status($keep);

        $_POST['order_status'] = 'wc-' . $keep;

        if (class_exists('WC_Admin_Meta_Boxes')) {
            WC_Admin_Meta_Boxes::add_error(self::error_message());
        }
    }

    /**
     * Annule un passage en completed si le moyen de paiement est toujours ND / Autre.
     * Ne touche pas une commande déjà completed (historique).
     *
     * @param WC_Data $order
     */
    public function revert_completed_without_payment($order)
    {
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') {
            return;
        }

        if (!self::is_unusable($order->get_payment_method())) {
            return;
        }

        if (!$this->is_completed_status($order->get_status())) {
            return;
        }

        $changes = $order->get_changes();
        if (empty($changes['status'])) {
            return;
        }

        $previous = $this->usable_fallback_status($this->get_persisted_status($order));
        $order->set_status(
            $previous,
            __('CiviCRM : passage en Terminée bloqué — moyen de paiement ND ou Autre.', 'wc-civicrm'),
            true
        );

        if (is_admin() && class_exists('WC_Admin_Meta_Boxes')) {
            WC_Admin_Meta_Boxes::add_error(self::error_message());
        }
    }

    /**
     * @param WC_Order        $order
     * @param WP_REST_Request $request
     * @param bool            $creating
     * @return WC_Order
     */
    public function validate_rest_order($order, $request, $creating)
    {
        $status = (string) $order->get_status();
        $posted_status = $request->get_param('status');
        if (is_string($posted_status) && $posted_status !== '') {
            $status = $posted_status;
        }

        if (!$this->is_completed_status($status)) {
            return $order;
        }

        $method = (string) $order->get_payment_method();
        $posted_method = $request->get_param('payment_method');
        if ($posted_method !== null) {
            $method = (string) $posted_method;
        }

        if (!self::is_unusable($method)) {
            return $order;
        }

        $message = self::error_message();

        if (class_exists('WC_REST_Exception')) {
            throw new WC_REST_Exception('wc_civicrm_invalid_payment_method', $message, 400);
        }

        throw new Exception($message);
    }

    /**
     * Masque ND / Autre dans le select, sauf s’ils sont déjà la valeur de la commande.
     *
     * @param string $hook
     */
    public function enqueue_order_admin_script($hook)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        $path = plugin_dir_path(__FILE__) . 'assets/js/order-payment-method.js';
        if (!file_exists($path)) {
            return;
        }

        wp_enqueue_script(
            'wc-civicrm-order-payment-method',
            plugins_url('assets/js/order-payment-method.js', __FILE__),
            ['jquery'],
            '1.0.5',
            true
        );

        wp_localize_script('wc-civicrm-order-payment-method', 'wcCivicrmPaymentMethod', [
            'placeholder' => __('Sélectionner un moyen de paiement', 'wc-civicrm'),
            'ndLabel'     => __('ND', 'wc-civicrm'),
        ]);
    }

    /**
     * @param string $status completed, wc-completed, etc.
     */
    private function is_completed_status($status)
    {
        $status = str_replace('wc-', '', (string) $status);

        return $status === 'completed';
    }

    /**
     * Statut à conserver si on refuse completed (jamais auto-draft ni completed).
     *
     * @param string $status
     * @return string
     */
    private function usable_fallback_status($status)
    {
        $status = str_replace('wc-', '', (string) $status);

        if (in_array($status, ['', 'completed', 'auto-draft', 'draft', 'checkout-draft', 'new'], true)) {
            return 'pending';
        }

        return $status;
    }

    /**
     * Statut actuellement en base (HPOS ou posts), sans le cache objet.
     *
     * @param WC_Order $order
     * @return string
     */
    private function get_persisted_status(WC_Order $order)
    {
        $id = $order->get_id();
        if (!$id) {
            return 'pending';
        }

        global $wpdb;

        if (
            class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            $status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d",
                    $id
                )
            );

            return is_string($status) ? $status : 'pending';
        }

        $post_status = get_post_status($id);

        return is_string($post_status) ? $post_status : 'pending';
    }
}
