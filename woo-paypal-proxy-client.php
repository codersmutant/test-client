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
 */
function wpppc_create_order_handler() {
    // Log all incoming data to debug
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
        
        // Skip regular validation for testing
        error_log('PayPal Proxy Client - Bypassing validation checks for testing');
        
        // Create a simple order manually without validation
        $order = wc_create_order();
        
        // Add customer data
        $address = array(
            'first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : '',
            'last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : '',
            'email'      => isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '',
            'phone'      => isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '',
            'address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '',
            'address_2'  => isset($_POST['billing_address_2']) ? sanitize_text_field($_POST['billing_address_2']) : '',
            'city'       => isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '',
            'state'      => isset($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : '',
            'postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '',
            'country'    => isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '',
        );
        
        $order->set_address($address, 'billing');
        
        // Set shipping if different
        if (isset($_POST['ship_to_different_address']) && $_POST['ship_to_different_address']) {
            $shipping = array(
                'first_name' => isset($_POST['shipping_first_name']) ? sanitize_text_field($_POST['shipping_first_name']) : '',
                'last_name'  => isset($_POST['shipping_last_name']) ? sanitize_text_field($_POST['shipping_last_name']) : '',
                'address_1'  => isset($_POST['shipping_address_1']) ? sanitize_text_field($_POST['shipping_address_1']) : '',
                'address_2'  => isset($_POST['shipping_address_2']) ? sanitize_text_field($_POST['shipping_address_2']) : '',
                'city'       => isset($_POST['shipping_city']) ? sanitize_text_field($_POST['shipping_city']) : '',
                'state'      => isset($_POST['shipping_state']) ? sanitize_text_field($_POST['shipping_state']) : '',
                'postcode'   => isset($_POST['shipping_postcode']) ? sanitize_text_field($_POST['shipping_postcode']) : '',
                'country'    => isset($_POST['shipping_country']) ? sanitize_text_field($_POST['shipping_country']) : '',
            );
            $order->set_address($shipping, 'shipping');
        } else {
            $order->set_address($address, 'shipping');
        }
        
        // Add cart items
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $order->add_product(
                $product,
                $cart_item['quantity'],
                array(
                    'variation' => $cart_item['variation'],
                    'totals'    => array(
                        'subtotal'     => $cart_item['line_subtotal'],
                        'subtotal_tax' => $cart_item['line_subtotal_tax'],
                        'total'        => $cart_item['line_total'],
                        'tax'          => $cart_item['line_tax'],
                    ),
                )
            );
        }
        
        // Add shipping
        if (WC()->cart->needs_shipping()) {
            $shipping_methods = WC()->session->get('chosen_shipping_methods');
            if (!empty($shipping_methods)) {
                $shipping_method = $shipping_methods[0];
                $order->add_shipping($shipping_method);
            }
        }
        
        // Calculate totals
        $order->calculate_totals();
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Set order status
        $order->update_status('pending', __('Awaiting PayPal payment', 'woo-paypal-proxy-client'));
        
        error_log('PayPal Proxy Client - Order created successfully: #' . $order->get_id());
        
        // Return success with order details
        wp_send_json_success(array(
            'order_id'   => $order->get_id(),
            'order_key'  => $order->get_order_key(),
            'proxy_data' => array('message' => 'Test data for debugging'),
        ));
        
    } catch (Exception $e) {
        error_log('PayPal Proxy Client - Error creating order: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Failed to create order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}

// Register the AJAX handler
add_action('wp_ajax_wpppc_create_order', 'wpppc_create_order_handler');
add_action('wp_ajax_nopriv_wpppc_create_order', 'wpppc_create_order_handler');


/**
 * Plugin deactivation hook
 */
function wpppc_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wpppc_deactivate');