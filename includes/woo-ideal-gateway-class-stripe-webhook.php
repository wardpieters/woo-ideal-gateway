<?php

defined('ABSPATH') or exit;

class StripeWebhook extends WC_iDEAL_Gateway
{

    /**
     * Receives and proccesses the webhook received by Stripe
     *
     * @since 2.0
     */
    function ReceiveWebhook()
    {
        global $woocommerce;
        $url = $this->api_url . "charges";

        if (isset($_GET['stripe_webhook'])) {

            if (empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
                exit(json_encode(array(
                    'error' => true,
                    'message' => __('No Stripe Signature provided', 'woo-ideal-gateway')
                )));
            }

            if (!$this->checkSignature($_SERVER['HTTP_STRIPE_SIGNATURE'], file_get_contents("php://input"))) {
                exit(json_encode(array(
                    'error' => true,
                    'message' => __('Stripe Signature is invalid', 'woo-ideal-gateway')
                )));
            }

            $input = json_decode(file_get_contents("php://input"), true);
            $data = $input['data']['object'];

            if ((int) $data['metadata']['woo-ideal-gateway'] != true) {
                exit(json_encode(array(
                    'error' => true,
                    'message' => __('1Source is not using the iDEAL payment method!', 'woo-ideal-gateway')
                )));
            }

            if ($data['type'] !== "ideal") {
                exit(json_encode(array(
                    'error' => true,
                    'message' => __('2Source is not using the iDEAL payment method!', 'woo-ideal-gateway')
                )));
            }

            $order_id_meta = (int) $data['metadata']['order_id'];
            $order = new WC_Order($order_id_meta);

            if ($data['status'] !== "chargeable") {
                $order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 002'); // order note is optional, if you want to  add a note to order

                // Source is not chargeable
                exit(json_encode(array(
                    'error' => true,
                    'message' => __('Source is not in a chargeable state', 'woo-ideal-gateway')
                )));
            }

            $amount = $order->get_total();
            $amount = (int)(((string)($amount * 100)));

            $source = get_post_meta($order_id_meta, 'woo-ideal-gateway-stripe-source', true);
            $source_stripe = $data['id'];

            $payment_description = $this->get_option('payment-description');
            $payment_description = str_replace("{order_number}", $order_id_meta, $payment_description);

            if ($source !== $source_stripe) {
                $order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 001'); // order note is optional, if you want to  add a note to order

                exit(json_encode(array(
                    'error' => true,
                    'message' => __('Stripe source and WooCommerce Order Source are not the same!', 'woo-ideal-gateway')
                )));
            }

            $stripe_data = array(
                'amount' => $amount,
                'currency' => 'eur',
                'source' => $source_stripe,
                'description' => $payment_description
            );

            $response = wp_remote_post($url, array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(
                        "User-Agent" => $this->user_agent,
                        "Stripe-Version" => $this->api_version,
                        "Content-Type" => "application/x-www-form-urlencoded",
                        "Authorization" => "Bearer " . $this->api_key
                    ),
                    'body' => $stripe_data,
                    'cookies' => array()
                )
            );

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();

                exit(json_encode(array(
                    'error' => true,
                    'message' => $error_message
                )));

            } else {
                $response = json_decode($response['body'], true);

                if ($response['paid'] == true) {
                    $charge_id = $response['id'];
                    $source_id = $response['source']['id'];
                    $iban_last_4 = $response['source']['ideal']['iban_last4'];
                    $ideal_bank = strtoupper($response['source']['ideal']['bank']);
                    if ($response['source']['owner']['verified_name'] != null) $verified_name = '<br>' . __('Name:', 'woo-ideal-gateway') . ' ' . $response['source']['owner']['verified_name'];
                    else $verified_name = '';

                    //Set order on payment complete
                    update_post_meta($order_id_meta, 'woo-ideal-gateway-stripe-charge-id', $charge_id);
                    $order->add_order_note(__('iDEAL Payment succeeded', 'woo-ideal-gateway') . '<br>' . __('IBAN: x', 'woo-ideal-gateway') . $iban_last_4 . ' (' . $ideal_bank . ')' . $verified_name . '<br>');
                    $order->payment_complete($source_id);

                    exit(json_encode(array(
                        'source_id' => $source_id,
                        'charge_id' => $charge_id,
                        'message' => __("Order status has been changed to processing", 'woo-ideal-gateway'),
                        'error' => false
                    )));
                } else {
                    $order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 003'); // order note is optional, if you want to  add a note to order

                    exit(json_encode(array(
                        'source_id' => $source_stripe,
                        'message' => __('Source is not successfully charged', 'woo-ideal-gateway'),
                        'error' => true
                    )));
                }
            }
        }
    }

    function checkSignature($StripeSignatureHeader, $RequestBody)
    {
        $valid = true;
        $header = explode(",", $StripeSignatureHeader);

        foreach ($header as $item) {
            $item = explode("=", $item);
            if ($item[0] == "t") {
                if (!$this->isValidTimeStamp($item[1])) $valid = false;
            } elseif ($item[0] == "v1") {
                if (!$this->createSignedPayload($RequestBody)) $valid = false;
            }
        }

        return $valid;
    }

    function isValidTimeStamp($timestamp)
    {
        return ((string)(int)$timestamp === $timestamp)
            && ($timestamp <= PHP_INT_MAX)
            && ($timestamp >= ~PHP_INT_MAX);
    }

    function createSignedPayload($body)
    {
        $current_timestamp = (string)time();
        $payload = $current_timestamp . "." . hash("SHA256", $body);
        return $payload;
    }

}