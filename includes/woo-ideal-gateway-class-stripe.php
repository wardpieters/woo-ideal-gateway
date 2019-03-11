<?php

defined('ABSPATH') or exit;

class StripeAPI extends WC_iDEAL_Gateway {
	
	/**
	* Creates a source at Stripe and returns array
	*
	* @since 2.0
	*/
	function CreateSource($order_data , $order_id) {
		$api_key = $this->api_key;
		$url = $this->api_url . "sources";
		
		// Order information
		$order = new WC_Order($order_id);
		$amount = $order->get_total();
		$amount = (int) (((string) ($amount*100)));
		
		if(empty($ideal_bank = sanitize_text_field($_POST['iDEAL_BANK']))) {
			$data = array(
				'success' => 'no',
				'error_type' => 'no_bank_chosen',
				'error_message' => __('Creating Stripe source failed because no bank was chosen', 'woo-ideal-gateway')
			);
			
			return $data;
		}
		
		$return_url = esc_url(parent::get_return_url($order));
		$return_url = explode("#", $return_url);
		$return_url = $return_url[0];
		$return_url = trim($return_url, '&');
		
		$payment_description = parent::get_option('payment-description');
		$statement_descriptor = str_replace("{order_number}", $order_id, $payment_description);
		
		// Customer information
		$name = $order_data['billing']['first_name'] . ' ' . $order_data['billing']['last_name'];
		$phone = $order_data['billing']['phone'];
		$mail = is_email(sanitize_email($order_data['billing']['email']));
		
		// Address information
		$city = $order_data['billing']['city'];
		$country = $order_data['billing']['country'];
		$line1 = $order_data['billing']['address_1'];
		$line2 = $order_data['billing']['address_2'];
		$postal_code = $order_data['billing']['postcode'];
		$state = $order_data['billing']['state'];
		
		$address = array(
			'city' => "$city",
			'country' => "$country",
			'line1' => "$line1",
			'line2' => "$line2",
			'postal_code' => "$postal_code",
			'state' => "$state"
		);
		
		$stripe_data = array(
			'type' => 'ideal',
			'amount' => $amount,
			'currency' => 'eur',
			
			'owner' => array(
				'email' => $mail,
				'address' => $address
			),
			
			'redirect' => array(
				'return_url' => $return_url
			),
			
			'statement_descriptor' => $statement_descriptor,
			
			'metadata' => array(
				'order_id' => $order_id,
				'woo-ideal-gateway' => true
			)
			
		);
		
		if($ideal_bank != "REDIRECT") $stripe_data['ideal']['bank'] = $ideal_bank;
		
		$response = wp_remote_post($url, array(
			'method' => 'POST',
			'timeout' => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(
				"Content-Type" => "application/x-www-form-urlencoded",
				"Authorization" => "Bearer " . $api_key
			),
			'body' => $stripe_data,
			'cookies' => array()
			)
		);
		
		
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();

			$data = array(
				'success' => 'no',
				'error_type' => 'response_http_error',
				'error_message' => $error_message
			);
			
			return $data;
			
		} else {
			$response = json_decode($response['body'],true);
			if($response['created'] != '') {
				// Source has creation date, which means it is created
				
				$source_id = $response['id'];
				$stripe_redirect_url = $response['redirect']['url'];
						
				$data = array(
					'success' => 'yes',
					'source_id' => $source_id,
					'redirect_url' => $stripe_redirect_url
				);
			
				return $data;
			}
			else {
				// Return Stripe Error
				
				$error_type = $response['error']['type'];
				$error_message = $response['error']['message'];
				
				$data = array(
					'success' => 'no',
					'error_type' => $error_type,
					'error_message' => $error_message
				);
				return $data;
			}
			
		}
	}
	
	/**
	* Retrieve Source status from Stripe
	*
	* @since 2.0
	*/
	function GetSourceStatus($source) {
		$api_key = $this->api_key;
		$url = $this->api_url . "sources/" . $source;
		
		$response = wp_remote_get($url, array(
			'headers' => array(
				"Authorization" => "Bearer " . $api_key
			)));
		if(is_array($response)) {
			if ($response['response']['code'] != 200) return false;
			$status = json_decode($response['body'], true)['status'];
			return $status;
		}
		else {
			return false;
		}
		
	}

	/**
	* Refunds the payment at Stripe and returns boolean
	*
	* @since 2.0
	*/
	function RefundPayment($order_id, $amount, $reason) {
		$api_key = $this->api_key;
		$url = $this->api_url . "refunds";

		$order = new WC_Order($order_id);
		
		if($amount == null) {
			// Amount is not set, so get total amount of the order
			$amount = $order->get_total();
		}
		
		$amount = (int) (((string) ($amount*100)));
		
		if($reason == null) {
			$reason = __('Refund for order', 'woo-ideal-gateway') . ' #' . $order_id;
		}
		else $reason = str_replace('{order_number}', $order_id, $reason);
		
		if(empty(get_post_meta($order_id, 'woo-ideal-gateway-stripe-charge-id', true))) {
			$data = array(
				'success' => 'no',
				'error_type' => 'charge_id_not_found',
				'error_message' => __('Could not refund because Charge ID required to refund the payment is for some reason not found', 'woo-ideal-gateway')
			);
			return $data;
		}
		else $charge_id = get_post_meta($order_id, 'woo-ideal-gateway-stripe-charge-id', true);
		
		$stripe_data = array(
		
			'charge' => $charge_id,
			'amount' => $amount,
			'reason' => 'requested_by_customer',
			
			'metadata' => array(
				'woocommerce_reason' => $reason
			)
		);
		
		$response = wp_remote_post($url, array(
			'method' => 'POST',
			'timeout' => 45,
			'redirection' => 5,
			'httpversion' => '1.0',
			'blocking' => true,
			'headers' => array(
				"Content-Type" => "application/x-www-form-urlencoded",
				"Authorization" => "Bearer " . $api_key
			),
			'body' => $stripe_data,
			'cookies' => array()
			)
		);
		
		
		if (is_wp_error($response)) {
			$error_message = $response->get_error_message();
			$data = array(
				'success' => 'no',
				'error_type' => 'response_http_error',
				'error_message' => $error_message
			);
			
			return $data;
			
		} else {
			$response = json_decode($response['body'],true);
			
			if($response['status'] == "succeeded" OR $response['status'] == "pending") {
				$refund_id = $response['id'];
				update_post_meta($order_id, 'woo-ideal-gateway-stripe-refund-id', $refund_id);
				$order->add_order_note(__('iDEAL Payment refunded', 'woo-ideal-gateway') . '<br>' . __('Refund ID:', 'woo-ideal-gateway') . ' ' . $refund_id);
				
				$data = array(
					'success' => 'yes'
				);
				return $data;
			}
			else {
				$error_type = $response['error']['type'];
				$error_message = $response['error']['message'];
				
				$data = array(
					'success' => 'no',
					'error_type' => $error_type,
					'error_message' => $error_message
				);
				return $data;
			}
		}
		
	}
	
}
?>