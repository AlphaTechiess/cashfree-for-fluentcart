=== Cashfree Payments for FluentCart ===
Contributors: fluentcart
Tags: cashfree, fluentcart, payment gateway, upi, subscriptions
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept one-time payments, subscriptions, and refunds in FluentCart via Cashfree Payments with UPI, cards, netbanking, wallets, and webhooks.

== Description ==

Cashfree Payments for FluentCart connects your FluentCart store to Cashfree Payments with an embedded checkout modal, recurring subscriptions, refunds, and zero-trust webhook verification.

= Key Features =

* **Embedded Modal Checkout:** Smooth payment experience supporting UPI, Credit/Debit Cards, NetBanking, and Wallets.
* **Subscriptions & Refunds:** Native support for recurring subscription plans and order/subscription refunds.
* **Live & Sandbox Modes:** Configure production and sandbox credentials independently.
* **Zero-Trust Webhook Verification:** REST API and IPN webhook endpoints with server-side signature validation.
* **Clean Uninstall:** Deletes stored Cashfree credentials upon uninstall while preserving FluentCart orders and transaction records.

= External Services =

This plugin connects to Cashfree's Payment Gateway API (`https://api.cashfree.com/pg` and `https://sandbox.cashfree.com/pg`) and loads the Cashfree JavaScript SDK (`https://sdk.cashfree.com/js/v3/cashfree.js`) to process payments, manage subscriptions, and issue refunds. Customer name, email, phone number, and order details are sent to Cashfree during checkout.

* [Cashfree Terms and Conditions](https://www.cashfree.com/tnc/)
* [Cashfree Privacy Policy](https://www.cashfree.com/privacypolicy/)

== Installation ==

1. Ensure that **FluentCart** is installed and activated.
2. Upload the `cashfree-for-fluentcart` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP via **Plugins > Add New**.
3. Activate **Cashfree Payments for FluentCart** in WordPress.
4. Go to **FluentCart > Settings > Payment Settings > Cashfree Payments**.
5. Enter your App ID and Secret Key, then add the displayed Webhook URL in your Cashfree Merchant Dashboard.

== Frequently Asked Questions ==

= Does this plugin require FluentCart? =

Yes. FluentCart must be installed and activated for this payment gateway to function.

= Does it support subscriptions? =

Yes. Recurring subscription plans and webhook status updates are supported.

== Changelog ==

= 1.0 =
* Initial release with embedded checkout, subscriptions, refunds, and webhook verification.
