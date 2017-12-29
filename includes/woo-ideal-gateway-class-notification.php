<?php

defined('ABSPATH') or exit;

class Notification extends WC_iDEAL_Gateway {
	
	/**
	 * Adds a notice on all admin screens
	 *
	 * @since 2.3
	 */
	function woo_ideal_gateway_api_key_admin_notice_error() {
		$api_key = $this->api_key;
		$api_key_type = $this->api_key_type;
		$class = 'notice notice-error';
		$message = '<strong>' . __('Notice', 'woo-ideal-gateway') . ':</strong> ' . __('The API Key you are currently using for %api_key_type% payments is the default one. Please change it to a working one found in your Stripe Dashboard.', 'woo-ideal-gateway');
		$message = str_replace('%api_key_type%', $api_key_type, $message);
		printf('<div class="%1$s"><p><strong>WooCommerce iDEAL Gateway</strong></p><p>%2$s</p></div>', esc_attr($class), $message); 
	}
	
	
	/**
	 * Checks the API if it is correct or still using the default one
	 *
	 * @since 2.3
	 */
	function woo_ideal_gateway_check_api() {
		$api_key = $this->api_key;
		
		if($api_key == "sk_live_XXXXXXXXXXXXXXXXXXXXXXXX" || $api_key == "sk_test_XXXXXXXXXXXXXXXXXXXXXXXX") {
			add_action('admin_notices', array($this, 'woo_ideal_gateway_api_key_admin_notice_error'));
		}
	}
	
	
}