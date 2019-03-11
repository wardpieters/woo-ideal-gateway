<?php

defined('ABSPATH') or exit;

class StripeWebhook extends WC_iDEAL_Gateway {
	
	/**
	* Receives and proccesses the webhook received by Stripe
	*
	* @since 2.0
	*/
	function ReceiveWebhook() {
		global $woocommerce;
		$url = $this->api_url . "charges";
		
		$webhook_key_stored = $this->get_option('stripe-webhook-key');
		
		if(isset($_GET['stripe_webhook']) && isset($_GET['key'])) {
			$webhook = sanitize_text_field($_GET['stripe_webhook']);
			$webhook_key = sanitize_text_field($_GET['key']);
		}
		else {
			$webhook = "no";
			$webhook_key = "";
		}
		
		if($webhook == "yes" && $webhook_key_stored == $webhook_key) {
			
			// Stripe webhook received
			$input = json_decode(file_get_contents("php://input"),true);
			$data = $input['data']['object'];
			
			$woo_ideal_gateway_payment = $data['metadata']['woo-ideal-gateway'];
			
			
			if($woo_ideal_gateway_payment == true) {
				// If payment is made via WooCommerce iDEAL Gateway
				
				if($data['type'] == "ideal") {
					// Source is using iDEAL
					
					if($data['status'] == "chargeable") {
						// Source is chargeable
						
						$order_id_meta = intval($data['metadata']['order_id']);
						
						$order = new WC_Order($order_id_meta);
						
						$amount = $order->get_total();
						$amount = (int) (((string) ($amount*100)));
						
						$source = get_post_meta($order_id_meta, 'woo-ideal-gateway-stripe-source', true);
						$source_stripe = $data['id'];
						
						$payment_description = $this->get_option('payment-description');
						$payment_description = str_replace("{order_number}", $order_id_meta, $payment_description);

						if($source == $source_stripe) {
							
							if($this->get_option('test-mode') == 'yes') {
								$StripeAPIKey = $this->get_option('stripe-api-test');
							}
							else {
								$StripeAPIKey = $this->get_option('stripe-api-live');
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
									"Content-Type" => "application/x-www-form-urlencoded",
									"Authorization" => "Bearer " . $StripeAPIKey
								),
								'body' => $stripe_data,
								'cookies' => array()
								)
							);
							
							if (is_wp_error($response)) {
							   $error_message = $response->get_error_message();
							   
							   $data = array(
									'error' => true,
									'message' => $error_message
								);
								
								exit(json_encode($data));
								
							} else {
								$response = json_decode($response['body'],true);
								
								if($response['paid'] == true) {
									$charge_id = $response['id'];
									$source_id = $response['source']['id'];
									$iban_last_4 = $response['source']['ideal']['iban_last4'];
									$ideal_bank = strtoupper($response['source']['ideal']['bank']);
									if($response['source']['owner']['verified_name'] != null) $verified_name = '<br>' . __('Name:', 'woo-ideal-gateway') . ' ' . $response['source']['owner']['verified_name'];
									else $verified_name = '';
									
									//Set order on payment complete
									update_post_meta($order_id_meta, 'woo-ideal-gateway-stripe-charge-id', $charge_id);
									$order->add_order_note(__('iDEAL Payment succeeded', 'woo-ideal-gateway') . '<br>' . __('IBAN: x', 'woo-ideal-gateway') . $iban_last_4 . ' (' . $ideal_bank . ')' . $verified_name . '<br>');
									$order->payment_complete($source_id);
									
									// Reduce stock levels
									//wc_reduce_stock_levels($order_id_meta);
									
									exit(json_encode($order));
									
									$output[] = array(
										'source_id' => $source_id,
										'charge_id' => $charge_id,
										'message' => __("Order status has been changed to processing", 'woo-ideal-gateway'),
										'error' => false
									);
									
									exit(json_encode($output));
								}
								else {
									$order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 003'); // order note is optional, if you want to  add a note to order

									$output[] = array(
										'source_id' => $source_stripe,
										'message' => __('Source is not successfully charged', 'woo-ideal-gateway'),
										'error' => true
									);
									
									exit(json_encode($output));
								}
							}
							
						}
						else {
							$order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 001'); // order note is optional, if you want to  add a note to order

							$output[] = array(
									'error' => true,
									'message' => __('Stripe source and WooCommerce Order Source are not the same!', 'woo-ideal-gateway')
								);
							exit(json_encode($output));
						}
						
					}
					else {
						$order->update_status('failed', __('iDEAL payment failed', 'woo-ideal-gateway') . ' - Error 002'); // order note is optional, if you want to  add a note to order

						// Source is not chargeable
						$output[] = array(
							'error' => true,
							'message' => __('Source is not in a chargeable state', 'woo-ideal-gateway')
						);
						exit(json_encode($output));
						
					}
		
				}
				$output[] = array(
					'error' => true,
					'message' => __('Source is not using the iDEAL payment method!', 'woo-ideal-gateway')
				);
				exit(json_encode($output));
				
			}
			$output[] = array(
				'error' => true,
				'message' => __('Source is not using the iDEAL payment method!', 'woo-ideal-gateway')
			);
			exit(json_encode($output));

		}
		
	}

}