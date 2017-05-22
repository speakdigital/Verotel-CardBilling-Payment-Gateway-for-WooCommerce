=== Verotel / CardBilling Payment Gateway for WooCommerce ===
Contributors: bkonyn
Tags: woocommerce, payment, gateway, verotel, cardbilling
Requires at least: 3.0.1
Tested up to: 4.7.5
Stable tag: trunk
Contributors: jcroucher
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Use Verotel or CardBilling as a payment method with your WooCommerce store.

== Description ==

This plugin allows you to use Verotel or CardBilling as a payment method with your WooCommerce store.
Support for subscriptions was added in version 2, coded by John Croucher.

== Installation ==

Download and install the plugin.
Go to your WooCommerce Settings section and look at the Checkout tab.
You will see a section for Verotel / CardBilling.

Complete all the required fields and then proceed to test.

== Frequently Asked Questions ==

= Is this plugin written / supported by Verotel or CardBilling =

No. It does use their API and has been tested on my own sites.

== Changelog ==

= 2.0.3 =
* Added Customer ID setting to automatically select Verotel / CardBilling
* Removed manual option for selecting which brand
* Updated FlexPay API code to 4.0.1 and included all branding files

= 2.0.2 =
* Fix fatal error caused by wcs_is_subscription() fucntion not being defined

= 2.0 =
* FlexPay 3.4 API
* Subscription payments
* Verotel Control Center API

= 1.2 =
* Fixed bug with Wordpress 4.6.1 that caused options not to display by making id lowercase only

= 1.1 =
* Added display of Postback URLs
* Added option to select between Verotel / CardBilling

= 1.0 =
* First release