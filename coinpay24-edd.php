
<?php
/*
Plugin Name: CoinPay24 Payment Gateway for Easy Digital Downloads
Description: A custom payment gateway for Easy Digital Downloads to integrate with CoinPay24.
Version: 1.0
Author: CoinPay24
*/

if (!defined('ABSPATH')) {
    exit;
}

function coinpay24_edd_register_gateway($gateways) {
    $gateways['coinpay24'] = array(
        'admin_label' => 'CoinPay24',
        'checkout_label' => __('Pay with CoinPay24', 'coinpay24'),
    );
    return $gateways;
}
add_filter('edd_payment_gateways', 'coinpay24_edd_register_gateway');

function coinpay24_edd_gateway_settings($settings) {
    $coinpay24_settings = array(
        array(
            'id' => 'coinpay24_settings',
            'name' => '<strong>' . __('CoinPay24 Settings', 'coinpay24') . '</strong>',
            'desc' => __('Configure the gateway settings for CoinPay24.', 'coinpay24'),
            'type' => 'header',
        ),
        array(
            'id' => 'coinpay24_api_key',
            'name' => __('API Key', 'coinpay24'),
            'desc' => __('Enter your CoinPay24 API Key.', 'coinpay24'),
            'type' => 'text',
            'size' => 'regular',
        ),
    );
    return array_merge($settings, $coinpay24_settings);
}
add_filter('edd_settings_gateways', 'coinpay24_edd_gateway_settings');

function coinpay24_edd_process_payment($purchase_data) {
    $api_key = edd_get_option('coinpay24_api_key', '');
    $callback_url = add_query_arg('edd-listener', 'coinpay24', home_url('/'));
    $payment_id = edd_insert_payment(array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => edd_get_currency(),
        'downloads' => $purchase_data['downloads'],
        'cart_details' => $purchase_data['cart_details'],
        'status' => 'pending',
    ));

    $data = array(
        'api_key' => $api_key,
        'order_id' => $payment_id,
        'price_amount' => $purchase_data['price'],
        'price_currency' => edd_get_currency(),
        'title' => 'Order #' . $payment_id,
        'callback_url' => $callback_url,
        'cancel_url' => edd_get_failed_transaction_uri(),
        'success_url' => add_query_arg('payment-confirmation', 'coinpay24', edd_get_return_uri($payment_id)),
    );

    $response = wp_remote_post('https://api.coinpay24.com/v1/invoices/create', array(
        'method' => 'POST',
        'body' => json_encode($data),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ),
    ));

    if (is_wp_error($response)) {
        edd_set_error('coinpay24_error', __('Error connecting to CoinPay24.', 'coinpay24'));
        edd_send_back_to_checkout();
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($body['payment_url'])) {
        edd_update_payment_meta($payment_id, '_coinpay24_payment_url', $body['payment_url']);
        wp_redirect($body['payment_url']);
        exit;
    } else {
        edd_set_error('coinpay24_error', __('Error creating CoinPay24 invoice.', 'coinpay24'));
        edd_send_back_to_checkout();
    }
}
add_action('edd_gateway_coinpay24', 'coinpay24_edd_process_payment');

function coinpay24_edd_listener() {
    if (!isset($_GET['edd-listener']) || $_GET['edd-listener'] !== 'coinpay24') {
        return;
    }

    $postData = $_POST;

    if (!isset($postData['verify_hash'])) {
        die('Invalid callback');
    }

    $api_key = edd_get_option('coinpay24_api_key', '');
    $verify_hash = $postData['verify_hash'];
    unset($postData['verify_hash']);
    ksort($postData);

    $generated_hash = hash_hmac('sha256', http_build_query($postData), $api_key);

    if ($verify_hash !== $generated_hash) {
        die('Invalid hash');
    }

    if ($postData['status'] === 'completed') {
        edd_update_payment_status($postData['order_id'], 'publish');
        die('Payment completed');
    } else {
        die('Payment failed');
    }
}
add_action('init', 'coinpay24_edd_listener');
?>
