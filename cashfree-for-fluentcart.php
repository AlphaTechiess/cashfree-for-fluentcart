<?php
/**
 * Plugin Name: Cashfree Payments for FluentCart
 * Description: Cashfree Payments gateway for FluentCart with embedded popup checkout, subscriptions, refunds, and zero-trust webhook verification.
 * Author: FluentCart Team
 * Author URI: https://fluentcart.com/about-us
 * Version: 1.0
 * Requires Plugins: fluent-cart
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: cashfree-for-fluentcart
 */

if (!defined('ABSPATH')) exit;

defined('CFFC_VER') || define('CFFC_VER', '1.0');
defined('CFFC_DIR') || define('CFFC_DIR', plugin_dir_path(__FILE__));
defined('CFFC_URL') || define('CFFC_URL', plugin_dir_url(__FILE__));

function cffc_get_gateway(): ?\CashfreePaymentsFC\Gateway {
    static $gateway = null;
    if ($gateway === null) {
        if (!class_exists(\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::class)) {
            return null;
        }
        require_once CFFC_DIR . 'includes/Settings.php';
        require_once CFFC_DIR . 'includes/API.php';
        require_once CFFC_DIR . 'includes/Gateway.php';
        $gateway = new \CashfreePaymentsFC\Gateway();
    }
    return $gateway;
}

add_action('init', static function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bootstrap loader only; AJAX handlers verify nonces and IPN verifies HMAC signatures.
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    if (wp_doing_ajax() && in_array($action, ['cffc_confirm_payment', 'cffc_payment_status', 'cffc_ipn'], true)) {
        cffc_get_gateway();
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public return/IPN route bootstrap; payment status and webhook signatures are verified server-side.
    $has_return_tx = isset($_GET['cffc_tx']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public FluentCart IPN listener route; webhook signature is verified in Gateway::handleIPN().
    $is_fc_route = isset($_GET['fluent-cart']) || isset($_GET['fluent_cart']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public FluentCart IPN listener route; webhook signature is verified in Gateway::handleIPN().
    $method = isset($_GET['method']) ? sanitize_key(wp_unslash($_GET['method'])) : '';
    if ($has_return_tx || ($is_fc_route && $method === 'cashfree')) {
        cffc_get_gateway();
    }
}, 5);

add_action('rest_api_init', static function () {
    register_rest_route('cashfree-fluentcart/v1', '/webhook', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => static function ($request) {
            $gateway = cffc_get_gateway();
            if (!$gateway) {
                return new \WP_REST_Response(['status' => 'error', 'message' => 'FluentCart core is not available.'], 503);
            }
            return $gateway->handleWebhook($request);
        },
    ]);
});

add_action('fluent_cart/register_payment_methods', static function ($data = null) {
    $gateway = cffc_get_gateway();
    if (!$gateway) return;
    if (is_object($data)) {
        if (method_exists($data, 'registerCustomPaymentMethod')) { $data->registerCustomPaymentMethod('cashfree', $gateway); return; }
        if (method_exists($data, 'register')) { $data->register('cashfree', $gateway); return; }
    }
    if (is_array($data) && is_object($data['gatewayManager'] ?? null) && method_exists($data['gatewayManager'], 'register')) {
        $data['gatewayManager']->register('cashfree', $gateway);
        return;
    }
    if (function_exists('fluent_cart_api')) {
        fluent_cart_api()->registerCustomPaymentMethod('cashfree', $gateway);
    }
});

add_action('admin_notices', static function () {
    if (!current_user_can('activate_plugins') || class_exists(\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::class)) return;
    echo '<div class="notice notice-warning is-dismissible"><p><strong>'
        . esc_html__('Cashfree Payments for FluentCart', 'cashfree-for-fluentcart') . ':</strong> '
        . esc_html__('FluentCart must be installed and activated for Cashfree Payments to work.', 'cashfree-for-fluentcart')
        . '</p></div>';
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    if (function_exists('fluent_cart_api')) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=fluent-cart#/settings/payments/cashfree')) . '">' . esc_html__('Settings', 'cashfree-for-fluentcart') . '</a>');
    }
    return $links;
});
