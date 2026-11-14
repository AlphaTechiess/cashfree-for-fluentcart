<?php
/**
 * Plugin Name: Cashfree Payments for FluentCart
 * Description: Cashfree Payments gateway for FluentCart with hosted checkout, subscriptions, refunds, and secure webhook verification.
 * Author: FluentCart Team
 * Author URI: https://fluentcart.com/about-us
 * Plugin URI: https://fluentcart.com/plugins/cashfree-fluentcart/
 * Update URI: https://fluentcart.com/plugins/cashfree-fluentcart/
 * Version: 1.1.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: cashfree-fluentcart
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CFFC_VER', '1.1.0');
define('CFFC_DIR', plugin_dir_path(__FILE__));
define('CFFC_URL', plugin_dir_url(__FILE__));

/**
 * Safely load gateway classes if FluentCart core is available.
 *
 * @return bool True if loaded successfully, false otherwise.
 */
function cffc_load_gateway_files() {
    static $loaded = false;
    if ($loaded) {
        return true;
    }

    if (!class_exists('\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway')) {
        return false;
    }

    require_once CFFC_DIR . 'includes/Settings.php';
    require_once CFFC_DIR . 'includes/API.php';
    require_once CFFC_DIR . 'includes/Gateway.php';

    $loaded = true;
    return true;
}

/**
 * Get the shared singleton instance of Cashfree Gateway.
 *
 * @return \CashfreePaymentsFC\Gateway|null
 */
function cffc_get_gateway() {
    static $gateway = null;
    if ($gateway === null) {
        if (!cffc_load_gateway_files()) {
            return null;
        }
        $gateway = new \CashfreePaymentsFC\Gateway();
    }
    return $gateway;
}

/**
 * Display an admin notice if FluentCart is not active.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (class_exists('\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway')) {
        return;
    }
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong><?php esc_html_e('Cashfree Payments for FluentCart', 'cashfree-fluentcart'); ?>:</strong>
            <?php esc_html_e('FluentCart must be installed and activated for Cashfree Payments to work.', 'cashfree-fluentcart'); ?>
        </p>
    </div>
    <?php
});

/**
 * Early boot for Cashfree AJAX actions, return URL verification, and IPN webhooks.
 */
add_action('init', function () {
    if (wp_doing_ajax() && isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['cffc_confirm_payment', 'cffc_payment_status', 'cffc_ipn'], true)) {
        cffc_get_gateway();
    } elseif (isset($_GET['cffc_tx']) || (isset($_GET['fluent-cart']) && isset($_GET['method']) && $_GET['method'] === 'cashfree')) {
        cffc_get_gateway();
    }
}, 5);

/**
 * Register the REST API webhook route as an alternative/dedicated endpoint.
 */
add_action('rest_api_init', function () {
    register_rest_route('cashfree-fluentcart/v1', '/webhook', [
        'methods'             => 'POST',
        'callback'            => function ($request) {
            $gateway = cffc_get_gateway();
            if (!$gateway) {
                return new \WP_REST_Response([
                    'ok'      => false,
                    'message' => 'FluentCart core is not available.',
                ], 503);
            }

            return $gateway->handleWebhook($request);
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Register Cashfree payment method with FluentCart.
 */
add_action('fluent_cart/register_payment_methods', function ($data = null) {
    $gateway = cffc_get_gateway();
    if (!$gateway) {
        return;
    }

    if (is_object($data)) {
        if (method_exists($data, 'registerCustomPaymentMethod')) {
            $data->registerCustomPaymentMethod('cashfree', $gateway);
            return;
        }
        if (method_exists($data, 'register')) {
            $data->register('cashfree', $gateway);
            return;
        }
    }

    if (is_array($data) && isset($data['gatewayManager']) && is_object($data['gatewayManager']) && method_exists($data['gatewayManager'], 'register')) {
        $data['gatewayManager']->register('cashfree', $gateway);
        return;
    }

    if (function_exists('fluent_cart_api')) {
        fluent_cart_api()->registerCustomPaymentMethod('cashfree', $gateway);
    }
});
