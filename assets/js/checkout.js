/**
 * WooCommerce PayPal Proxy Client Checkout JS
 */

(function($) {
    'use strict';
    
    // PayPal Button Status
    var paypalButtonLoaded = false;
    var creatingOrder = false;
    var orderCreated = false;
    var orderID = null;
    
    // Store error messages
    var errorMessages = {};
    
    // PayPal data received from iframe
    var paypalData = {};
    
    /**
     * Initialize the checkout handlers
     */
    function init() {
        // Listen for messages from iframe
        setupMessageListener();
        
        // Handle checkout form submission
        handleCheckoutSubmission();
        
        // Handle iframe loading event
        $('#paypal-proxy-iframe').on('load', function() {
            paypalButtonLoaded = true;
        });
        
        // Listen for validation errors
        $(document.body).on('checkout_error', function() {
            // Reset order creation flag if validation fails
            creatingOrder = false;
            orderCreated = false;
        });
    }
    
    /**
     * Setup message listener for communication with iframe
     */
    function setupMessageListener() {
        window.addEventListener('message', function(event) {
            // Validate origin
            const iframeUrl = new URL($('#paypal-proxy-iframe').attr('src'));
            if (event.origin !== iframeUrl.origin) {
                return;
            }
            
            const data = event.data;
            
            // Check if message is for us
            if (!data || !data.action || data.source !== 'paypal-proxy') {
                return;
            }
            
            // Handle different actions
            switch (data.action) {
                case 'button_loaded':
                    paypalButtonLoaded = true;
                    break;
                    
                case 'button_clicked':
                    handlePayPalButtonClick();
                    break;
                    
                case 'order_approved':
                    handleOrderApproved(data.payload);
                    break;
                    
                case 'payment_cancelled':
                    handlePaymentCancelled();
                    break;
                    
                case 'payment_error':
                    handlePaymentError(data.error);
                    break;
            }
        });
    }
    
    /**
     * Handle PayPal button click
     */
    function handlePayPalButtonClick() {
    if (creatingOrder || orderCreated) {
        console.log('Already processing an order, ignoring click');
        return;
    }
    
    creatingOrder = true;
    console.log('PayPal button clicked, starting order creation process');
    
    // Clear previous errors
    clearErrors();
    
    // Validate checkout fields first
    validateCheckoutFields().then(function(validationResult) {
        console.log('Checkout validation result:', validationResult);
        if (!validationResult.valid) {
            displayErrors(validationResult.errors);
            creatingOrder = false;
            return;
        }
        
        // Create WooCommerce order
        console.log('Creating WooCommerce order...');
        createOrder().then(function(orderData) {
            // Order created successfully
            console.log('Order created successfully:', orderData);
            orderID = orderData.order_id;
            orderCreated = true;
            creatingOrder = false;
            
            // Send message to iframe with order info
            console.log('Sending order data to PayPal iframe');
            sendMessageToIframe({
                action: 'create_paypal_order',
                order_id: orderID,
                order_key: orderData.order_key,
                proxy_data: orderData.proxy_data
            });
        }).catch(function(error) {
            console.error('Order creation failed:', error);
            creatingOrder = false;
            
            if (error.errors) {
                displayErrors(error.errors);
            } else {
                displayError('general', error.message || 'Failed to create order. Please try again.');
            }
            
            // Send message to iframe about the failure
            sendMessageToIframe({
                action: 'order_creation_failed',
                message: error.message || 'Failed to create order'
            });
        });
    }).catch(function(error) {
        console.error('Validation failed:', error);
        creatingOrder = false;
    });
}

    
    /**
     * Validate checkout fields via AJAX
     */
    function validateCheckoutFields() {
        return new Promise(function(resolve, reject) {
            // Get form data
            const formData = $('form.checkout').serialize();
            
            // Send AJAX request
            $.ajax({
                type: 'POST',
                url: wpppc_params.ajax_url,
                data: {
                    action: 'wpppc_validate_checkout',
                    nonce: wpppc_params.nonce,
                    ...parseFormData(formData)
                },
                success: function(response) {
                    if (response.success) {
                        resolve({ valid: true });
                    } else {
                        resolve({ valid: false, errors: response.data.errors });
                    }
                },
                error: function(xhr, status, error) {
                    reject({ message: 'Validation request failed', xhr: xhr });
                }
            });
        });
    }
    
    /**
     * Create WooCommerce order via AJAX
     */
    function createOrder() {
    console.log("Creating order with the following data:");
    console.log("Form data:", $('form.checkout').serialize());
    
    return new Promise(function(resolve, reject) {
        // Get form data
        const formData = $('form.checkout').serialize();
        
        // Send AJAX request
        $.ajax({
            type: 'POST',
            url: wpppc_params.ajax_url,
            data: {
                action: 'wpppc_ajax_create_order',
                nonce: wpppc_params.nonce,
                ...parseFormData(formData)
            },
            success: function(response) {
                console.log("Order creation response:", response);
                if (response.success) {
                    resolve(response.data);
                } else {
                    reject({ message: response.data.message, errors: response.data.errors });
                }
            },
            error: function(xhr, status, error) {
                console.error("Order creation error details:", xhr.responseText);
                reject({ message: 'Order creation request failed: ' + error, xhr: xhr });
            }
        });
    });
}
    
    /**
     * Complete payment after PayPal approval
     */
    function completePayment(paymentData) {
        return new Promise(function(resolve, reject) {
            $.ajax({
                type: 'POST',
                url: wpppc_params.ajax_url,
                data: {
                    action: 'wpppc_complete_order',
                    nonce: wpppc_params.nonce,
                    order_id: orderID,
                    paypal_order_id: paymentData.orderID,
                    transaction_id: paymentData.transactionID || ''
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response.data);
                    } else {
                        reject({ message: response.data.message });
                    }
                },
                error: function(xhr, status, error) {
                    reject({ message: 'Payment completion request failed', xhr: xhr });
                }
            });
        });
    }
    
    /**
     * Handle order approved by PayPal
     */
    function handleOrderApproved(payload) {
        if (!orderCreated || !orderID) {
            console.error('No order created or invalid order ID');
            return;
        }
        
        // Store PayPal data
        paypalData = payload;
        
        // Complete the payment
        completePayment(payload).then(function(data) {
            // Redirect to thank you page
            window.location.href = data.redirect;
        }).catch(function(error) {
            console.error('Payment completion failed:', error);
            displayError('general', error.message || 'Failed to complete payment. Please try again.');
            
            // Reset order flags
            orderCreated = false;
            orderID = null;
        });
    }
    
    /**
     * Handle payment cancelled
     */
    function handlePaymentCancelled() {
        console.log('Payment cancelled by user');
        
        // Reset order flags
        orderCreated = false;
        orderID = null;
    }
    
    /**
     * Handle payment error
     */
    function handlePaymentError(error) {
        console.error('Payment error:', error);
        displayError('general', 'PayPal error: ' + (error.message || 'Unknown error'));
        
        // Reset order flags
        orderCreated = false;
        orderID = null;
    }
    
    /**
     * Send message to iframe
     */
    function sendMessageToIframe(message) {
    const iframe = document.getElementById('paypal-proxy-iframe');
    if (!iframe || !iframe.contentWindow) {
        console.error('Cannot find PayPal iframe');
        return;
    }
    
    // Add source identifier
    message.source = 'woocommerce-site';
    
    console.log('Sending message to iframe:', message);
    
    // Get iframe origin - use wildcard for testing
    // const iframeUrl = new URL(iframe.src);
    // const targetOrigin = iframeUrl.origin;
    const targetOrigin = '*'; // Use wildcard for testing
    
    // Send message
    iframe.contentWindow.postMessage(message, targetOrigin);
}
    
    /**
     * Handle checkout form submission
     */
    function handleCheckoutSubmission() {
        $('form.checkout').on('checkout_place_order_paypal_proxy', function() {
            // If we've already created an order via PayPal, let the form submit normally
            if (orderCreated && orderID) {
                return true;
            }
            
            // Otherwise, prevent form submission and trigger PayPal button click
            if (paypalButtonLoaded) {
                sendMessageToIframe({
                    action: 'trigger_paypal_button'
                });
            }
            
            return false;
        });
    }
    
    /**
     * Display checkout validation errors
     */
    function displayErrors(errors) {
        // Clear previous errors
        clearErrors();
        
        // Store new errors
        errorMessages = errors;
        
        // Add error messages to the page
        $.each(errors, function(field, message) {
            const $field = $('#' + field);
            const $parent = $field.closest('.form-row');
            $parent.addClass('woocommerce-invalid');
            $parent.append('<span class="woocommerce-error">' + message + '</span>');
        });
        
        // Scroll to the first error
        const $firstErrorField = $('.woocommerce-invalid:first');
        if ($firstErrorField.length) {
            $('html, body').animate({
                scrollTop: $firstErrorField.offset().top - 100
            }, 500);
        }
    }
    
    /**
     * Display a single error message
     */
    function displayError(field, message) {
        const errors = {};
        errors[field] = message;
        displayErrors(errors);
    }
    
    /**
     * Clear all error messages
     */
    function clearErrors() {
        $('.woocommerce-error').remove();
        $('.woocommerce-invalid').removeClass('woocommerce-invalid');
        errorMessages = {};
    }
    
    /**
     * Parse form data string into object
     */
    function parseFormData(formData) {
        const data = {};
        const pairs = formData.split('&');
        
        for (let i = 0; i < pairs.length; i++) {
            const pair = pairs[i].split('=');
            const key = decodeURIComponent(pair[0]);
            const value = decodeURIComponent(pair[1] || '');
            
            // Handle array-like names (e.g., shipping_method[0])
            if (key.match(/\[\d*\]$/)) {
                const base = key.replace(/\[\d*\]$/, '');
                if (!data[base]) data[base] = [];
                data[base].push(value);
            } else {
                data[key] = value;
            }
        }
        
        return data;
    }
    
    // Initialize on document ready
    $(document).ready(init);
    
})(jQuery);