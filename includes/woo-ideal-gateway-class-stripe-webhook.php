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
        $url = $this->api_url . "charges";

        if (isset($_GET['stripe_webhook'])) {

            if (empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
                $this->exitWithError(__('No Stripe Signature provided', 'woo-ideal-gateway'));
            }

            if (!$this->checkSignature($_SERVER['HTTP_STRIPE_SIGNATURE'], file_get_contents("php://input"))) {
                $this->exitWithError(__('Stripe Signature is invalid', 'woo-ideal-gateway'));
            }

            $input = json_decode(file_get_contents("php://input"), true);
            $data = $input['data']['object'];

            if (((int)$data['metadata']['woo-ideal-gateway'] != true) OR $data['type'] !== "ideal") {
                $this->exitWithError(__('Source is not using the iDEAL payment method!', 'woo-ideal-gateway'));
            }

            $order_id_meta = (int)$data['metadata']['order_id'];
            $order = new WC_Order($order_id_meta);

            if ($data['status'] !== "chargeable") {
                $order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 002'); // order note is optional, if you want to  add a note to order

                // Source is not chargeable
                $this->exitWithError(__('Source is not in a chargeable state', 'woo-ideal-gateway'));
            }

            $amount = $order->get_total();
            $amount = (int)(((string)($amount * 100)));

            $source = get_post_meta($order_id_meta, 'woo-ideal-gateway-stripe-source', true);
            $source_stripe = $data['id'];

            $payment_description = $this->get_option('payment-description');
            $payment_description = str_replace("{order_number}", $order_id_meta, $payment_description);

            if ($source !== $source_stripe) {
                $order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 001'); // order note is optional, if you want to  add a note to order
                $this->exitWithError(__('Stripe source and WooCommerce Order Source are not the same!', 'woo-ideal-gateway'));
            }

            $body = array(
                'amount' => $amount,
                'currency' => 'eur',
                'source' => $source_stripe,
                'description' => $payment_description
            );

            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Content-Type" => "application/x-www-form-urlencoded",
                "Authorization" => "Bearer " . $this->api_key
            );

            $response = $this->postRequest($url, $body, $headers);

            if ($response === false) {
                $error_message = $response->get_error_message();
                $this->exitWithError($error_message);
            }

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
            }

            $order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 003'); // order note is optional, if you want to  add a note to order
            exit(json_encode(array(
                'source_id' => $source_stripe,
                'message' => __('Source is not successfully charged', 'woo-ideal-gateway'),
                'error' => true
            )));
        }
    }

    function exitWithError($error_message)
    {
        exit(json_encode(array(
            'error' => true,
            'message' => $error_message
        )));
    }

    function checkWebhook()
    {
        $status = array();

        $test_webhook_id = $this->get_option("stripe-test-webhook-id");
        $live_webhook_id = $this->get_option("stripe-live-webhook-id");

        if (!$this->isBogusAPIKey($this->get_option('stripe-api-live'))) {
            $url = $this->api_url . "webhook_endpoints/" . $live_webhook_id;

            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Authorization" => "Bearer " . $this->get_option('stripe-api-live')
            );

            $response = $this->getRequest($url, $headers);
            $status["live"] = false;

            $json_response = json_decode($response["body"], true);
            if ($json_response["status"] == "enabled") $status["live"] = true;
        }

        if (!$this->isBogusAPIKey($this->get_option('stripe-api-test'))) {
            $url = $this->api_url . "webhook_endpoints/" . $test_webhook_id;

            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Authorization" => "Bearer " . $this->get_option('stripe-api-test')
            );

            $response = $this->getRequest($url, $headers);
            $status["test"] = false;

            $json_response = json_decode($response["body"], true);
            if ($json_response["status"] == "enabled") $status["test"] = true;
        }

        return $status;
    }

    // 0 = all
    // 1 = only live
    // 2 = only test
    function addWebhooks($mode)
    {
        $status = array();

        $url = $this->api_url . "webhook_endpoints";

        $body = array(
            "url" => esc_url(home_url('/?stripe_webhook')),
            "enabled_events[]" => "source.chargeable",
            "api_version" => $this->api_version
        );

        if (($mode == 0 OR $mode == 1) && !$this->isBogusAPIKey($this->get_option('stripe-api-live'))) {
            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Content-Type" => "application/x-www-form-urlencoded",
                "Authorization" => "Bearer " . $this->get_option('stripe-api-live')
            );

            $response = $this->postRequest($url, $body, $headers);
            $status["live"] = false;

            $json_response = json_decode($response["body"], true);
            if (is_int($json_response["created"])) {
                $this->update_option("stripe-live-webhook-id", $json_response["id"]);
                $this->update_option("stripe-live-webhook-secret", $json_response["secret"]);
                $status["live"] = true;
            }
        }

        if (($mode == 0 OR $mode == 2) && !$this->isBogusAPIKey($this->get_option('stripe-api-test'))) {
            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Content-Type" => "application/x-www-form-urlencoded",
                "Authorization" => "Bearer " . $this->get_option('stripe-api-test')
            );

            $response = $this->postRequest($url, $body, $headers);
            $status["test"] = false;

            $json_response = json_decode($response["body"], true);
            if (is_int($json_response["created"])) {
                $this->update_option("stripe-test-webhook-id", $json_response["id"]);
                $this->update_option("stripe-test-webhook-secret", $json_response["secret"]);
                $status["test"] = true;
            }
        }

        return $status;
    }

    function getAllWebhooks()
    {
        $webhooks = array();
        $url = $this->api_url . "webhook_endpoints?limit=100";

        if (!$this->isBogusAPIKey($this->get_option('stripe-api-live'))) {
            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Authorization" => "Bearer " . $this->get_option('stripe-api-live')
            );

            $response = $this->getRequest($url, $headers);
            if ($response !== false) {
                $json_response = json_decode($response["body"], true);
                if (is_array($json_response["data"])) $webhooks["live"] = $json_response["data"];
            }
        }

        if (!$this->isBogusAPIKey($this->get_option('stripe-api-test'))) {
            $headers = array(
                "User-Agent" => $this->user_agent,
                "Stripe-Version" => $this->api_version,
                "Authorization" => "Bearer " . $this->get_option('stripe-api-test')
            );

            $response = $this->getRequest($url, $headers);
            if ($response !== false) {
                $json_response = json_decode($response["body"], true);
                if (is_array($json_response["data"])) $webhooks["test"] = $json_response["data"];
            }
        }

        return $webhooks;
    }

    function disableWebhook($webhookId, $livemode)
    {
        $url = $this->api_url . "webhook_endpoints/" . $webhookId;

        $headers = array(
            "User-Agent" => $this->user_agent,
            "Stripe-Version" => $this->api_version,
            "Content-Type" => "application/x-www-form-urlencoded",
            "Authorization" => "Bearer " . $this->get_option('stripe-api-live')
        );

        if (!$livemode) $headers["Authorization"] = "Bearer " . $this->get_option('stripe-api-test');

        $body = array(
            "disabled" => "true"
        );

        $response = $this->postRequest($url, $body, $headers);
        if ($response === false) return false;

        $json_response = json_decode($response["body"], true);
        if ($json_response["id"] !== null) return true;

        return false;
    }

    function postRequest($url, $body, $headers)
    {
        $response = wp_remote_post($url, array(
                'method' => 'POST',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => $headers,
                'body' => $body
            )
        );

        if (is_wp_error($response)) return false;
        return ($response) ? $response : false;
    }

    function getRequest($url, $headers)
    {
        $response = wp_remote_get($url, array(
                'method' => 'GET',
                'timeout' => 45,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => $headers
            )
        );

        if (is_wp_error($response)) return false;
        return ($response) ? $response : false;
    }

    function isBogusAPIKey($key) {
        if ($key == "sk_live_XXXXXXXXXXXXXXXXXXXXXXXX" || $key == "sk_test_XXXXXXXXXXXXXXXXXXXXXXXX") return true;
        return false;
    }

    /**
     * checkSignature checks whether there is at least one valid webhook event
     *        signature that matches the body. There can be multiple for which
     *        we don't have the key due to key rollover
     *
     * @param string $SignatureHeader : "Stripe-Signature"-header as
     *        received in the HTTP request
     * @param string $RequestBody : Entire HTTP body
     *
     * @return boolean $isValid: whether the signature was valid and matches the body
     */
    function checkSignature($SignatureHeader, $RequestBody)
    {
        $isValid = false;
        $header = explode(",", $SignatureHeader);
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
    function isValidTimeStamp($RequestTimestamp)
    {
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
     * @param string $RequestTimestamp : Numeric string (no integer)
     *        representing a timestamp
     * @param string $RequestBody : Entire HTTP body
     *
     * @return boolean $expectedSignature: Signature expected for the given
     *        body at the given timestamp using the stored signing secret
     */
    function createExpectedSignature($RequestTimestamp, $RequestBody)
    {
        $signedPayload = $RequestTimestamp . "." . $RequestBody;
        $signingSecret = $this->get_option("stripe-webhook-secret");
        $expectedSignature = hash_hmac("sha256", $signedPayload, $signingSecret);
        return $expectedSignature;
    }


}
