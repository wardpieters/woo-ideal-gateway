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

    function checkWebhook() {
        $url = $this->api_url . "webhook_endpoints/" . $this->get_option("stripe-webhook-id");

        $response = wp_remote_get($url, array(
                'method' => 'GET',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(
                    "User-Agent" => $this->user_agent,
                    "Stripe-Version" => $this->api_version,
                    "Authorization" => "Bearer " . $this->api_key
                )
            )
        );

        if (is_wp_error($response)) return false;

        else {
            $json_response = json_decode($response["body"], true);

            if ($json_response["status"] == "enabled") return true;
            else return false;
        }
    }

    function addWebhook() {
        $url = $this->api_url . "webhook_endpoints";

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
                'body' => array(
                    "url" => esc_url(home_url('/?stripe_webhook')),
                    "enabled_events[]" => "source.chargeable",
                    "api_version" => $this->api_version
                )
            )
        );

        if (is_wp_error($response)) return false;
        else {
            $json_response = json_decode($response["body"], true);
            if (is_int($json_response["created"])) {
                $this->update_option("stripe-webhook-id", $json_response["id"]);
                $this->update_option("stripe-webhook-secret", $json_response["secret"]);

                return true;
            } else return false;
        }
    }

    function getAllWebhooks() {
        $url = $this->api_url . "webhook_endpoints/list?limit=100";

        $response = wp_remote_get($url, array(
                'method' => 'GET',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(
                    "User-Agent" => $this->user_agent,
                    "Stripe-Version" => $this->api_version,
                    "Authorization" => "Bearer " . $this->api_key
                )
            )
        );

        if (is_wp_error($response)) return false;

        else {
            $json_response = json_decode($response["body"], true);

            if (is_array($json_response["data"])) return $json_response["data"];
            else return false;
        }
    }

    function disableWehook($id) {
        $url = $this->api_url . "webhook_endpoints/" . $id;

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
                'body' => array(
                    "webhook_endpoint" => $id,
                    "disabled" => "true"
                )
            )
        );

        if (is_wp_error($response)) return false;
        else {
            $json_response = json_decode($response["body"], true);
            if (!is_null($json_response["id"])) return true;
            else return false;
        }
    }

    /**
      * checkSignature checks whether there is at least one valid webhook event
      *        signature that matches the body. There can be multiple for which
      *        we don't have the key due to key rollover
      *
      * @param string $StripeSignatureHeader: "Stripe-Signature"-header as
      *        received in the HTTP request
      * @param string $RequestBody: Entire HTTP body
      *
      * @return boolean $isValid: whether the signature was valid and matches the body
      */
    function checkSignature($StripeSignatureHeader, $RequestBody) {
        $isValid = false;
        $header = explode(",", $StripeSignatureHeader);
        $RequestTimestamp = null;

        foreach ($header as $item) {
            $item = explode("=", $item);
            if ($item[0] == "t") {
                //there can only be one t, so if it's not valid, we know there
                //is no valid timestamp
                if (!$this->isValidTimeStamp($item[1])) return false;
                else $RequestTimestamp = intval($item[1]);
            }
        }
        if ($RequestTimestamp === null) return false; //no valid timestamp

        //need for separate loops as the order is not specified and we need to
        //check for all v1 headers
        foreach ($header as $item) {
            $item = explode("=", $item);
            if ($item[0] == "v1") {
                $expectedSignature = $this->createExpectedSignature($RequestTimestamp, $RequestBody);
                if ($expectedSignature == $item[1]) $isValid = true; //at least one valid v1 header is enough
            }
        }

        return $isValid;
    }

    /**
     * isValidTimeStamp checks whether the inputted timestamp is valid and
     *        recent (no more than 10 minutes old)
     *
     * @param string $RequestTimestamp : Numeric string (no integer) representing a timestamp
     * @return bool
     */
    function isValidTimeStamp($RequestTimestamp) {
        if (!is_numeric($RequestTimestamp)) return false;
        elseif ($RequestTimestamp > time() + 60) return false;   //timestamp should be no more than 60 seconds from now
        elseif ($RequestTimestamp < time() - 600) return false;  //timestamp should be no more than 10 minutes ago
        else return true;
    }

    /**
      * createExpectedSignature creates the expected signature for a given body
      *        at the given timestamp using the stored signing secret
      * Possible that there are multiple signing secrets active so need to
      *        check all signatures sent since we only store one key
      *
      * @param string $RequestTimestamp: Numeric string (no integer)
      *        representing a timestamp
      * @param string $RequestBody: Entire HTTP body
      *
      * @return boolean $expectedSignature: Signature expected for the given
      *        body at the given timestamp using the stored signing secret
      */
    function createExpectedSignature($RequestTimestamp, $RequestBody) {
        $signedPayload = $RequestTimestamp . "." . $RequestBody;
        $signingSecret = $this->get_option("stripe-webhook-secret");
        $expectedSignature = hash_hmac("sha256", $signedPayload, $signingSecret);
        return $expectedSignature;
    }


}
