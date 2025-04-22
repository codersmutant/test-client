<?php
/**
 * Plugin Name: WooCommerce PayPal Proxy Client
 * Plugin URI: https://yourwebsite.com
 * Description: Connects to Website B for secure PayPal processing
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: woo-paypal-proxy-client
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 8.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPPPC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPPPC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPPPC_VERSION', '1.0.0');

/**
 * Check if WooCommerce is active
 */
function wpppc_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wpppc_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display WooCommerce missing notice
 */
function wpppc_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce PayPal Proxy Client requires WooCommerce to be installed and active.', 'woo-paypal-proxy-client'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function wpppc_init() {
    if (!wpppc_check_woocommerce_active()) {
        return;
    }
    
    // Load required files
    require_once WPPPC_PLUGIN_DIR . 'includes/class-woo-paypal-gateway.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-api-handler.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-admin.php';
    
    // Initialize classes
    $api_handler = new WPPPC_API_Handler();
    $admin = new WPPPC_Admin();
    
    // Add payment gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'wpppc_add_gateway');
    
    // Add scripts and styles
    add_action('wp_enqueue_scripts', 'wpppc_enqueue_scripts');
}
add_action('plugins_loaded', 'wpppc_init');

/**
 * Add PayPal Proxy Gateway to WooCommerce
 */
function wpppc_add_gateway($gateways) {
    $gateways[] = 'WPPPC_PayPal_Gateway';
    return $gateways;
}

/**
 * Enqueue scripts and styles
 */
function wpppc_enqueue_scripts() {
    if (is_checkout()) {
        wp_enqueue_style('wpppc-checkout-style', WPPPC_PLUGIN_URL . 'assets/css/checkout.css', array(), WPPPC_VERSION);
        wp_enqueue_script('wpppc-checkout-script', WPPPC_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), WPPPC_VERSION, true);
        
        // Add localized data for the script
        wp_localize_script('wpppc-checkout-script', 'wpppc_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-nonce'),
        ));
    }
}

/**
 * AJAX handler for validating checkout fields
 */
function wpppc_validate_checkout_fields() {
    check_ajax_referer('wpppc-nonce', 'nonce');
    
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
        wp_send_json_success(array('valid' => true));
    } else {
        wp_send_json_error(array('valid' => false, 'errors' => $errors));
    }
    
    wp_die();
}
add_action('wp_ajax_wpppc_validate_checkout', 'wpppc_validate_checkout_fields');
add_action('wp_ajax_nopriv_wpppc_validate_checkout', 'wpppc_validate_checkout_fields');

/**
 * Add settings link on plugin page
 */
function wpppc_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=paypal_proxy">' . __('Settings', 'woo-paypal-proxy-client') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wpppc_settings_link');

/**
 * Plugin activation hook
 */
function wpppc_activate() {
    // Create necessary database tables or options if needed
    add_option('wpppc_proxy_url', '');
    add_option('wpppc_api_key', '');
    add_option('wpppc_api_secret', md5(uniqid(rand(), true)));
}
register_activation_hook(__FILE__, 'wpppc_activate');


/**
 * AJAX handler for creating a WooCommerce order
 * Add detailed error logging and fix the order creation process
 */
function wpppc_create_order_handler() {
    // Log all incoming data for debugging
    error_log('PayPal Proxy Client - Incoming AJAX data: ' . print_r($_POST, true));
    
    try {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
            error_log('PayPal Proxy Client - Invalid nonce');
            wp_send_json_error(array(
                'message' => 'Security check failed'
            ));
            wp_die();
        }
        
        // Create a simple order for testing
        $order = wc_create_order();
        
        // Add customer data from POST
        $address = array(
            'first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : 'Test',
            'last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : 'Customer',
            'email'      => isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : 'test@example.com',
            'phone'      => isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '555-555-5555',
            'address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : 'Test Address',
            'city'       => isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : 'Test City',
            'state'      => isset($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : 'CA',
            'postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '12345',
            'country'    => isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : 'US',
        );
        
        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');
        
        // Add cart items (simplified for testing)
        if (WC()->cart->is_empty()) {
            // For testing, add a dummy product if cart is empty
            $product = new WC_Product_Simple();
            $product->set_name('Test Product');
            $product->set_price(10.00);
            $order->add_product($product, 1);
        } else {
            // Add real cart items
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $order->add_product(
                    $product,
                    $cart_item['quantity']
                );
            }
        }
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Calculate totals
        $order->calculate_totals();
        
        // Set order status
        $order->update_status('pending', __('Awaiting PayPal payment', 'woo-paypal-proxy-client'));
        
        error_log('PayPal Proxy Client - Order created successfully: #' . $order->get_id());
        
        // Return success with order details
        wp_send_json_success(array(
            'order_id'   => $order->get_id(),
            'order_key'  => $order->get_order_key(),
            'proxy_data' => array('message' => 'Order created successfully'),
        ));
        
    } catch (Exception $e) {
        error_log('PayPal Proxy Client - Error creating order: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Failed to create order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}

add_action('wp_ajax_wpppc_create_order', 'wpppc_create_order_handler');
add_action('wp_ajax_nopriv_wpppc_create_order', 'wpppc_create_order_handler');


/**
 * AJAX handler for completing orders after PayPal payment
 */
function wpppc_complete_order_handler() {
    // Log request for debugging
    error_log('PayPal Proxy Client - Complete Order AJAX request: ' . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
        error_log('PayPal Proxy Client - Invalid nonce in complete order request');
        wp_send_json_error(array(
            'message' => 'Security check failed'
        ));
        wp_die();
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
    
    if (!$order_id || !$paypal_order_id) {
        error_log('PayPal Proxy Client - Invalid order data in completion request');
        wp_send_json_error(array(
            'message' => 'Invalid order data'
        ));
        wp_die();
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        error_log('PayPal Proxy Client - Order not found: ' . $order_id);
        wp_send_json_error(array(
            'message' => 'Order not found'
        ));
        wp_die();
    }
    
    try {
        // Complete the order
        $order->payment_complete($transaction_id);
        
        // Add order note
        $order->add_order_note(
            sprintf('PayPal payment completed. PayPal Order ID: %s, Transaction ID: %s',
                $paypal_order_id,
                $transaction_id
            )
        );
        
        // Update status to processing
        $order->update_status('processing');
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Log success
        error_log('PayPal Proxy Client - Order successfully completed: ' . $order_id);
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
    } catch (Exception $e) {
        error_log('PayPal Proxy Client - Exception during order completion: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error completing order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}

// Register the AJAX handlers
add_action('wp_ajax_wpppc_complete_order', 'wpppc_complete_order_handler');
add_action('wp_ajax_nopriv_wpppc_complete_order', 'wpppc_complete_order_handler');

/**
 * Plugin deactivation hook
 */
function wpppc_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wpppc_deactivate');