<?php
/**
 * PayPal Proxy Gateway for WooCommerce
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy Gateway Class
 */
class WPPPC_PayPal_Gateway extends WC_Payment_Gateway {
    
    /**
     * API Handler instance
     */
    private $api_handler;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'paypal_proxy';
        $this->icon               = apply_filters('woocommerce_paypal_proxy_icon', WPPPC_PLUGIN_URL . 'assets/images/paypal.png');
        $this->has_fields         = true;
        $this->method_title       = __('PayPal via Proxy', 'woo-paypal-proxy-client');
        $this->method_description = __('Accept PayPal payments securely through Website B proxy.', 'woo-paypal-proxy-client');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->proxy_url    = $this->get_option('proxy_url');
        $this->api_key      = $this->get_option('api_key');
        
        // Initialize API handler
        $this->api_handler = new WPPPC_API_Handler(
            $this->proxy_url,
            $this->api_key,
            get_option('wpppc_api_secret')
        );
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wpppc_callback', array($this, 'process_callback'));
        
        // AJAX handlers for order processing
        add_action('wp_ajax_wpppc_create_order', array($this, 'ajax_create_order'));
        add_action('wp_ajax_nopriv_wpppc_create_order', array($this, 'ajax_create_order'));
        add_action('wp_ajax_wpppc_complete_order', array($this, 'ajax_complete_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_order', array($this, 'ajax_complete_order'));
    }
    
    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'woo-paypal-proxy-client'),
                'type'        => 'checkbox',
                'label'       => __('Enable PayPal via Proxy', 'woo-paypal-proxy-client'),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('PayPal', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-paypal-proxy-client'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('Pay securely via PayPal.', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'proxy_url' => array(
                'title'       => __('Proxy Website URL', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Enter the URL of Website B (PayPal proxy).', 'woo-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Enter the API key provided by Website B.', 'woo-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }
    
    /**
     * Payment fields displayed on checkout
     */
    public function payment_fields() {
        // Display description if set
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Load PayPal buttons iframe
        $iframe_url = $this->generate_iframe_url();
        include WPPPC_PLUGIN_DIR . 'templates/iframe-container.php';
    }
    
    /**
     * Generate iframe URL with necessary parameters
     */
    private function generate_iframe_url() {
        // Get cart total and currency
        $total = WC()->cart->get_total('');
        $currency = get_woocommerce_currency();
        
        // Get callback URL
        $callback_url = WC()->api_request_url('wpppc_callback');
        
        // Generate a hash for security
        $timestamp = time();
        $hash_data = $timestamp . $total . $currency . $this->api_key;
        $hash = hash_hmac('sha256', $hash_data, get_option('wpppc_api_secret'));
        
        // Build the iframe URL
        $params = array(
            'rest_route'    => '/wppps/v1/paypal-buttons',
            'amount'        => $total,
            'currency'      => $currency,
            'api_key'       => $this->api_key,
            'timestamp'     => $timestamp,
            'hash'          => $hash,
            'callback_url'  => base64_encode($callback_url),
            'site_url'      => base64_encode(get_site_url()),
        );
        
        $iframe_url = $this->proxy_url . '?' . http_build_query($params);
        
        error_log('PayPal Proxy Client Debug - Using API key: ' . $this->api_key);
        error_log('PayPal Proxy Client Debug - Setting from DB: ' . get_option('wpppc_api_key'));
        $this->api_key = get_option('wpppc_api_key');

        
        return $iframe_url;
    }
    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        // This method will be called when the order is created
        // The actual payment processing happens via AJAX
        
        $order = wc_get_order($order_id);
        
        // Return success response
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    /**
     * AJAX handler for creating a WooCommerce order
     */
    
/**
 * Add enhanced logging to the PayPal proxy gateway
 */

// Add this method to the WPPPC_PayPal_Gateway class
public function ajax_create_order() {
    // Log the raw POST data for debugging
    error_log('PayPal Proxy - Create Order Raw POST data: ' . print_r($_POST, true));
    
    try {
        check_ajax_referer('wpppc-nonce', 'nonce');
        
        // Log the checkout fields
        $checkout_fields = WC()->checkout()->get_checkout_fields();
        error_log('PayPal Proxy - Checkout fields structure: ' . print_r($checkout_fields, true));
        
        // Create order with error handling
        try {
            // For testing, create a simple order directly
            $order = wc_create_order();
            
            // Add billing information
            $billing_address = array(
                'first_name' => !empty($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : 'Test',
                'last_name'  => !empty($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : 'User',
                'email'      => !empty($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : 'test@example.com',
            );
            
            $order->set_address($billing_address, 'billing');
            $order->set_address($billing_address, 'shipping');
            
            // Add a product if cart is empty (for testing)
            if (WC()->cart->is_empty()) {
                // Get any product
                $products = wc_get_products(array('limit' => 1));
                if (!empty($products)) {
                    $product = $products[0];
                    $order->add_product($product, 1);
                }
            } else {
                // Add real cart items
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    $order->add_product($product, $cart_item['quantity']);
                }
            }
            
            // Calculate totals
            $order->calculate_totals();
            
            // Set payment method
            $order->set_payment_method('paypal_proxy');
            
            // Set status
            $order->update_status('pending', __('Awaiting PayPal payment', 'woo-paypal-proxy-client'));
            
            $order_id = $order->get_id();
            error_log('PayPal Proxy - Successfully created order #' . $order_id);
            
            // Return success
            wp_send_json_success(array(
                'order_id'   => $order_id,
                'order_key'  => $order->get_order_key(),
                'proxy_data' => array('message' => 'Order created successfully'),
            ));
        } catch (Exception $e) {
            error_log('PayPal Proxy - Exception during order creation: ' . $e->getMessage());
            error_log('PayPal Proxy - Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'Order creation error: ' . $e->getMessage()
            ));
        }
    } catch (Exception $e) {
        error_log('PayPal Proxy - Exception in AJAX handler: ' . $e->getMessage());
        error_log('PayPal Proxy - Exception trace: ' . $e->getTraceAsString());
        wp_send_json_error(array(
            'message' => 'AJAX handler error: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}
    
    /**
     * AJAX handler for completing an order after payment
     */
    public function ajax_complete_order() {
        check_ajax_referer('wpppc-nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
        $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
        
        if (!$order_id || !$paypal_order_id) {
            wp_send_json_error(array(
                'message' => __('Invalid order data', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(array(
                'message' => __('Order not found', 'woo-paypal-proxy-client')
            ));
            wp_die();
        }
        
        // Verify payment with Website B
        $verification = $this->api_handler->verify_payment($paypal_order_id, $order);
        
        if (is_wp_error($verification)) {
            wp_send_json_error(array(
                'message' => $verification->get_error_message()
            ));
            wp_die();
        }
        
        // Complete the order
        $order->payment_complete($transaction_id);
        $order->add_order_note(
            sprintf(__('PayPal payment completed via proxy. PayPal Order ID: %s, Transaction ID: %s', 'woo-paypal-proxy-client'),
                $paypal_order_id,
                $transaction_id
            )
        );
        
        // Empty cart
        WC()->cart->empty_cart();
        
        wp_send_json_success(array(
            'redirect' => $this->get_return_url($order)
        ));
        
        wp_die();
    }
    
    /**
     * Process callback from Website B
     */
    public function process_callback() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
        
        // Verify hash
        $check_hash = hash_hmac('sha256', $order_id . $status . $this->api_key, get_option('wpppc_api_secret'));
        
        if ($hash !== $check_hash) {
            wp_die(__('Invalid security hash', 'woo-paypal-proxy-client'), '', array('response' => 403));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die(__('Order not found', 'woo-paypal-proxy-client'), '', array('response' => 404));
        }
        
        if ($status === 'completed') {
            // Payment was successful
            $order->payment_complete();
            $order->add_order_note(__('Payment completed via PayPal proxy callback', 'woo-paypal-proxy-client'));
            
            // Redirect to thank you page
            wp_redirect($this->get_return_url($order));
            exit;
        } elseif ($status === 'cancelled') {
            // Payment was cancelled
            $order->update_status('cancelled', __('Payment cancelled by customer', 'woo-paypal-proxy-client'));
            
            // Redirect to cart page
            wp_redirect(wc_get_cart_url());
            exit;
        } else {
            // Payment failed
            $order->update_status('failed', __('Payment failed', 'woo-paypal-proxy-client'));
            
            // Redirect to checkout page
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }
    
    /**
     * Validate checkout fields
     */
    private function validate_checkout_fields() {
        $errors = array();
        
        // Get checkout fields
        $fields = WC()->checkout()->get_checkout_fields();
        
        // Loop through required fields and check if they're empty
        foreach ($fields as $fieldset_key => $fieldset) {
            foreach ($fieldset as $key => $field) {
                if (!empty($field['required']) && empty($_POST[$key])) {
                    $errors[$key] = sprintf(__('%s is a required field.', 'woocommerce'), $field['label']);
                }
            }
        }
        
        if (empty($errors)) {
            return array('valid' => true);
        } else {
            return array('valid' => false, 'errors' => $errors);
        }
    }
}