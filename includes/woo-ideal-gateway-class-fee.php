<?php

defined('ABSPATH') or exit;

class Fee extends WC_iDEAL_Gateway {

	/**
	 * Initialize the iDEAL fee
	 *
	 * @since 2.0
	 */
	function woo_ideal_gateway_fee_init() {
		add_action('woocommerce_cart_calculate_fees', array($this, 'woo_ideal_gateway_add_stripe_cost'));
		add_action('wp_footer', array($this, 'woo_ideal_gateway_cart_update_script'), 9999);
	}


	/**
	 * Adds the iDEAL fee when enabled
	 *
	 * @since 2.0
	 */
	function woo_ideal_gateway_add_stripe_cost() {
		global $woocommerce;
		if((is_admin() && !defined('DOING_AJAX')) || !is_checkout()) return;

		if(WC()->session->chosen_payment_method == "ideal_gateway" && $this->get_option('stripe-cost-to-customer') == 'yes') {
			$woocommerce->cart->add_fee(__('Transaction fee', 'woo-ideal-gateway'), 0.29, true, 'standard');
		}
	}


	/**
	 * Updates the cart when switching payment methods
	 *
	 * @since 2.0
	 */
	function woo_ideal_gateway_cart_update_script() {
		global $woocommerce;
		if (is_checkout()) :
		?>
			<script>
				jQuery(function($) {
					if (typeof woocommerce_params === 'undefined') return false;
					$checkout_form = $('form.checkout');
					$checkout_form.on('change', 'input[name="payment_method"]', function() {
						$checkout_form.trigger('update');
					});
				});
			</script>
		<?php
		endif;
	}

}