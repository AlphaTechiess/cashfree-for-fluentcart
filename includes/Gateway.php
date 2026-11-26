<?php
namespace CashfreePaymentsFC;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Services\Subscription\SubscriptionRenewal;

class Gateway extends AbstractPaymentGateway {
    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'subscriptions', 'system_subscription'];
    private ?API $api = null;

    public function __construct() {
        parent::__construct(new Settings());
        $this->bootActions();
    }

    public function init(): void {
        parent::init();
        $this->bootActions();
    }

    private function api(): API {
        return $this->api ??= new API($this->settings);
    }

    private function bootActions(): void {
        static $booted = false;
        if ($booted) return;
        $booted = true;

        add_action('wp_ajax_cffc_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_nopriv_cffc_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_cffc_payment_status', [$this, 'paymentStatus']);
        add_action('wp_ajax_nopriv_cffc_payment_status', [$this, 'paymentStatus']);
        add_action('wp_ajax_cffc_ipn', [$this, 'handleIPN']);
        add_action('wp_ajax_nopriv_cffc_ipn', [$this, 'handleIPN']);
        add_action('fluent_cart/handle_payment_ipn_cashfree', [$this, 'handleIPN']);
        add_action('fluent_cart/subscription_cancelled', [$this, 'cancelSubscription'], 20, 1);
        add_action('fluent_cart/subscription_canceled', [$this, 'cancelSubscription'], 20, 1);
        add_action('template_redirect', [$this, 'handleReturn']);
    }

    public function meta(): array {
        $iconFile = CFFC_DIR . 'assets/icon.png';
        $iconUrl  = CFFC_URL . 'assets/icon.png' . (file_exists($iconFile) ? '?v=' . filemtime($iconFile) : '');

        return [
            'title'              => __('Cashfree Payments', 'cashfree-for-fluentcart'),
            'route'              => 'cashfree',
            'slug'               => 'cashfree',
            'description'        => $this->settings->get('description', __('Accept payments via UPI, Credit/Debit Cards, NetBanking, and Wallets using Cashfree.', 'cashfree-for-fluentcart')),
            'brand_color'        => '#6C2BD9',
            'logo'               => $iconUrl,
            'icon'               => $iconUrl,
            'status'             => $this->settings->isActive(),
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool { return in_array($feature, $this->supportedFeatures, true); }

    public function getWebhookInstructions(): array {
        return [
            'title'       => __('Webhook URL', 'cashfree-for-fluentcart'),
            'webhook_url' => esc_url_raw($this->settings->webhookUrl()),
            'description' => __('Configure this webhook URL in your Cashfree Dashboard.', 'cashfree-for-fluentcart'),
            'steps'       => [
                'title' => __('How to configure?', 'cashfree-for-fluentcart'),
                'list'  => [
                    __('In your Cashfree Merchant Dashboard under Payment Gateway → Developers → Webhooks', 'cashfree-for-fluentcart'),
                    /* translators: %s: Cashfree API version. */
                    sprintf(__('Click "Add Webhook", paste the Webhook URL above, and select API version %s', 'cashfree-for-fluentcart'), API::API_VERSION),
                    __('Enable events: success payment, failed payment', 'cashfree-for-fluentcart'),
                    __('Also add this Webhook URL under Subscriptions → Webhooks with events: subscription status changed, subscription payment success, subscription payment failed, subscription refund status, subscription payment cancelled. If you are selling a subscriptions, enable these events too.', 'cashfree-for-fluentcart'),
                ],
            ],
        ];
    }

    public function fields(): array {
        $fields = [];
        if (method_exists($this, 'renderStoreModeNotice') && ($notice = $this->renderStoreModeNotice())) {
            $fields['notice'] = ['value' => $notice, 'label' => __('Store Mode notice', 'cashfree-for-fluentcart'), 'type' => 'notice'];
        }

        $fields['description'] = [
            'type'        => 'textarea',
            'label'       => __('Description', 'cashfree-for-fluentcart'),
            'description' => __('Payment method description displayed to the customer at checkout.', 'cashfree-for-fluentcart'),
            'value'       => __('Pay securely using UPI, Cards, NetBanking, and Wallets via Cashfree.', 'cashfree-for-fluentcart'),
        ];

        $fields['payment_mode'] = [
            'type'   => 'tabs',
            'value'  => 'live',
            'schema' => [
                [
                    'type'   => 'tab',
                    'label'  => __('Live credentials', 'cashfree-for-fluentcart'),
                    'value'  => 'live',
                    'schema' => [
                        'live_app_id'     => ['type' => 'text', 'label' => __('Live App ID', 'cashfree-for-fluentcart'), 'placeholder' => __('Your live Cashfree App ID', 'cashfree-for-fluentcart')],
                        'live_secret_key' => ['type' => 'password', 'label' => __('Live Secret Key', 'cashfree-for-fluentcart'), 'placeholder' => __('Your live secret key', 'cashfree-for-fluentcart')],
                    ],
                ],
                [
                    'type'   => 'tab',
                    'label'  => __('Test credentials', 'cashfree-for-fluentcart'),
                    'value'  => 'test',
                    'schema' => [
                        'test_app_id'     => ['type' => 'text', 'label' => __('Test App ID', 'cashfree-for-fluentcart'), 'placeholder' => __('Your sandbox App ID', 'cashfree-for-fluentcart')],
                        'test_secret_key' => ['type' => 'password', 'label' => __('Test Secret Key', 'cashfree-for-fluentcart'), 'placeholder' => __('Your sandbox secret key', 'cashfree-for-fluentcart')],
                    ],
                ],
            ],
        ];

        $fields['webhook_info'] = [
            'value' => $this->getWebhookInstructions(),
            'label' => __('Webhook Configuration', 'cashfree-for-fluentcart'),
            'type'  => 'html_attr',
        ];

        return $fields;
    }

    public function getLocalizeData(): array {
        $upiFile = CFFC_DIR . 'assets/upi.svg';
        $upiUrl  = CFFC_URL . 'assets/upi.svg' . (file_exists($upiFile) ? '?v=' . filemtime($upiFile) : '');

        return [
            'fct_cashfree_data' => [
                'app_id'        => $this->settings->appId(),
                'mode'          => $this->settings->isTest() ? 'sandbox' : 'production',
                'checkout_type' => 'modal',
                'ajax_url'      => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('cffc_confirm'),
                'upi_icon'      => esc_url_raw($upiUrl),
                'translations'  => [
                    'Cards'                       => __('Cards', 'cashfree-for-fluentcart'),
                    'UPI'                         => __('UPI', 'cashfree-for-fluentcart'),
                    'Net Banking'                 => __('Net Banking', 'cashfree-for-fluentcart'),
                    'Wallets'                     => __('Wallets', 'cashfree-for-fluentcart'),
                    'Available payment methods'   => __('Available payment methods', 'cashfree-for-fluentcart'),
                    'Place Order'                 => __('Place Order', 'cashfree-for-fluentcart'),
                    'Processing payment...'       => __('Processing payment...', 'cashfree-for-fluentcart'),
                    'Payment Modal is opening...' => __('Opening Cashfree Payments, please complete payment...', 'cashfree-for-fluentcart'),
                ],
            ],
        ];
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array {
        return [
            ['handle' => 'cashfree-js', 'src' => 'https://sdk.cashfree.com/js/v3/cashfree.js'],
            ['handle' => 'fluent-cart-cashfree-custom', 'src' => CFFC_URL . 'assets/checkout.min.js', 'version' => CFFC_VER, 'deps' => ['cashfree-js']],
        ];
    }

    public function getEnqueueStyleSrc(): array { return []; }

    public function getOrderInfo($data = null) {
        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'cashfree-for-fluentcart'),
            'data'         => [],
            'payment_args' => [
                'app_id'        => $this->settings->appId(),
                'mode'          => $this->settings->isTest() ? 'sandbox' : 'production',
                'checkout_type' => 'modal',
            ],
        ], 200);
    }

    private function failed(string $msg): array {
        return ['status' => 'failed', 'message' => $msg];
    }

    private function amount($minorUnits): float {
        return round((float)Helper::toDecimalWithoutComma((int)$minorUnits), 2);
    }

    private function stableId(string $prefix, string $seed, int $maxLen = 45): string {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $seed);
        $id    = $prefix . $clean;
        if (strlen($id) > $maxLen) {
            $id = $prefix . substr(hash('sha256', $seed), 0, $maxLen - strlen($prefix));
        }
        return $id;
    }

    private function idempotencyKey(string $scope, string $seed): string {
        return 'cffc_' . substr(hash('sha256', $scope . '|' . $seed), 0, 40);
    }

    private function getReceiptUrl($transaction): string {
        return ($transaction && method_exists($transaction, 'getReceiptPageUrl')) ? (string)$transaction->getReceiptPageUrl() : home_url('/');
    }

    private function extractPhoneFromRecord($record): string {
        if (!$record) return '';

        if (is_array($record)) {
            $meta = $record['meta'] ?? null;
            if (is_string($meta)) $meta = json_decode($meta, true);
            $candidates = [
                $record['phone'] ?? '',
                is_array($meta) ? ($meta['other_data']['phone'] ?? ($meta['phone'] ?? ($meta['billing_phone'] ?? ''))) : '',
            ];
            foreach ($candidates as $val) {
                $val = trim((string)$val);
                if ($val !== '') return $val;
            }
            return '';
        }

        if (is_object($record)) {
            try {
                $direct = trim((string)$record->phone);
                if ($direct !== '') return $direct;
            } catch (\Throwable $e) {}

            try {
                $meta = $record->meta ?? null;
                if (is_string($meta)) $meta = json_decode($meta, true);
                elseif (is_object($meta)) $meta = (array)$meta;
                if (is_array($meta)) {
                    $other = is_object($meta['other_data'] ?? null) ? (array)$meta['other_data'] : ($meta['other_data'] ?? []);
                    foreach ([$other['phone'] ?? '', $meta['phone'] ?? '', $meta['billing_phone'] ?? ''] as $val) {
                        $val = trim((string)$val);
                        if ($val !== '') return $val;
                    }
                }
            } catch (\Throwable $e) {}
        }

        return '';
    }

    private function resolveCustomerDetails($order): array {
        $order->loadMissing('customer', 'billing_address', 'shipping_address');
        $customer = $order->customer;
        $billing  = $order->billing_address ?: $order->shipping_address;

        $name = trim((string)($billing->name ?? ''));
        if (!$name && $customer) $name = trim((string)($customer->first_name ?? '') . ' ' . (string)($customer->last_name ?? ''));
        $email = sanitize_email((string)($customer->email ?? ($order->email ?? '')));

        $rawPhoneInput = '';
        $sources       = [$order->billing_address, $order->shipping_address, $customer, $order];
        if ($customer) {
            try {
                if (!empty($customer->primary_billing_address)) $sources[] = $customer->primary_billing_address;
                if (!empty($customer->primary_shipping_address)) $sources[] = $customer->primary_shipping_address;
            } catch (\Throwable $e) {}
        }
        foreach ($sources as $src) {
            $rawPhoneInput = $this->extractPhoneFromRecord($src);
            if ($rawPhoneInput !== '') break;
        }
        if ($rawPhoneInput === '') {
            $userId = (int)($customer->user_id ?? ($order->user_id ?? 0));
            if ($userId > 0) {
                $rawPhoneInput = (string)(get_user_meta($userId, 'billing_phone', true) ?: get_user_meta($userId, 'phone', true));
            }
        }

        $rawPh = preg_replace('/[^0-9]/', '', $rawPhoneInput);
        if (strlen($rawPh) === 12 && substr($rawPh, 0, 2) === '91') $rawPh = substr($rawPh, 2);
        elseif (strlen($rawPh) === 11 && $rawPh[0] === '0') $rawPh = substr($rawPh, 1);

        $custSeed = (string)($customer->id ?? ($email ?: ($order->id ?? uniqid())));
        return [
            'customer_id' => $this->stableId('fcc_', $custSeed, 40),
            'name'        => substr(sanitize_text_field($name), 0, 100),
            'email'       => $email,
            'phone'       => strlen($rawPh) >= 10 ? substr($rawPh, -10) : '',
        ];
    }

    private function resolveNotifyUrl(): string {
        $url = $this->settings->webhookUrl();
        return (strpos($url, 'https://') === 0) ? $url : '';
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance) {
        if (!$this->settings->isActive()) return $this->failed(__('Cashfree Payments is not active.', 'cashfree-for-fluentcart'));
        if (!$this->settings->appId() || !$this->settings->secretKey()) {
            return $this->failed(__('Cashfree App ID or Secret Key is not configured.', 'cashfree-for-fluentcart'));
        }
        if ($paymentInstance->subscription && (!method_exists($this, 'shouldChargeSubscriptionAsOneTime') || !$this->shouldChargeSubscriptionAsOneTime($paymentInstance))) {
            return $this->createSubscriptionCheckout($paymentInstance);
        }
        return $this->createOneTimeCheckout($paymentInstance);
    }

    private function createOneTimeCheckout(PaymentInstance $paymentInstance) {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        if (!$order || !$transaction) return $this->failed(__('Unable to initialize the Cashfree transaction.', 'cashfree-for-fluentcart'));

        $currency = strtoupper(trim((string)($transaction->currency ?: 'INR')));
        $amount   = $this->amount($transaction->total);
        $minAmt   = ($currency === 'INR') ? 1.00 : 0.50;
        if ($amount < $minAmt) {
            return $this->failed(/* translators: 1: currency code (e.g. INR), 2: minimum order amount. */
            sprintf(__('Cashfree requires an order amount of at least %1$s %2$s.', 'cashfree-for-fluentcart'), $currency, number_format($minAmt, 2)));
        }

        $existingOrderId   = (string)($transaction->meta['cashfree_order_id'] ?? '');
        $existingSessionId = (string)($transaction->meta['cashfree_payment_session_id'] ?? '');
        if ($existingOrderId && $existingSessionId) {
            $existingOrder = $this->api()->getOrder($existingOrderId);
            if (!is_wp_error($existingOrder)) {
                $status = strtoupper((string)($existingOrder['order_status'] ?? ''));
                if ($status === 'ACTIVE' && !empty($existingOrder['payment_session_id'])) {
                    return $this->checkoutResponse($transaction, 'payment', [
                        'payment_session_id' => (string)$existingOrder['payment_session_id'],
                        'cashfree_order_id'  => $existingOrderId,
                    ]);
                }
                if ($status === 'PAID') {
                    $this->verifyAndSucceed($transaction, $order);
                    return ['status' => 'success', 'actionName' => 'redirect', 'redirect_to' => $this->getReceiptUrl($transaction)];
                }
            }
        }

        $cust = $this->resolveCustomerDetails($order);
        if (strlen($cust['phone']) < 10) {
            return $this->failed(__('A valid phone number (at least 10 digits) is required for Cashfree checkout.', 'cashfree-for-fluentcart'));
        }

        $attempt         = (int)($transaction->meta['cashfree_attempts'] ?? 0) + 1;
        $cashfreeOrderId = $this->stableId('fc_', $attempt > 1 ? ($transaction->uuid . '_' . $attempt) : (string)$transaction->uuid, 45);
        $orderMeta       = ['return_url' => add_query_arg(['cffc_tx' => (string)$transaction->uuid], $this->getReceiptUrl($transaction))];
        if ($notifyUrl = $this->resolveNotifyUrl()) $orderMeta['notify_url'] = $notifyUrl;

        $customerDetails = ['customer_id' => $cust['customer_id'], 'customer_phone' => $cust['phone']];
        if ($cust['name']) $customerDetails['customer_name'] = $cust['name'];
        if ($cust['email']) $customerDetails['customer_email'] = $cust['email'];

        $payload = [
            'order_id'         => $cashfreeOrderId,
            'order_amount'     => $amount,
            'order_currency'   => $currency,
            'customer_details' => $customerDetails,
            'order_note'       => 'Order #' . ($order->id ?? ''),
            'order_meta'       => $orderMeta,
            'order_tags'       => [
                'Method'                  => 'FluentCart',
                'fluent_transaction_uuid' => (string)$transaction->uuid,
                'fluent_order_id'         => (string)$order->id,
            ],
        ];

        $result = $this->api()->createOrder($payload, $this->idempotencyKey('order', $cashfreeOrderId));
        if (is_wp_error($result) && (int)($result->get_error_data()['status'] ?? 0) === 409) {
            $cashfreeOrderId     = $this->stableId('fc_', $transaction->uuid . '_' . time(), 45);
            $payload['order_id'] = $cashfreeOrderId;
            $result              = $this->api()->createOrder($payload, $this->idempotencyKey('order', $cashfreeOrderId));
        }

        if (is_wp_error($result)) return $this->failed($result->get_error_message());
        $sessionId = (string)($result['payment_session_id'] ?? '');
        if (!$sessionId) return $this->failed(__('Cashfree did not return a payment session ID.', 'cashfree-for-fluentcart'));

        $meta                                = is_array($transaction->meta) ? $transaction->meta : [];
        $meta['cashfree_order_id']           = $cashfreeOrderId;
        $meta['cashfree_payment_session_id'] = $sessionId;
        $meta['cashfree_attempts']           = $attempt;
        $transaction->payment_mode           = $this->settings->getMode();
        $transaction->meta                   = $meta;
        $transaction->save();

        set_transient('cffc_tx_order_' . md5($cashfreeOrderId), (string)$transaction->uuid, 14 * DAY_IN_SECONDS);

        return $this->checkoutResponse($transaction, 'payment', [
            'payment_session_id' => $sessionId,
            'cashfree_order_id'  => $cashfreeOrderId,
        ]);
    }

    private function mapInterval(string $interval): ?array {
        $map = [
            'daily'     => ['type' => 'DAY', 'count' => 1],
            'weekly'    => ['type' => 'WEEK', 'count' => 1],
            'monthly'   => ['type' => 'MONTH', 'count' => 1],
            'quarterly' => ['type' => 'MONTH', 'count' => 3],
            'yearly'    => ['type' => 'YEAR', 'count' => 1],
        ];
        return $map[strtolower(trim($interval))] ?? null;
    }

    private function ensurePlan(string $planId, array $interval, float $recurringAmount, string $currency, $order) {
        $existing = $this->api()->getPlan($planId);
        if (!is_wp_error($existing) && !empty($existing['plan_id'])) return $existing;

        return $this->api()->createPlan([
            'plan_id'               => $planId,
            'plan_name'             => substr('FC Plan ' . $currency . ' ' . number_format($recurringAmount, 2, '.', ''), 0, 40),
            'plan_type'             => 'PERIODIC',
            'plan_currency'         => $currency,
            'plan_recurring_amount' => $recurringAmount,
            'plan_max_amount'       => $recurringAmount,
            'plan_interval_type'    => $interval['type'],
            'plan_intervals'        => $interval['count'],
            'plan_note'             => 'Order #' . ($order->id ?? ''),
        ], $this->idempotencyKey('plan', $planId));
    }

    private function createSubscriptionCheckout(PaymentInstance $paymentInstance) {
        $order        = $paymentInstance->order;
        $transaction  = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        if (!$order || !$transaction || !$subscription) {
            return $this->failed(__('Unable to initialize the Cashfree subscription payment.', 'cashfree-for-fluentcart'));
        }

        $currency        = strtoupper(trim((string)($transaction->currency ?: 'INR')));
        $initialAmount   = $this->amount($transaction->total);
        $recurringAmount = $this->amount($subscription->recurring_total);
        $minAmt          = ($currency === 'INR') ? 1.00 : 0.50;
        if ($recurringAmount < $minAmt) {
            return $this->failed(/* translators: 1: currency code (e.g. INR), 2: minimum recurring subscription amount. */
            sprintf(__('Cashfree recurring subscription amount must be at least %1$s %2$s.', 'cashfree-for-fluentcart'), $currency, number_format($minAmt, 2)));
        }

        $interval = $this->mapInterval((string)$subscription->billing_interval);
        if (!$interval) return $this->failed(__('This subscription billing interval is not supported by Cashfree.', 'cashfree-for-fluentcart'));

        $cust = $this->resolveCustomerDetails($order);
        if (strlen($cust['phone']) < 10) {
            return $this->failed(__('A valid customer phone number is required for Cashfree subscriptions.', 'cashfree-for-fluentcart'));
        }

        $cfSubId = (string)($subscription->vendor_subscription_id ?: ($transaction->meta['cashfree_subscription_id'] ?? ''));
        if (!$cfSubId) $cfSubId = $this->stableId('fcsub_', $subscription->id . '_' . $transaction->uuid, 45);

        $planId = $this->stableId('fcplan_', $recurringAmount . '|' . $interval['count'] . '|' . $interval['type'] . '|' . $currency . '|' . $this->settings->getMode(), 40);
        $plan   = $this->ensurePlan($planId, $interval, $recurringAmount, $currency, $order);
        if (is_wp_error($plan)) return $this->failed($plan->get_error_message());

        $subMeta = [
            'return_url'           => add_query_arg(['cffc_tx' => (string)$transaction->uuid], $this->getReceiptUrl($transaction)),
            'notification_channel' => ['EMAIL', 'SMS'],
        ];
        if ($notifyUrl = $this->resolveNotifyUrl()) $subMeta['notify_url'] = $notifyUrl;

        $subCust = ['customer_phone' => $cust['phone']];
        if ($cust['name']) $subCust['customer_name'] = $cust['name'];
        if ($cust['email']) $subCust['customer_email'] = $cust['email'];

        $payload = [
            'subscription_id'       => $cfSubId,
            'customer_details'      => $subCust,
            'plan_details'          => ['plan_id' => $planId],
            'authorization_details' => [
                'authorization_amount'        => max($minAmt, $initialAmount),
                'authorization_amount_refund' => ($initialAmount < $minAmt),
                'payment_methods'             => ($currency === 'INR') ? ['upi', 'enach', 'card'] : ['card'],
            ],
            'subscription_meta'     => $subMeta,
            'subscription_note'     => 'Order #' . ($order->id ?? ''),
            'subscription_tags'     => [
                'Method'                  => 'FluentCart',
                'fluent_transaction_uuid' => (string)$transaction->uuid,
                'fluent_subscription_id'  => (string)$subscription->id,
                'fluent_order_id'         => (string)$order->id,
            ],
        ];

        $res = $this->api()->createSubscription($payload, $this->idempotencyKey('sub', $cfSubId));
        if (is_wp_error($res)) return $this->failed($res->get_error_message());

        $subSessionId = (string)($res['subscription_session_id'] ?? '');
        if (!$subSessionId) return $this->failed(__('Cashfree did not return a subscription session ID.', 'cashfree-for-fluentcart'));

        $subscription->vendor_subscription_id = $cfSubId;
        $subscription->collection_method      = 'system';
        $subscription->current_payment_method = 'cashfree';
        $subscription->save();

        $meta                                     = is_array($transaction->meta) ? $transaction->meta : [];
        $meta['cashfree_subscription_id']         = $cfSubId;
        $meta['cashfree_subscription_session_id'] = $subSessionId;
        $transaction->payment_mode                = $this->settings->getMode();
        $transaction->meta                        = $meta;
        $transaction->save();

        set_transient('cffc_tx_sub_' . md5($cfSubId), (string)$transaction->uuid, 14 * DAY_IN_SECONDS);

        return $this->checkoutResponse($transaction, 'subscription', [
            'subscription_session_id' => $subSessionId,
            'cashfree_subscription_id' => $cfSubId,
        ]);
    }

    private function checkoutResponse($transaction, string $type, array $extra): array {
        return [
            'status'     => 'success',
            'nextAction' => 'cashfree',
            'actionName' => 'custom',
            'message'    => __('Opening Cashfree Payments…', 'cashfree-for-fluentcart'),
            'response'   => array_merge([
                'type'             => $type,
                'mode'             => $this->settings->isTest() ? 'sandbox' : 'production',
                'transaction_uuid' => (string)$transaction->uuid,
                'ajax_url'         => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('cffc_confirm'),
            ], $extra),
        ];
    }

    private function extractPaymentMethodInfo(array $paymentData): array {
        $pm = is_array($paymentData['payment_method'] ?? null) ? $paymentData['payment_method'] : [];
        $type = 'cashfree';
        $brand = '';
        $last4 = '';

        if (!empty($pm['upi'])) {
            $type = 'upi';
        } elseif (!empty($pm['card']) && is_array($pm['card'])) {
            $type  = 'card';
            $brand = trim((string)($pm['card']['card_network'] ?? ($pm['card']['card_type'] ?? '')));
            $num   = preg_replace('/[^0-9]/', '', (string)($pm['card']['card_number'] ?? ''));
            $last4 = strlen($num) >= 4 ? substr($num, -4) : '';
        } elseif (!empty($pm['netbanking'])) {
            $type = 'net_banking';
        } elseif (!empty($pm['app'])) {
            $type = 'wallet';
        } elseif (!empty($paymentData['payment_group'])) {
            $type = strtolower(trim((string)$paymentData['payment_group']));
        }

        return ['type' => $type, 'brand' => $brand, 'last4' => $last4];
    }

    private function markTransactionSucceeded($transaction, string $vendorChargeId = '', $order = null, array $paymentData = []): void {
        if (!$transaction || $transaction->status === 'succeeded') return;

        $pmInfo = $this->extractPaymentMethodInfo($paymentData);
        $transaction->status              = 'succeeded';
        $transaction->payment_method      = 'cashfree';
        $transaction->payment_method_type = $pmInfo['type'];
        if ($pmInfo['brand'] !== '') $transaction->card_brand = $pmInfo['brand'];
        if ($pmInfo['last4'] !== '') $transaction->card_last_4 = $pmInfo['last4'];
        if ($vendorChargeId !== '') $transaction->vendor_charge_id = $vendorChargeId;

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        if ($vendorChargeId !== '') $meta['cashfree_payment_id'] = $vendorChargeId;
        $meta['cashfree_payment_method_type'] = $pmInfo['type'];
        $transaction->meta                    = $meta;
        $transaction->save();

        $order = $order ?: Order::find($transaction->order_id);
        if ($order) {
            try { (new StatusHelper($order))->syncOrderStatuses($transaction); } catch (\Throwable $e) {}
        }
    }

    private function verifyAndSucceed($transaction, $order = null): bool {
        if (!$transaction) return false;
        if ($transaction->status === 'succeeded') return true;

        // Zero-trust: ONLY use stored transaction metadata (never untrusted query parameters)
        $order   = $order ?: Order::find($transaction->order_id);
        $orderId = (string)($transaction->meta['cashfree_order_id'] ?? '');
        $subId   = (string)($transaction->meta['cashfree_subscription_id'] ?? '');

        if ($orderId) {
            $expected = $this->amount($transaction->total);
            $payments = $this->api()->getPayments($orderId);
            if (!is_wp_error($payments) && is_array($payments)) {
                $list = isset($payments['cf_payment_id']) ? [$payments] : $payments;
                foreach ($list as $p) {
                    if (!is_array($p)) continue;
                    $paidAmt = round((float)($p['payment_amount'] ?? 0), 2);
                    if (strtoupper((string)($p['payment_status'] ?? '')) === 'SUCCESS' && abs($paidAmt - $expected) <= 0.01) {
                        $this->markTransactionSucceeded($transaction, (string)($p['cf_payment_id'] ?? ''), $order, $p);
                        return true;
                    }
                }
            }
        } elseif ($subId) {
            $sub = $this->api()->getSubscription($subId);
            if (!is_wp_error($sub) && is_array($sub)) {
                $status = strtoupper((string)($sub['subscription_status'] ?? ''));
                $auth   = strtoupper((string)($sub['authorization_details']['authorization_status'] ?? ''));
                if (in_array($status, ['ACTIVE', 'INITIALIZED'], true) && ($auth === 'SUCCESS' || $status === 'ACTIVE')) {
                    $chargeId = (string)($sub['authorization_details']['cf_payment_id'] ?? ($sub['authorization_details']['payment_id'] ?? ''));
                    $this->markTransactionSucceeded($transaction, $chargeId, $order, (array)($sub['authorization_details'] ?? []));
                    $this->syncLocalSubscriptionStatus($subId, 'active');
                    return true;
                }
            }
        }
        return false;
    }

    public function confirmPayment() {
        if (!check_ajax_referer('cffc_confirm', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed. Please refresh and try again.', 'cashfree-for-fluentcart')], 403);
        }

        $txUuid = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
        $tx     = $txUuid ? OrderTransaction::query()->where('uuid', $txUuid)->first() : null;
        if (!$tx) wp_send_json_error(['message' => __('Transaction not found.', 'cashfree-for-fluentcart')], 404);

        if ($this->verifyAndSucceed($tx)) {
            wp_send_json_success(['redirect_url' => $this->getReceiptUrl($tx)]);
        }
        wp_send_json_error(['message' => __('Payment is still processing or has not been confirmed yet.', 'cashfree-for-fluentcart')], 400);
    }

    public function paymentStatus() {
        if (!check_ajax_referer('cffc_confirm', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed.', 'cashfree-for-fluentcart')], 403);
        }

        $txUuid = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
        $tx     = $txUuid ? OrderTransaction::query()->where('uuid', $txUuid)->first() : null;
        if (!$tx) wp_send_json_error(['message' => __('Transaction not found.', 'cashfree-for-fluentcart')], 404);

        if ($this->verifyAndSucceed($tx)) {
            wp_send_json_success(['transaction_status' => 'succeeded', 'redirect_url' => $this->getReceiptUrl($tx)]);
        }
        wp_send_json_success(['transaction_status' => (string)$tx->status]);
    }

    public function handleReturn() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public Cashfree return URL; payment status is verified server-side via Cashfree API.
        if (empty($_GET['cffc_tx'])) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public Cashfree return URL; payment status is verified server-side via Cashfree API.
        $txUuid = sanitize_text_field(wp_unslash($_GET['cffc_tx']));
        $tx     = OrderTransaction::query()->where('uuid', $txUuid)->first();
        if ($tx && $tx->status !== 'succeeded') {
            $this->verifyAndSucceed($tx);
        }
    }

    public function processRefund($transaction, $amount, $args = []) {
        if (!$transaction) return new \WP_Error('cffc_refund', __('Transaction not found.', 'cashfree-for-fluentcart'));

        $requestedAmount = $this->amount($amount);
        if ($requestedAmount <= 0) return new \WP_Error('cffc_refund', __('Refund amount must be greater than zero.', 'cashfree-for-fluentcart'));

        $orderId        = (string)($transaction->meta['cashfree_order_id'] ?? '');
        $paymentId      = (string)($transaction->meta['cashfree_payment_id'] ?? ($transaction->vendor_charge_id ?? ''));
        $subscriptionId = (string)($transaction->meta['cashfree_subscription_id'] ?? '');
        if (!$orderId && !$subscriptionId) {
            return new \WP_Error('cffc_refund', __('Cashfree order or subscription information is missing.', 'cashfree-for-fluentcart'));
        }

        $lockKey = 'cffc_refund_lock_' . md5((string)$transaction->uuid . '|' . number_format($requestedAmount, 2, '.', ''));
        $hasLock = add_option($lockKey, time(), '', 'no');
        if (!$hasLock) {
            if ((time() - (int)get_option($lockKey, 0)) > 120) {
                delete_option($lockKey);
                $hasLock = add_option($lockKey, time(), '', 'no');
            } else {
                return new \WP_Error('cffc_refund_busy', __('A refund for this transaction is already in progress. Please wait a moment.', 'cashfree-for-fluentcart'));
            }
        }

        try {
            $note = sanitize_text_field(substr((string)($args['description'] ?? ($args['note'] ?? 'Refund from FluentCart')), 0, 100));
            if (strlen($note) < 3) $note = 'Refund from FluentCart';

            if ($orderId) {
                $orderRes = $this->api()->getOrder($orderId);
                if (is_wp_error($orderRes)) return $orderRes;
                if (strtoupper((string)($orderRes['order_status'] ?? '')) !== 'PAID') {
                    return new \WP_Error('cffc_refund', __('This Cashfree order is not eligible for refund.', 'cashfree-for-fluentcart'));
                }
                if ($requestedAmount > round((float)($orderRes['order_amount'] ?? 0), 2) + 0.01) {
                    return new \WP_Error('cffc_refund', __('Refund amount exceeds the Cashfree order amount.', 'cashfree-for-fluentcart'));
                }
            }

            $refundId = 'fcr_' . substr(hash('sha256', $transaction->uuid . '_' . microtime(true) . '_' . wp_rand()), 0, 32);
            if ($subscriptionId && $paymentId) {
                $result = $this->api()->createSubscriptionRefund($subscriptionId, [
                    'subscription_id' => $subscriptionId,
                    'payment_id'      => $paymentId,
                    'refund_id'       => $refundId,
                    'refund_amount'   => $requestedAmount,
                    'refund_note'     => $note,
                    'refund_speed'    => 'STANDARD',
                ], $this->idempotencyKey('sub-refund', $refundId));
            } elseif ($orderId) {
                $result = $this->api()->createRefund($orderId, [
                    'refund_amount' => $requestedAmount,
                    'refund_id'     => $refundId,
                    'refund_note'   => $note,
                    'refund_speed'  => 'STANDARD',
                ], $this->idempotencyKey('refund', $refundId));
            } else {
                return new \WP_Error('cffc_refund', __('Cashfree payment reference is missing.', 'cashfree-for-fluentcart'));
            }

            if (is_wp_error($result)) return $result;

            $remoteId = (string)($result['cf_refund_id'] ?? ($result['refund_id'] ?? ($result['subRefundId'] ?? $refundId)));
            $status   = strtolower((string)($result['refund_status'] ?? ($result['refundStatus'] ?? 'pending')));
            if (in_array($status, ['failed', 'cancelled', 'canceled'], true)) {
                return new \WP_Error('cffc_refund', __('Cashfree rejected the refund request.', 'cashfree-for-fluentcart'));
            }

            $fluentStatus = in_array($status, ['success', 'succeeded', 'completed'], true) ? 'refunded' : 'processing';
            $pmType       = (string)($transaction->payment_method_type ?: 'cashfree');

            if (!empty($args['refund_transaction_id']) && ($refundTx = OrderTransaction::find($args['refund_transaction_id']))) {
                $refundTx->status              = $fluentStatus;
                $refundTx->vendor_charge_id    = $remoteId;
                $refundTx->payment_method      = 'cashfree';
                $refundTx->payment_method_type = $pmType;
                $refundTx->save();
            }

            return $remoteId;
        } finally {
            if ($hasLock) delete_option($lockKey);
        }
    }

    public function cancelSubscription($data): void {
        $sub = is_object($data) ? $data : (is_array($data) ? ($data['subscription'] ?? null) : null);
        if (!$sub || ($sub->current_payment_method ?? '') !== 'cashfree' || empty($sub->vendor_subscription_id)) return;
        $this->api()->manageSubscription((string)$sub->vendor_subscription_id, 'CANCEL');
    }

    private function syncLocalSubscriptionStatus(string $cfSubId, string $status): void {
        if (!$cfSubId || !class_exists(Subscription::class)) return;
        $sub = Subscription::query()->where('vendor_subscription_id', $cfSubId)->first();
        if ($sub && $sub->status !== $status) {
            $sub->status = $status;
            $sub->save();
        }
    }

    private function getHeaderValue(string $name): string {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
            return trim(sanitize_text_field(wp_unslash($_SERVER[$serverKey])));
        }
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case((array)getallheaders(), CASE_LOWER);
            $key     = strtolower($name);
            if (isset($headers[$key]) && is_string($headers[$key]) && $headers[$key] !== '') {
                return trim(sanitize_text_field(wp_unslash($headers[$key])));
            }
        }
        return '';
    }

    public function verifyWebhookSignature(string $timestamp, string $raw, string $signature): bool {
        $timestamp = trim($timestamp);
        $signature = trim($signature);
        if ($timestamp === '' || $raw === '' || $signature === '' || !ctype_digit($timestamp)) return false;

        $tsSec  = strlen($timestamp) > 10 ? (int)(((int)$timestamp) / 1000) : (int)$timestamp;
        $maxAge = (int)apply_filters('cffc_webhook_max_age_seconds', 600);
        if ($maxAge > 0 && abs(time() - $tsSec) > $maxAge) return false;

        // Zero-trust: verify strictly against the ACTIVE mode's secret key only
        $secret = $this->settings->secretKey();
        if ($secret === '') return false;

        $expected = base64_encode(hash_hmac('sha256', $timestamp . $raw, $secret, true));
        return hash_equals($expected, $signature);
    }

    public function handleIPN() {
        $raw = (string)file_get_contents('php://input');
        $res = $this->processWebhookRequest($raw, $this->getHeaderValue('x-webhook-signature'), $this->getHeaderValue('x-webhook-timestamp'));
        if (is_wp_error($res)) {
            $code = (int)($res->get_error_data()['status'] ?? 400);
            wp_send_json(['status' => 'error', 'message' => $res->get_error_message()], ($code >= 400 && $code <= 599) ? $code : 400);
        }
        wp_send_json(['status' => 'ok', 'message' => $res], 200);
    }

    public function handleWebhook($request) {
        $raw  = (string)$request->get_body();
        $sig  = (string)($request->get_header('x-webhook-signature') ?: $this->getHeaderValue('x-webhook-signature'));
        $time = (string)($request->get_header('x-webhook-timestamp') ?: $this->getHeaderValue('x-webhook-timestamp'));
        $res  = $this->processWebhookRequest($raw, $sig, $time);
        if (is_wp_error($res)) {
            return new \WP_REST_Response(['status' => 'error', 'message' => $res->get_error_message()], (int)($res->get_error_data()['status'] ?? 400));
        }
        return new \WP_REST_Response(['status' => 'ok', 'message' => $res], 200);
    }

    private function syncRefundFromWebhook(array $refund, string $parentTransientKey): string {
        $refundId = sanitize_text_field((string)($refund['cf_refund_id'] ?? ($refund['refund_id'] ?? ($refund['subRefundId'] ?? ''))));
        $status   = strtoupper((string)($refund['refund_status'] ?? ($refund['refundStatus'] ?? '')));
        if (!$refundId || !in_array($status, ['SUCCESS', 'FAILED', 'CANCELLED', 'CANCELED'], true)) {
            return '';
        }

        $mapped      = ($status === 'SUCCESS') ? 'refunded' : 'failed';
        $amountMinor = (int)round(((float)($refund['refund_amount'] ?? ($refund['refundAmount'] ?? 0))) * 100);
        $refundTx    = OrderTransaction::query()->where('vendor_charge_id', $refundId)->first();
        if ($refundTx) {
            if ($refundTx->status !== 'refunded') $refundTx->status = $mapped;
            if ($amountMinor > 0 && empty($refundTx->total)) $refundTx->total = $amountMinor;
            $refundTx->save();
            return 'Refund updated to ' . $mapped . '.';
        }

        if ($parentTransientKey && ($txUuid = (string)get_transient($parentTransientKey))) {
            $parent = OrderTransaction::query()->where('uuid', $txUuid)->first();
            if ($parent) {
                Refund::createOrRecordRefund([
                    'vendor_charge_id'    => $refundId,
                    'payment_method'      => 'cashfree',
                    'payment_method_type' => (string)($parent->payment_method_type ?: 'cashfree'),
                    'payment_mode'        => $parent->payment_mode ?? $this->settings->getMode(),
                    'status'              => $mapped,
                    'total'               => $amountMinor,
                ], $parent);
                return 'Refund recorded as ' . $mapped . '.';
            }
        }

        return '';
    }

    private function processWebhookRequest(string $raw, string $signature, string $timestamp) {
        if ($raw === '' || $signature === '' || $timestamp === '') {
            return new \WP_Error('cffc_webhook', 'Missing Cashfree webhook signature or timestamp headers.', ['status' => 400]);
        }
        if (!$this->verifyWebhookSignature($timestamp, $raw, $signature)) {
            return new \WP_Error('cffc_webhook_signature', 'Invalid Cashfree webhook signature.', ['status' => 401]);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new \WP_Error('cffc_webhook_json', 'Invalid Cashfree webhook JSON body.', ['status' => 400]);
        }

        $dedupeKey = 'cffc_evt_' . md5($this->settings->getMode() . '|' . $timestamp . '|' . $signature);
        if (get_transient($dedupeKey)) return 'Duplicate webhook event already processed.';

        $event = strtoupper(str_replace([' ', '-'], '_', trim((string)($payload['type'] ?? ($payload['event'] ?? '')))));
        $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // Ignore orders explicitly tagged for another platform
        $tags = is_array($data['order']['order_tags'] ?? null) ? $data['order']['order_tags'] : [];
        if (isset($tags['Method']) && strtolower((string)$tags['Method']) !== 'fluentcart') {
            set_transient($dedupeKey, 1, 2 * DAY_IN_SECONDS);
            return 'Ignored: event belongs to another method.';
        }

        $msg = 'Webhook processed.';
        if ($event === 'PAYMENT_SUCCESS_WEBHOOK' || $event === 'ORDER_PAID') {
            $cfOrderId = sanitize_text_field((string)($data['order']['order_id'] ?? ''));
            $txUuid    = sanitize_text_field((string)($tags['fluent_transaction_uuid'] ?? ($cfOrderId ? get_transient('cffc_tx_order_' . md5($cfOrderId)) : '')));
            $tx        = $txUuid ? OrderTransaction::query()->where('uuid', $txUuid)->first() : null;
            if (!$tx) {
                set_transient($dedupeKey, 1, 2 * DAY_IN_SECONDS);
                return 'Ignored: matching FluentCart transaction not found.';
            }
            $payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
            $this->markTransactionSucceeded($tx, (string)($payment['cf_payment_id'] ?? ''), null, $payment);
            $msg = 'Payment succeeded and FluentCart order updated.';
        } elseif ($event === 'PAYMENT_FAILED_WEBHOOK') {
            $cfOrderId = sanitize_text_field((string)($data['order']['order_id'] ?? ''));
            $txUuid    = sanitize_text_field((string)($tags['fluent_transaction_uuid'] ?? ($cfOrderId ? get_transient('cffc_tx_order_' . md5($cfOrderId)) : '')));
            $tx        = $txUuid ? OrderTransaction::query()->where('uuid', $txUuid)->first() : null;
            if ($tx && $tx->status !== 'succeeded') {
                $tx->status = 'failed';
                $tx->save();
                if ($order = Order::find($tx->order_id)) {
                    try { (new StatusHelper($order))->syncOrderStatuses($tx); } catch (\Throwable $e) {}
                }
                $msg = 'Payment marked as failed.';
            }
        } elseif ($event === 'REFUND_STATUS_WEBHOOK') {
            $refund    = is_array($data['refund'] ?? null) ? $data['refund'] : [];
            $cfOrderId = sanitize_text_field((string)($refund['order_id'] ?? ''));
            $parentKey = $cfOrderId ? 'cffc_tx_order_' . md5($cfOrderId) : '';
            $msg       = $this->syncRefundFromWebhook($refund, $parentKey) ?: $msg;
        } elseif (strpos($event, 'SUBSCRIPTION_') === 0) {
            $subDetails = is_array($data['subscription_details'] ?? null) ? $data['subscription_details'] : $data;
            $cfSubId    = sanitize_text_field((string)($subDetails['subscription_id'] ?? ''));
            if ($cfSubId) {
                if ($event === 'SUBSCRIPTION_STATUS_CHANGED') {
                    $st = strtoupper((string)($subDetails['subscription_status'] ?? ''));
                    if ($st === 'ACTIVE') $this->syncLocalSubscriptionStatus($cfSubId, 'active');
                    elseif (in_array($st, ['CANCELLED', 'CUSTOMER_CANCELLED', 'BANK_APPROVAL_FAILED'], true)) $this->syncLocalSubscriptionStatus($cfSubId, 'canceled');
                    elseif ($st === 'ON_HOLD') $this->syncLocalSubscriptionStatus($cfSubId, 'failing');
                    elseif ($st === 'COMPLETED') $this->syncLocalSubscriptionStatus($cfSubId, 'completed');
                    $msg = 'Subscription status synced.';
                } elseif ($event === 'SUBSCRIPTION_PAYMENT_SUCCESS') {
                    if ($txUuid = (string)get_transient('cffc_tx_sub_' . md5($cfSubId))) {
                        $tx = OrderTransaction::query()->where('uuid', $txUuid)->first();
                        if ($tx && $tx->status !== 'succeeded') {
                            $this->markTransactionSucceeded($tx, (string)($data['cf_payment_id'] ?? ''), null, $data);
                        }
                    }
                    $this->syncLocalSubscriptionStatus($cfSubId, 'active');
                    $msg = 'Subscription payment synced.';
                } elseif (in_array($event, ['SUBSCRIPTION_PAYMENT_FAILED', 'SUBSCRIPTION_PAYMENT_CANCELLED'], true)) {
                    if ($txUuid = (string)get_transient('cffc_tx_sub_' . md5($cfSubId))) {
                        $tx = OrderTransaction::query()->where('uuid', $txUuid)->first();
                        if ($tx && $tx->status !== 'succeeded') {
                            $tx->status = 'failed';
                            $tx->save();
                            if ($order = Order::find($tx->order_id)) {
                                try { (new StatusHelper($order))->syncOrderStatuses($tx); } catch (\Throwable $e) {}
                            }
                        }
                    }
                    $this->syncLocalSubscriptionStatus($cfSubId, 'failing');
                    $msg = 'Subscription payment failure synced.';
                } elseif (in_array($event, ['SUBSCRIPTION_REFUND_STATUS', 'SUBSCRIPTION_REFUND_STATUS_WEBHOOK'], true)) {
                    $refund = is_array($data['refund'] ?? null) ? $data['refund'] : $data;
                    $msg    = $this->syncRefundFromWebhook($refund, 'cffc_tx_sub_' . md5($cfSubId)) ?: $msg;
                }
            }
        }

        set_transient($dedupeKey, 1, 2 * DAY_IN_SECONDS);
        return $msg;
    }
}
