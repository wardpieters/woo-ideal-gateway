<?php
/*
 * Plugin Name: WooCommerce iDEAL Gateway
 * Plugin URI: https://wordpress.org/plugins/woo-ideal-gateway/
 * Description: Payment gateway for WooCommerce that allows iDEAL via Stripe
 * Author: Ward Pieters
 * Author URI: https://wardpieters.nl/
 * Version: 2.7
 * Text Domain: woo-ideal-gateway
 *
 * Copyright: (c) 2018 Ward Pieters
 *
 * WC tested up to: 3.6.5
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package		WC_iDEAL_Gateway
 * @author		Ward Pieters
 * @category	E-Commerce
 * @copyright	Copyright (c) 2018 Ward Pieters
 * @license		http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}

/**
 * Load plugin textdomain
 * This fixes the translation problems
 *
 * @since 2.3.1
 */
function woo_ideal_gateway_load_plugin_textdomain() {
	load_plugin_textdomain('woo-ideal-gateway', FALSE, basename(dirname(__FILE__)) . '/i18n/languages/');
}
add_action( 'plugins_loaded', 'woo_ideal_gateway_load_plugin_textdomain' );


/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 */
function wc_ideal_add_to_gateways($gateways) {
	$gateways[] = 'WC_iDEAL_Gateway';
	return $gateways;
}
add_filter('woocommerce_payment_gateways', 'wc_ideal_add_to_gateways');

/**
 * Adds plugin page links
 *
 * @since 1.0.0
 */
function woo_ideal_gateway_plugin_links($links) {
	$plugin_links = array(
		'<a href="' . esc_url(admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ideal_gateway' )) . '">' . __( 'Settings', 'woo-ideal-gateway' ) . '</a>',
		'<a href="https://wordpress.org/support/plugin/woo-ideal-gateway/">' . __( 'Support', 'woo-ideal-gateway' ) . '</a>'
	);
	return array_merge( $plugin_links, $links );
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woo_ideal_gateway_plugin_links');


/**
 * Initializes all classes required
 *
 * @since 2.3
 */
function IncludeClasses() {
	include(__DIR__ . '/includes/woo-ideal-gateway-class-fee.php');
	include(__DIR__ . '/includes/woo-ideal-gateway-class-notification.php');
	include(__DIR__ . '/includes/woo-ideal-gateway-class-stripe-webhook.php');
	
	$Notification = new Notification(true);
	$Notification->woo_ideal_gateway_check_api();
	
	$Fee = new Fee;
	$Fee->woo_ideal_gateway_fee_init();
	
	$StripeWebhook = new StripeWebhook;
	$StripeWebhook->ReceiveWebhook();
}

add_action('init', 'IncludeClasses', 11);
add_action('plugins_loaded', 'woo_ideal_gateway_init', 11);

function woo_ideal_webhook_exec() {
    $StripeWebhook = new StripeWebhook;

    $options = get_option("woocommerce_ideal_gateway_settings", array());

    if(($options['stripe-webhook-id'] == "") OR (!$StripeWebhook->checkWebhook())) {
        $StripeWebhook->addWebhook();
    }
}
add_action( 'woo_ideal_webhook', 'woo_ideal_webhook_exec' );

if ( ! wp_next_scheduled( 'woo_ideal_webhook' ) ) {
    wp_schedule_event( time(), 'hourly', 'woo_ideal_webhook' );
}

function woo_ideal_remove_old_webhooks_exec() {
    $StripeWebhook = new StripeWebhook;

    $webhooks = $StripeWebhook->getAllWebhooks();

    if ($webhooks !== false) {
        foreach ($webhooks as $webhook) {
            $url = parse_url($webhook["url"]);
            $wp_url = parse_url(home_url());

            // Domain equals domain WordPress is running on and URL contains variables used in a previous version of this plugin.
            if ($url["host"] == $wp_url["host"] AND strpos($url["query"], 'key=') !== false AND strpos($url["query"], 'stripe_webhook=yes') !== false) {
                $StripeWebhook->disableWebhook($webhook["id"]);
            }
        }
    }
}

function woo_ideal_updated( $upgrader_object, $options ) {
    $current_plugin_path_name = plugin_basename( __FILE__ );

    if ($options['action'] == 'update' && $options['type'] == 'plugin' ){
        foreach($options['plugins'] as $plugin){
            if ($plugin == $current_plugin_path_name){
                woo_ideal_remove_old_webhooks_exec();
                woo_ideal_webhook_exec();
            }
        }
    }
}
add_action('upgrader_process_complete', 'woo_ideal_updated', 10, 2);

function admin_notice_webhook() {
    $options = get_option("woocommerce_ideal_gateway_settings", array());

    if (isset($_GET["section"]) AND $_GET["section"] == "ideal_gateway" AND isset($_GET['tab']) AND $_GET["tab"] == "checkout") {
        $StripeWebhook = new StripeWebhook;
        $webhook_status = $StripeWebhook->checkWebhook();

        if(isset($_GET['fixed']) AND $_GET['fixed'] == "1" AND $webhook_status) {
            echo "<div class=\"notice notice-success is-dismissible\"><p>Webhook is ingesteld!</p></div>";
        }
        elseif (!$webhook_status) {
            echo "<div class=\"notice notice-error is-dismissible\"><p>Webhook is niet goed ingesteld! Klik <a href=\"" . esc_url(admin_url("admin.php?page=wc-settings&tab=checkout&section=ideal_gateway&fix_webhook")) . "\">hier</a> om dit op te lossen.</p></div>";
        }
    }
}
add_action( 'admin_notices', 'admin_notice_webhook' );

function setup_webhook_and_redirect() {
    if (isset($_GET["section"]) AND $_GET["section"] == "ideal_gateway" AND isset($_GET['tab']) AND $_GET["tab"] == "checkout" AND isset($_GET['fix_webhook'])) {
        $StripeWebhook = new StripeWebhook;
        woo_ideal_remove_old_webhooks_exec();
        $StripeWebhook->addWebhook();

        wp_safe_redirect(esc_url(admin_url("admin.php?page=wc-settings&tab=checkout&section=ideal_gateway&fixed=1")));
    }
}
add_action( 'admin_init', 'setup_webhook_and_redirect' );


function woo_ideal_gateway_init() {

	class WC_iDEAL_Gateway extends WC_Payment_Gateway {
		
		/**
		 * @since 0.5 
		 * Constructor for the gateway.
		 */
		public function __construct($add_actions = false) {
			$this->id				  = 'ideal_gateway';
			$this->icon				  = apply_filters( 'woocommerce_gateway_icon', plugins_url('woo-ideal-gateway\images\ideal.png', dirname(__FILE__)) );
			$this->has_fields		  = true;
			$this->method_title		  = __('iDEAL', 'woo-ideal-gateway');
			//$this->method_description = __('Adds iDEAL as payment gateway, a <a href="https://stripe.com/">Stripe API key</a> is required.', 'woo-ideal-gateway' );
			$this->method_description = __('WooCommerce iDEAL Gateway will generate a iDEAL source at Stripe using their API, then send the customer to their bank of choice. At this point the order is put on-hold, once the order is payed, Stripe will use a webhook to let WooCommerce know the payment succeeded. To use this payment gateway you are required to have a <a href="https://stripe.com/">Stripe API key</a>, you can read more about Stripe\'s Authentication <a href="https://stripe.com/docs/api#authentication">here</a>.', 'woo-ideal-gateway' );
			
			$this->supports = array('products', 'refunds');
			
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->description = $this->get_option('description');
			$this->stripe_api_live = $this->get_option('woocommerce-stripe-api-live');
			$this->stripe_api_test = $this->get_option('woocommerce-stripe-api-test');
			$this->show_error_codes_to_customer = $this->get_option('show-error-codes-to-customer');
			$this->show_checkout_dropdown = $this->get_option('show-checkout-dropdown');
			$this->title = $this->get_option('title');
			
			// Stripe API Information
			$this->api_url = "https://api.stripe.com/v1/";
			$this->user_agent = "WooCommerce iDEAL Gateway/2.6 (https://wordpress.org/plugins/woo-ideal-gateway/)";
			$this->api_version = "2018-07-27";

			// API Key variable used in other classes to get current API Key
			if($this->get_option('test-mode') == 'yes') {
				$this->api_key = $this->get_option('stripe-api-test');
				$this->api_key_type = __('test', 'woo-ideal-gateway');
			}
			else {
				$this->api_key = $this->get_option('stripe-api-live');
				$this->api_key_type = __('live', 'woo-ideal-gateway');
			}
			
			// Actions
			if ($add_actions == true) {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				add_action('woocommerce_thankyou_' . $this->id, array($this, 'woo_ideal_gateway_check_source'));
			}
		}

		/**
		* Initialize payment gateway form fields
		*
		* @since 2.0
		*/
		public function init_form_fields() {
			$this->form_fields = apply_filters( 'woo__ideal_gateway_form_fields', array(

				'enabled' => array(
					'title'   => __('Enable/Disable', 'woo-ideal-gateway'),
					'type'    => 'checkbox',
					'label'   => __('Enable iDEAL payments', 'woo-ideal-gateway'),
					'default' => 'yes'
				),

				'title' => array(
					'title'       => __('Title', 'woo-ideal-gateway'),
					'type'        => 'text',
					'description' => __('Title of this payment gateway a customer sees when checking out', 'woo-ideal-gateway' ),
					'default'     => __('iDEAL', 'woo-ideal-gateway'),
					'desc_tip'    => false,
				),
				
				'test-mode' => array(
					'title'   => __('Enable/Disable Test Mode', 'woo-ideal-gateway'),
					'type'    => 'checkbox',
					'label'   => __('Enable test mode to see if you have correctly setup Stripe iDEAL payements', 'woo-ideal-gateway'),
					'default' => 'yes'
				),

				'stripe-api-live' => array(
					'title'       => __('Stripe Secret API Key (Live)', 'woo-ideal-gateway'),
					'type'        => 'text',
					'description' => __('Secret API Key from Stripe used for live payments', 'woo-ideal-gateway' ),
					'default'     => __('sk_live_XXXXXXXXXXXXXXXXXXXXXXXX', 'woo-ideal-gateway'),
					'desc_tip'    => true,
				),
				
				'stripe-api-test' => array(
					'title'       => __('Stripe Secret API Key (Test)', 'woo-ideal-gateway'),
					'type'        => 'text',
					'description' => __('Secret API Key from Stripe used for test payments', 'woo-ideal-gateway' ),
					'default'     => __('sk_test_XXXXXXXXXXXXXXXXXXXXXXXX', 'woo-ideal-gateway'),
					'desc_tip'    => true,
				),

				'payment-description' => array(
					'title'       => __('Payment reference', 'woo-ideal-gateway'),
					'type'        => 'text',
					'description' => __('Payment reference a customer sees in their iDEAL-enviroment', 'woo-ideal-gateway' ),
					'default'     => __('Payment for #{order_number}', 'woo-ideal-gateway'),
					'desc_tip'    => false,
				),
				
				'show-checkout-dropdown' => array(
					'title'   => __('Show dropdown with banks', 'woo-ideal-gateway'),
					'type'    => 'checkbox',
					'label'   => __('Show a dropdown with banks on the checkout page.', 'woo-ideal-gateway'),
					'default' => 'yes'
				),
				
				'stripe-cost-to-customer' => array(
					'title'   => __('Transaction fee', 'woo-ideal-gateway'),
					'type'    => 'checkbox',
					'label'   => __('Let the customer pay the transaction fee.', 'woo-ideal-gateway'),
					'default' => 'no'
				),

				'show-error-codes-to-customer' => array(
					'title'   => __('Visible error codes', 'woo-ideal-gateway'),
					'type'    => 'checkbox',
					'label'   => __('If there is an error while checking out, show the error code to the user. This might be useful for you to solve an issue a customer has.', 'woo-ideal-gateway'),
					'default' => 'yes'
				),

			) );
		}
		
		/**
		* Includes the StripeAPI Class
		*
		* @since 2.0
		*/
		public function InitStripeAPI() {
			require_once(__DIR__ . '/includes/woo-ideal-gateway-class-stripe.php');
		}
		
		/**
		* When redirect to the webshop check source if payment is failed, if so update order status.
		*
		* @since 2.4
		*/
		public function woo_ideal_gateway_check_source($order_id) {

			$this->InitStripeAPI();
			$StripeAPI = new StripeAPI;

			$order = new WC_Order($order_id);
			
			$order_data = $order->get_data();
			$order_status = $order_data['status'];
			
			if($order_status != "on-hold") return false;
			
			$source = get_post_meta($order_id, 'woo-ideal-gateway-stripe-source', true);
			$status = $StripeAPI->GetSourceStatus($source);
			
			if($status !== false) {
				if($status == 'failed') {
					$order->update_status('failed', __('iDEAL Payment canceled by customer', 'woo-ideal-gateway'));
					wp_redirect($_SERVER['REQUEST_URI'], 302, $this->user_agent);
					exit();
				}
			}
			
		}
		
		/**
		* Adds the bank selector at the checkout screen
		*
		* @since 0.5
		*/
		public function payment_fields() {
			
			if($this->show_checkout_dropdown == 'yes') {
			
				$stripe_banks = array(
					'abn_amro' => 'ABN Amro',
					'asn_bank' => 'ASN Bank',
					'bunq' => 'Bunq',
					'handelsbanken' => 'Handelsbanken',
					'ing' => 'ING',
					'knab' => 'Knab',
					'moneyou' => 'Moneyou',
					'rabobank' => 'Rabobank',
					'regiobank' => 'Regiobank',
					'sns_bank' => 'SNS Bank',
					'triodos_bank' => 'Triodos Bank',
					'van_lanschot' => 'Van Lanschot'
				);

				echo '<label>' . __("Bank", 'woo-ideal-gateway') . '<span class="required">*</span></label>
				<br>
				<select id="iDEAL_BANK" name="iDEAL_BANK">
				<option disabled="disabled" selected>' . __("Choose your bank", 'woo-ideal-gateway') . '</option>';
				
				foreach( $stripe_banks as $code => $name ) {
					echo '<option value="' . $code . '">' . $name . '</option>';
				}
				echo '</select>';
			
			}
			
			else {
				echo "<input type=\"hidden\" name=\"iDEAL_BANK\" id=\"iDEAL_BANK\" value=\"REDIRECT\"></input>";
				echo "<p>" . __("You will be redirected to iDEAL", 'woo-ideal-gateway') . "</p>";
			}
		}

		/*
		 * Process the payment and return the result
		 *
		 * @since 0.5
		 */
		public function process_payment($order_id) {
			$this->InitStripeAPI();
			$StripeAPI = new StripeAPI;
			
			global $woocommerce;
			$order = new WC_Order($order_id);
			$order_data = $order->get_data();
			$payment_gateway = $order_data['payment_method'];
			
			if($order_data['payment_method'] == "ideal_gateway") {
				
				
				/**
				* @since 2.2
				* Checks if user entered a bank, if user entered a bank, check if it's correct and exists in $stripe_bank
				* Otherwise return error on checkout page
				*/
				
				$stripe_bank = array('abn_amro', 'asn_bank', 'bunq', 'handelsbanken', 'ing', 'knab', 'moneyou', 'rabobank', 'regiobank', 'sns_bank', 'triodos_bank', 'van_lanschot');
				
				if(!isset($_POST['iDEAL_BANK']) && (!in_array($_POST['iDEAL_BANK'],$stripe_bank) OR $_POST['iDEAL_BANK'] != "REDIRECT")) {
					wc_add_notice(__("Please choose your bank and try again", 'woo-ideal-gateway'), 'error');
					return;
				}
				
				/**
				* @since 2.3
				* If enabled, user will see the error code on the checkout page,
				* this might be useful if you want to fix this issue.
				*/
				
				if($this->show_error_codes_to_customer == 'yes') $show_error = true;
				else $show_error = false;
				
				$stripe_response = $StripeAPI->CreateSource($order_data, $order_id);
				
				if($stripe_response['success'] == 'no') {
					$order->add_order_note(__('Stripe error', 'woo-ideal-gateway') . ': ' . $stripe_response['error_message'] . ' (' . $stripe_response['error_type'] . ')'); //FAILURE NOTE
					if($show_error) wc_add_notice(__('iDEAL payment failed, please try again', 'woo-ideal-gateway') . ' (' . __('Error', 'woo-ideal-gateway') . ': ' . $stripe_response['error_type'] . ')', 'error');
					else wc_add_notice(__('iDEAL payment failed, please try again', 'woo-ideal-gateway'), 'error');
					return;
				}
				elseif($stripe_response['success'] == 'yes') {
					$stripe_source_id = $stripe_response['source_id'];
					$stripe_url = $stripe_response['redirect_url'];
					update_post_meta($order_id, 'woo-ideal-gateway-stripe-source', $stripe_source_id);
					
					$order->update_status('on-hold', __('New iDEAL payment initiated by customer', 'woo-ideal-gateway'));
					
					// Empty cart
					WC()->cart->empty_cart();
					
					return array(
						'result' => 'success',
						'redirect' => $stripe_url
					);
					
				}
			
			}
			
		}
		
		/**
		* Refunds the payment via Stripe
		*
		* @since 2.0
		*/
		public function process_refund($order_id, $amount = null, $reason = null) {
			$this->InitStripeAPI();
			$StripeAPI = new StripeAPI;
			$order = new WC_Order($order_id);
			if($order->has_status('on-hold') || empty(get_post_meta($order_id, 'woo-ideal-gateway-stripe-source', true)) || empty(get_post_meta($order_id, 'woo-ideal-gateway-stripe-charge-id', true))) {
				// No refund possible through iDEAL
				return new WP_Error('ideal_refund_not_possible', __('A refund through iDEAL is not possible because the order has not been paid yet. Or the order was initially not using this payment gateway.', 'woo-ideal-gateway'));
			}
			else {
				$stripe_response = $StripeAPI->RefundPayment($order_id, $amount, $reason);
				if($stripe_response['success'] == 'yes') {
					// Refund succeeded
					return true;
				}
				else {
					// Refund failed, user gets notified.
					$error_type = $stripe_response['error_type'];
					$error_message = $stripe_response['error_message'];
					
					return new WP_Error($error_type, __('Stripe error', 'woo-ideal-gateway') . ': ' . $error_message);
				}
			}
			
		}
		
	}
  
}