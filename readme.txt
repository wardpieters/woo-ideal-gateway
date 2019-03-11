=== WooCommerce iDEAL Gateway ===
Contributors: WardPieters, WooCommerce
Tags: ideal, woocommerce, stripe, ideal betalen, payment gateway, ideal gateway, ecommerce, shopping, webshop
Donate link: https://www.paypal.me/wardpieters
Requires at least: 4.7
Tested up to: 5.1
Stable tag: 2.6
Requires PHP: 5.5
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Payment gateway for WooCommerce that allows iDEAL via Stripe

== Description ==
Payment gateway for WooCommerce that allows iDEAL via [Stripe](https://stripe.com/)

= Current features =
*	iDEAL payments, quick and hassle free
*	Refunds through Stripe
*	Supported banks
	*	ABN Amro
	*	ASN Bank
	*	Bunq
	*	Moneyou
	*	ING
	*	Knab
	*	Rabobank
	*	Regiobank
	*	SNS Bank
	*	Triodos Bank
	*	Van Lanschot

== Installation ==

= Requirements =

The WooCommerce iDEAL Gateway plugin extends WooCommerce with the iDEAL payment method.
To offer the iDEAL payment method to the vistors of your WordPress website you also require [WooCommerce](https://nl.wordpress.org/plugins/woocommerce/) to be installed.

= Setting up a webhook =
To correctly setup a [webhook](https://stripe.com/docs/webhooks) you have to go to your [Stripe Dashboard > API > Webhooks](https://dashboard.stripe.com/account/webhooks), make sure you are not in test mode.

Then you click on 'Add Endpoint', in the field 'URL to be called' you fill in the URL stated in the settings of the plugin.

After that you click on 'Select types to send' and mark 'source.chargeable' (to find it very fast, just do CTRL+F). For test mode you are going to de exactly the same, so you could receive payments when in test mode.

If you need help setting this up, please look in the Support section of the FAQ.

== Frequently Asked Questions ==

= Support =
If you have any problem with this plugin don't hesitate to open a topic over [here](https://wordpress.org/support/plugin/woo-ideal-gateway) or contact me at [support@wardpieters.nl](mailto:support@wardpieters.nl). I will reply as soon as possible.

= Why won't the money go directly to my bank account? =
According to iDEAL's requirements, the money can only go to business accounts. PSPs (Payment Service Providers) who offer C2C (Customer-to-Customer) payments must first hold the money. Stripe is a PSP and holds your money first in a German bank account. Depending on your payout settings, the money will be transferred to you daily/weekly/monthly, but the money you earn today will not be transferred in less than 5 business days.

Stripe works with a German payment provider and therefore the money goes to a German bank account, but customers will see your companies' name on their bank statement.

If you make a refund from the Stripe dashboard, people will see your companies' name on their bank statements.

= Why are my customers getting two mails short after each other? =
When a customer places an order, the order is put on-hold until WooCommerce receives a webhook event or by checking when a customer is being redirected to the webshop. So the first mail they get is from the order being put on-hold and the second one is from the order status being updated to either failed or processing.
If you want to disable this, you can simply disable on-hold mails in the WooCommerce mail settings.

= What does my error code mean? =

*	001: The provided Stripe source does not equal the source of the order
*	002: The Stripe source is not chargeable at this time
*	003: The Stripe source is not paid

== Screenshots ==
1. Checkout
2. Settings
3. iDEAL enviroment
4. Successful order
5. Failed order

== Changelog ==
= 2.6 =
* Added the functionality to change the transaction fee
* Fixed `init_transactional_emails` hook

= 2.5 =
* Added the functionality to redirect to Stripe instead of choosing the bank on the checkout page
* Added Moneyou bank
* Small improvements

= 2.4 =
* Added feature to show order failed page when redirected back to your shop
* Performance improvements

= 2.3.2 =
* Wrong order amount in Stripe Dashboard fixed
* Added Swedish language (niklaswallerstedt, jyourstone)

= 2.3.1 =
Translation problems fixed

= 2.3 =
* Added the option to show an error code to the user when on the checkout page
* Checkout errors fixed
* Refunds improved
* Other minor improvements

= 2.2 =
* Added 'Choose your bank' to dropdown on checkout page
* Errors e.g. 'Please choose your bank and try again' are now visibile to the end users.
* Order notes are changed

= 2.0 =
Biggest update so far, completely redesigned from scratch!

* Support for refunds
* Uses the Stripe webhook for receiving payments
* Redirecting back from Stripe to your webshop is way faster thanks to [webhooks](https://stripe.com/docs/webhooks) :)
* Some minor fixes e.g correctly displaying the iDEAL fee when checking out

= 1.3 =
* Fixed security issue with Stripe source manipulation
* Added suport for descriptions which are visibile in your Stripe Dashboard
* Bugfixes

= 1.1 =
* Securityfixes

= 1.0 =
* First release

== Upgrade Notice ==
= 2.6 =
Added the functionality to change the transaction fee and fixed the `init_transactional_emails` hook

= 2.5 =
Added Moneyou and the functionality to be redirected to Stripe without choosing a bank

= 2.4 =
Added feature to show order failed page when redirected back to your shop

= 2.3.2 =
Wrong order amount in Stripe Dashboard fixed. Added Swedish language (niklaswallerstedt, jyourstone)

= 2.3 =
Important update: checkout errors fixed. See changelog for further changes

= 2.2 =
Added 'Choose your bank' to dropdown on checkout page. Errors e.g. 'Please choose your bank and try again' are now visibile to the end users. Order notes are changed

= 2.0 =
Notice: When upgrading to this version you have to create a webhook! Read [here](https://wordpress.org/plugins/woo-ideal-gateway/#faq) how to set it up before using it.

= 1.3 =
Fixed security issue with Stripe source manipulation. Added suport for order descriptions which are visibile in your Stripe Dashboard

= 1.1 =
Securityfixes