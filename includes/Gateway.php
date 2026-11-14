<?php
namespace CashfreePaymentsFC;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Refund;

class Gateway extends AbstractPaymentGateway {
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions',
        'system_subscription',
    ];

    public function __construct() {
        parent::__construct(new Settings());
        $this->bootActions();
    }

    public function init(): void {
        parent::init();
        $this->bootActions();
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
        return [
            'title'              => __('Cashfree Payments', 'cashfree-fluentcart'),
            'route'              => 'cashfree',
            'slug'               => 'cashfree',
            'description'        => $this->settings->get('description', __('Accept payments via UPI, Credit/Debit Cards, NetBanking, and Wallets using Cashfree.', 'cashfree-fluentcart')),
            'brand_color'        => '#6C2BD9',
            'logo'               => CFFC_URL . 'assets/icon.png',
            'icon'               => CFFC_URL . 'assets/icon.png',
            'status'             => $this->settings->isActive(),
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool {
        return in_array($feature, $this->supportedFeatures, true);
    }

    public function getWebhookInstructions(): array {
        $webhookUrl = site_url('?fluent-cart=fct_payment_listener_ipn&method=cashfree');

        return [
            'title'       => __('Webhook URL', 'cashfree-fluentcart'),
            'webhook_url' => esc_url($webhookUrl),
            'description' => __('Configure this webhook URL in your Cashfree Dashboard.', 'cashfree-fluentcart'),
            'steps'       => [
                'title' => __('How to configure?', 'cashfree-fluentcart'),
                'list'  => [
                    __('In your Cashfree Merchant Dashboard under Payment Gateway &rarr; Developers &rarr; Webhooks', 'cashfree-fluentcart'),
                    __('Click "Add Webhook", paste the Webhook URL above, and select API version 2026-01-01', 'cashfree-fluentcart'),
                    __('Also add this Webhook URL under Subscriptions &rarr; Webhooks if using subscriptions', 'cashfree-fluentcart'),
                ],
            ],
            'events'      => [
                'title' => __('Required Webhook Events', 'cashfree-fluentcart'),
                'list'  => [
                    'PAYMENT_SUCCESS_WEBHOOK',
                    'PAYMENT_FAILED_WEBHOOK',
                    'PAYMENT_USER_DROPPED_WEBHOOK',
                    'ORDER_PAID',
                    'REFUND_STATUS_WEBHOOK',
                    'SUBSCRIPTION_STATUS_CHANGED',
                    'SUBSCRIPTION_PAYMENT_SUCCESS',
                    'SUBSCRIPTION_PAYMENT_FAILED',
                ],
            ],
        ];
    }

    public function fields(): array {
        return [
            'notice' => [
                'value' => method_exists($this, 'renderStoreModeNotice') ? $this->renderStoreModeNotice() : '',
                'label' => __('Store Mode notice', 'cashfree-fluentcart'),
                'type'  => 'notice',
            ],
            'setup_notice' => [
                'type'  => 'notice',
                'value' => '<p>' . esc_html__('Cashfree supports one-time payments and recurring subscriptions in INR and 140+ international currencies (USD, EUR, GBP, AUD, CAD, AED, SGD, etc.). Webhooks are cryptographically verified using your App Secret Key per official Cashfree documentation.', 'cashfree-fluentcart') . '</p>',
            ],
            'is_active' => [
                'type'  => 'enable',
                'label' => __('Enable Cashfree Payments', 'cashfree-fluentcart'),
                'value' => 'no',
            ],
            'description' => [
                'type'        => 'textarea',
                'label'       => __('Description', 'cashfree-fluentcart'),
                'description' => __('Payment method description displayed to the customer at checkout.', 'cashfree-fluentcart'),
                'value'       => __('Pay securely using UPI, Cards, NetBanking, and Wallets via Cashfree.', 'cashfree-fluentcart'),
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'cashfree-fluentcart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_app_id' => [
                                'type'        => 'text',
                                'label'       => __('Live App ID', 'cashfree-fluentcart'),
                                'placeholder' => __('Your live Cashfree App ID', 'cashfree-fluentcart'),
                                'description' => __('Your production App ID from Cashfree Merchant Dashboard.', 'cashfree-fluentcart'),
                            ],
                            'live_secret_key' => [
                                'type'        => 'password',
                                'label'       => __('Live Secret Key', 'cashfree-fluentcart'),
                                'placeholder' => __('Your live secret key', 'cashfree-fluentcart'),
                                'description' => __('Your production App Secret Key from Cashfree. Used for API access and webhook verification.', 'cashfree-fluentcart'),
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'cashfree-fluentcart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_app_id' => [
                                'type'        => 'text',
                                'label'       => __('Test App ID', 'cashfree-fluentcart'),
                                'placeholder' => __('Your test / sandbox App ID', 'cashfree-fluentcart'),
                                'description' => __('Your sandbox App ID from Cashfree Merchant Dashboard.', 'cashfree-fluentcart'),
                            ],
                            'test_secret_key' => [
                                'type'        => 'password',
                                'label'       => __('Test Secret Key', 'cashfree-fluentcart'),
                                'placeholder' => __('Your test secret key', 'cashfree-fluentcart'),
                                'description' => __('Your sandbox App Secret Key from Cashfree. Used for sandbox API access and webhook verification.', 'cashfree-fluentcart'),
                            ],
                        ],
                    ],
                ],
                'value' => 'live',
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
                'label' => __('Webhook Configuration', 'cashfree-fluentcart'),
                'type'  => 'html_attr',
            ],
        ];
    }

    public function getLocalizeData(): array {
        return [
            'fct_cashfree_data' => [
                'app_id'        => $this->settings->appId(),
                'mode'          => $this->settings->isTest() ? 'sandbox' : 'production',
                'checkout_type' => 'modal',
                'ajax_url'      => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('cffc_confirm'),
                'translations'  => [
                    'Cards'                       => __('Cards', 'cashfree-fluentcart'),
                    'UPI'                         => __('UPI', 'cashfree-fluentcart'),
                    'Net Banking'                 => __('Net Banking', 'cashfree-fluentcart'),
                    'Wallets'                     => __('Wallets', 'cashfree-fluentcart'),
                    'Available payment methods'   => __('Available payment methods', 'cashfree-fluentcart'),
                    'Place Order'                 => __('Place Order', 'cashfree-fluentcart'),
                    'Processing payment...'       => __('Processing payment...', 'cashfree-fluentcart'),
                    'Payment Modal is opening...' => __('Payment Modal is opening, please complete the payment...', 'cashfree-fluentcart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'cashfree-fluentcart'),
                ],
            ],
        ];
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array {
        return [
            [
                'handle' => 'cashfree-js',
                'src'    => 'https://sdk.cashfree.com/js/v3/cashfree.js',
            ],
            [
                'handle'  => 'fluent-cart-cashfree-custom',
                'src'     => CFFC_URL . 'assets/checkout.min.js',
                'version' => CFFC_VER,
                'deps'    => ['cashfree-js'],
            ],
        ];
    }

    public function getOrderInfo($data) {
        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'cashfree-fluentcart'),
            'data'         => [],
            'payment_args' => [
                'app_id'        => $this->settings->appId(),
                'mode'          => $this->settings->isTest() ? 'sandbox' : 'production',
                'checkout_type' => 'modal',
            ],
        ], 200);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance) {
        if (!$this->settings->isActive()) {
            return $this->failed(__('Cashfree Payments is not active.', 'cashfree-fluentcart'));
        }
        if (!$this->settings->appId() || !$this->settings->secretKey()) {
            return $this->failed(__('Cashfree App ID or Secret Key is not configured.', 'cashfree-fluentcart'));
        }
        if ($paymentInstance->subscription && (!method_exists($this, 'shouldChargeSubscriptionAsOneTime') || !$this->shouldChargeSubscriptionAsOneTime($paymentInstance))) {
            return $this->createSubscriptionCheckout($paymentInstance);
        }
        return $this->createOneTimeCheckout($paymentInstance);
    }

    private function createOneTimeCheckout(PaymentInstance $paymentInstance) {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        if (!$order || !$transaction) {
            return $this->failed(__('Unable to initialize the Cashfree transaction.', 'cashfree-fluentcart'));
        }

        $currency = strtoupper((string) ($transaction->currency ?: 'INR'));
        if (!$this->isSupportedCurrency($currency)) {
            return $this->failed(sprintf(__('Cashfree does not support %s in this configuration.', 'cashfree-fluentcart'), $currency));
        }

        $amount = $this->amount($transaction->total, $currency);
        $minAmount = ($currency === 'INR') ? 1.00 : 0.50;
        if ($amount < $minAmount) {
            return $this->failed(sprintf(__('Cashfree requires an order amount of at least %s %s.', 'cashfree-fluentcart'), $currency, number_format($minAmount, 2)));
        }

        $api = new API($this->settings);
        $existingOrderId = (string) ($transaction->meta['cashfree_order_id'] ?? '');
        $existingSessionId = (string) ($transaction->meta['cashfree_payment_session_id'] ?? '');

        if ($existingOrderId && $existingSessionId) {
            $existingOrder = $api->getOrder($existingOrderId);
            if (!is_wp_error($existingOrder)) {
                $status = strtoupper((string) ($existingOrder['order_status'] ?? ''));
                if ($status === 'ACTIVE' && !empty($existingOrder['payment_session_id'])) {
                    return $this->checkoutResponse($transaction, 'payment', [
                        'payment_session_id' => (string) $existingOrder['payment_session_id'],
                        'cashfree_order_id'  => $existingOrderId,
                    ]);
                } elseif ($status === 'PAID') {
                    $this->verifyAndSucceed($transaction, $order, $existingOrderId);
                    return ['status' => 'success', 'actionName' => 'redirect', 'redirect_to' => $this->getReceiptUrl($transaction)];
                }
            }
        }

        $attemptCount = (int) ($transaction->meta['cashfree_attempts'] ?? 0) + 1;
        $orderSeed = ($attemptCount > 1) ? $transaction->uuid . '_' . $attemptCount : (string) $transaction->uuid;
        $cashfreeOrderId = $this->stableId('fc_', $orderSeed, 45);

        $cust = $this->resolveCustomerDetails($order);
        if (strlen($cust['phone']) < 10) {
            return $this->failed(__('A valid phone number (at least 10 digits) is required for Cashfree checkout.', 'cashfree-fluentcart'));
        }

        $orderMeta = [
            'return_url' => add_query_arg(['cffc_order_id' => $cashfreeOrderId, 'cffc_tx' => (string) $transaction->uuid], $this->getReceiptUrl($transaction)),
        ];
        $notifyUrl = $this->resolveNotifyUrl();
        if ($notifyUrl) {
            $orderMeta['notify_url'] = $notifyUrl;
        }

        $customerDetails = [
            'customer_id'    => $cust['customer_id'],
            'customer_phone' => $cust['phone'],
        ];
        if (!empty($cust['name'])) {
            $customerDetails['customer_name'] = $cust['name'];
        }
        if (!empty($cust['email'])) {
            $customerDetails['customer_email'] = $cust['email'];
        }

        $payload = [
            'order_id'         => $cashfreeOrderId,
            'order_amount'     => $amount,
            'order_currency'   => $currency,
            'customer_details' => $customerDetails,
            'order_note'       => sanitize_text_field(substr($this->getOrderDescription($order), 0, 200)),
            'order_meta'       => $orderMeta,
            'order_tags'       => [
                'fluent_transaction_uuid' => (string) $transaction->uuid,
                'fluent_order_id'         => (string) $order->id,
            ],
        ];

        $result = $api->createOrder($payload, $this->idempotencyKey('order', $cashfreeOrderId));
        if (is_wp_error($result) && (int) $result->get_error_data('status') === 409) {
            $cashfreeOrderId = $this->stableId('fc_', $transaction->uuid . '_' . time(), 45);
            $payload['order_id'] = $cashfreeOrderId;
            $payload['order_meta']['return_url'] = add_query_arg(['cffc_order_id' => $cashfreeOrderId, 'cffc_tx' => (string) $transaction->uuid], $this->getReceiptUrl($transaction));
            $result = $api->createOrder($payload, $this->idempotencyKey('order', $cashfreeOrderId));
        }

        if (is_wp_error($result)) {
            return $this->failed($result->get_error_message());
        }

        $sessionId = (string) ($result['payment_session_id'] ?? '');
        if (!$sessionId) {
            return $this->failed(__('Cashfree did not return a payment session ID.', 'cashfree-fluentcart'));
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $meta['cashfree_order_id']           = $cashfreeOrderId;
        $meta['cashfree_payment_session_id'] = $sessionId;
        $meta['cashfree_attempts']           = $attemptCount;
        $transaction->meta                   = $meta;
        $transaction->save();

        return $this->checkoutResponse($transaction, 'payment', [
            'payment_session_id' => $sessionId,
            'cashfree_order_id'  => $cashfreeOrderId,
        ]);
    }

    private function createSubscriptionCheckout(PaymentInstance $paymentInstance) {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;

        if (!$order || !$transaction || !$subscription) {
            return $this->failed(__('Unable to initialize the Cashfree subscription payment.', 'cashfree-fluentcart'));
        }

        $currency = strtoupper((string) ($transaction->currency ?: 'INR'));
        if (!$this->isSupportedCurrency($currency)) {
            return $this->failed(sprintf(__('Cashfree does not support %s currency for subscriptions in this configuration.', 'cashfree-fluentcart'), $currency));
        }

        $initialAmount = $this->amount($transaction->total, $currency);
        $recurringAmount = $this->amount($subscription->recurring_total, $currency);
        $minAmount = ($currency === 'INR') ? 1.00 : 0.50;

        if ($recurringAmount < $minAmount) {
            return $this->failed(sprintf(__('Cashfree recurring subscription amount must be at least %s %s.', 'cashfree-fluentcart'), $currency, number_format($minAmount, 2)));
        }

        $interval = $this->mapInterval($subscription->billing_interval);
        if (!$interval) {
            return $this->failed(__('This subscription billing interval is not supported by Cashfree.', 'cashfree-fluentcart'));
        }

        $cust = $this->resolveCustomerDetails($order);
        if (strlen($cust['phone']) < 10) {
            return $this->failed(__('A valid customer phone number is required for Cashfree subscriptions.', 'cashfree-fluentcart'));
        }

        $cfSubscriptionId = (string) ($subscription->vendor_subscription_id ?: ($transaction->meta['cashfree_subscription_id'] ?? ''));
        if (!$cfSubscriptionId) {
            $cfSubscriptionId = $this->stableId('fcsub_', (string) $subscription->id . '_' . (string) $transaction->uuid, 45);
        }

        $planId = $this->stableId('fcplan_', $recurringAmount . '|' . $interval['count'] . '|' . $interval['type'] . '|' . $currency . '|' . $this->settings->mode(), 40);
        $planResult = $this->ensurePlan($planId, $interval, $recurringAmount, $initialAmount, $currency, $order);
        if (is_wp_error($planResult)) {
            return $this->failed($planResult->get_error_message());
        }

        $nextBilling = $subscription->next_billing_date ?: (method_exists($subscription, 'guessNextBillingDate') ? $subscription->guessNextBillingDate() : null);
        $firstChargeTimestamp = is_numeric($nextBilling) ? (int) $nextBilling : strtotime((string) $nextBilling);
        if (!$firstChargeTimestamp || $firstChargeTimestamp <= time()) {
            $firstChargeTimestamp = $this->calculateNextCycleTimestamp($interval['count'], $interval['type']);
        }

        $subMeta = [
            'return_url'           => add_query_arg(['cffc_subscription_id' => $cfSubscriptionId, 'cffc_tx' => (string) $transaction->uuid], $this->getReceiptUrl($transaction)),
            'notification_channel' => ['EMAIL', 'SMS'],
        ];
        $notifyUrl = $this->resolveNotifyUrl();
        if ($notifyUrl) {
            $subMeta['notify_url'] = $notifyUrl;
        }

        $authAmount = ($initialAmount >= $minAmount) ? $initialAmount : $minAmount;
        $authRefund = ($initialAmount < $minAmount);
        $methods = ($currency === 'INR') ? ['upi', 'enach', 'card'] : ['card'];

        $subCustDetails = [
            'customer_phone' => $cust['phone'],
        ];
        if (!empty($cust['name'])) {
            $subCustDetails['customer_name'] = $cust['name'];
        }
        if (!empty($cust['email'])) {
            $subCustDetails['customer_email'] = $cust['email'];
        }

        $payload = [
            'subscription_id' => $cfSubscriptionId,
            'customer_details' => $subCustDetails,
            'plan_details' => [
                'plan_id'   => $planId,
                'plan_name' => substr('FluentCart ' . $interval['label'], 0, 40),
                'plan_type' => 'PERIODIC',
            ],
            'authorization_details' => [
                'authorization_amount'        => $authAmount,
                'authorization_amount_refund' => $authRefund,
                'payment_methods'             => $methods,
            ],
            'subscription_meta'              => $subMeta,
            'subscription_expiry_time'       => $subscription->expire_at ? $this->toIsoDate($subscription->expire_at, strtotime('+30 years')) : gmdate('c', strtotime('+30 years')),
            'subscription_first_charge_time' => gmdate('c', $firstChargeTimestamp),
            'subscription_note'              => sanitize_text_field(substr($this->getOrderDescription($order), 0, 200)),
            'subscription_tags'              => [
                'fluent_transaction_uuid' => (string) $transaction->uuid,
                'fluent_subscription_id'  => (string) $subscription->id,
                'cashfree_plan_id'        => $planId,
            ],
        ];

        $api = new API($this->settings);
        $result = $api->createSubscription($payload, $this->idempotencyKey('subscription', $cfSubscriptionId));
        if (is_wp_error($result) && (int) $result->get_error_data('status') === 409) {
            $existing = $api->getSubscription($cfSubscriptionId);
            if (!is_wp_error($existing)) {
                $result = $existing;
            }
        }

        if (is_wp_error($result)) {
            return $this->failed($result->get_error_message());
        }

        $sessionId = (string) ($result['subscription_session_id'] ?? '');
        if (!$sessionId) {
            return $this->failed(__('Cashfree did not return a subscription session ID.', 'cashfree-fluentcart'));
        }

        $returnedMerchantId = (string) ($result['subscription_id'] ?? $cfSubscriptionId);
        $cashfreeRef = (string) ($result['cf_subscription_id'] ?? '');

        $subscription->vendor_subscription_id = $returnedMerchantId;
        $subscription->vendor_plan_id         = $planId;
        $subscription->current_payment_method = 'cashfree';
        $subscription->collection_method      = 'system';

        if (property_exists($subscription, 'vendor_customer_id')) {
            $subscription->vendor_customer_id = $cust['customer_id'];
        }
        if (property_exists($subscription, 'vendor_response')) {
            $subscription->vendor_response = $result;
        }
        $subscription->save();

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $meta['cashfree_subscription_id']         = $returnedMerchantId;
        $meta['cashfree_cf_subscription_id']      = $cashfreeRef;
        $meta['cashfree_subscription_session_id'] = $sessionId;
        $meta['cashfree_plan_id']                 = $planId;
        $meta['cashfree_subscription_type']       = 'periodic';
        $transaction->meta                        = $meta;
        $transaction->save();

        return $this->checkoutResponse($transaction, 'subscription', [
            'subscription_session_id'  => $sessionId,
            'cashfree_subscription_id' => $returnedMerchantId,
        ]);
    }

    private function ensurePlan($planId, array $interval, $recurringAmount, $maxAmount, $currency, $order) {
        $api = new API($this->settings);
        $existing = $api->getPlan($planId);
        if (!is_wp_error($existing) && strtoupper((string) ($existing['plan_status'] ?? 'ACTIVE')) === 'ACTIVE') {
            return $existing;
        }

        $payload = [
            'plan_id'               => $planId,
            'plan_name'             => substr('FluentCart ' . $interval['label'], 0, 40),
            'plan_type'             => 'PERIODIC',
            'plan_currency'         => strtoupper((string) $currency),
            'plan_recurring_amount' => $recurringAmount,
            'plan_max_amount'       => max($recurringAmount, $maxAmount),
            'plan_intervals'        => $interval['count'],
            'plan_interval_type'    => $interval['type'],
            'plan_note'             => sanitize_text_field(substr($this->getOrderDescription($order), 0, 200)),
        ];

        $created = $api->createPlan($payload, $this->idempotencyKey('plan', $planId));
        if (is_wp_error($created) && (int) $created->get_error_data('status') === 409) {
            $existing = $api->getPlan($planId);
            if (!is_wp_error($existing)) {
                return $existing;
            }
        }
        return $created;
    }

    public function chargeRenewal(PaymentInstance $paymentInstance, $args = []) {
        $subscription = $paymentInstance->subscription;
        $transaction = $paymentInstance->transaction;
        if (!$subscription || !$transaction) {
            return new \WP_Error('cffc_subscription', __('Subscription renewal transaction is unavailable.', 'cashfree-fluentcart'));
        }

        $cashfreeSubscriptionId = (string) ($subscription->vendor_subscription_id ?: ($transaction->meta['cashfree_subscription_id'] ?? ''));
        if (!$cashfreeSubscriptionId) {
            return new \WP_Error('cffc_subscription', __('Cashfree subscription ID is missing.', 'cashfree-fluentcart'));
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $meta['cashfree_subscription_id']    = $cashfreeSubscriptionId;
        $meta['cashfree_renewal_started_at'] = time();
        $transaction->meta                   = $meta;
        $transaction->save();

        return 'processing';
    }

    public function reconcileRenewalCharge(PaymentInstance $paymentInstance) {
        $transaction = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        if (!$transaction || !$subscription) {
            return new \WP_Error('cffc_transaction', __('Subscription renewal transaction is unavailable.', 'cashfree-fluentcart'));
        }
        if ($transaction->status === 'succeeded') {
            return true;
        }

        $cashfreeSubscriptionId = (string) ($subscription->vendor_subscription_id ?: ($transaction->meta['cashfree_subscription_id'] ?? ''));
        if (!$cashfreeSubscriptionId) {
            return new \WP_Error('cffc_subscription', __('Cashfree subscription ID is missing.', 'cashfree-fluentcart'));
        }

        $result = (new API($this->settings))->getSubscriptionPayments($cashfreeSubscriptionId);
        if (is_wp_error($result)) {
            return $result;
        }

        $payments = $this->normalizeList($result);
        if (!$payments) {
            return 'processing';
        }

        $currency = strtoupper((string) ($transaction->currency ?: 'INR'));
        $expectedAmount = $this->amount($transaction->total, $currency);
        $startedAt = (int) ($transaction->meta['cashfree_renewal_started_at'] ?? strtotime((string) ($transaction->created_at ?? 'now')));
        $candidate = null;

        foreach ($payments as $payment) {
            $status = strtoupper((string) ($payment['payment_status'] ?? ''));
            $amount = $this->cashfreeAmount($payment['payment_amount'] ?? 0);
            if (abs($amount - $expectedAmount) > 0.01) continue;

            $initiated = !empty($payment['payment_initiated_date']) ? strtotime((string) $payment['payment_initiated_date']) : 0;
            if ($initiated && $startedAt && $initiated + DAY_IN_SECONDS < $startedAt) continue;

            if ($status === 'SUCCESS') {
                $candidate = $payment;
                break;
            }
            if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                $candidate = $payment;
            }
        }

        if (!$candidate) {
            return 'processing';
        }

        $status = strtoupper((string) ($candidate['payment_status'] ?? ''));
        $paymentId = (string) ($candidate['cf_payment_id'] ?? ($candidate['payment_id'] ?? ''));
        if ($status === 'SUCCESS') {
            $this->markTransactionSucceeded($transaction, $paymentId, $paymentInstance->order);
            return true;
        }

        if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            $transaction->status = 'failed';
            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            if ($paymentId) {
                $meta['cashfree_payment_id'] = $paymentId;
            }
            $transaction->meta = $meta;
            $transaction->save();
            return new \WP_Error('cffc_renewal_failed', __('Cashfree reported that the subscription payment failed.', 'cashfree-fluentcart'));
        }

        return 'processing';
    }

    public function cancelSubscription($subscription) {
        if (!$subscription) return false;
        if (is_numeric($subscription)) {
            $subscription = Subscription::find((int) $subscription);
        }
        if (!$subscription || !is_object($subscription)) return false;
        if (isset($subscription->payment_method) && $subscription->payment_method !== 'cashfree') {
            return false;
        }

        $cashfreeSubscriptionId = (string) ($subscription->vendor_subscription_id ?: '');
        if (!$cashfreeSubscriptionId) return false;

        $result = (new API($this->settings))->manageSubscription($cashfreeSubscriptionId, 'CANCEL');
        return is_wp_error($result) ? $result : true;
    }

    public function processRefund($transaction, $amount, $args = []) {
        if (!$transaction) {
            return new \WP_Error('cffc_refund', __('FluentCart transaction was not found for this refund.', 'cashfree-fluentcart'));
        }

        $currency = strtoupper((string) ($transaction->currency ?: 'INR'));
        $requestedAmount = $this->amount($amount, $currency);
        if ($requestedAmount <= 0) {
            return new \WP_Error('cffc_refund', __('Refund amount must be greater than zero.', 'cashfree-fluentcart'));
        }

        $orderId = (string) ($transaction->meta['cashfree_order_id'] ?? '');
        $paymentId = (string) ($transaction->meta['cashfree_payment_id'] ?? '');
        $subscriptionId = (string) ($transaction->meta['cashfree_subscription_id'] ?? '');
        if (!$orderId && !$subscriptionId) {
            return new \WP_Error('cffc_refund', __('Cashfree order or subscription information is missing.', 'cashfree-fluentcart'));
        }

        $lockKey = 'cffc_refund_lock_' . md5((string) $transaction->uuid . '|' . $requestedAmount);
        if (!add_option($lockKey, time(), '', 'no')) {
            $lockAt = (int) get_option($lockKey, 0);
            if ($lockAt && (time() - $lockAt) > 600) {
                delete_option($lockKey);
                if (!add_option($lockKey, time(), '', 'no')) {
                    return new \WP_Error('cffc_refund_busy', __('A refund for this transaction is already being processed.', 'cashfree-fluentcart'));
                }
            } else {
                return new \WP_Error('cffc_refund_busy', __('A refund for this transaction is already being processed. Please wait a moment.', 'cashfree-fluentcart'));
            }
        }

        try {
            $api = new API($this->settings);
            $note = substr((string) ($args['description'] ?? __('Refund from FluentCart', 'cashfree-fluentcart')), 0, 100);
            if (strlen($note) < 3) $note = 'Refund from FluentCart';

            if ($orderId) {
                $orderResult = $api->getOrder($orderId);
                if (is_wp_error($orderResult)) return $orderResult;
                if (strtoupper((string) ($orderResult['order_status'] ?? '')) !== 'PAID') {
                    return new \WP_Error('cffc_refund', __('This Cashfree order is not eligible for refund because it is not marked as paid.', 'cashfree-fluentcart'));
                }
                $paidAmount = $this->cashfreeAmount($orderResult['order_amount'] ?? 0);
                if ($requestedAmount > $paidAmount + 0.01) {
                    return new \WP_Error('cffc_refund', __('The refund amount exceeds the Cashfree order amount.', 'cashfree-fluentcart'));
                }
            }

            $refundId = 'fcr_' . substr(md5($transaction->uuid . '_' . microtime() . '_' . wp_rand()), 0, 32);
            if ($subscriptionId && $paymentId) {
                $result = $api->createSubscriptionRefund($subscriptionId, [
                    'subscription_id' => $subscriptionId,
                    'payment_id'      => $paymentId,
                    'refund_id'       => $refundId,
                    'refund_amount'   => $requestedAmount,
                    'refund_note'     => $note,
                    'refund_speed'    => 'STANDARD',
                ], $this->idempotencyKey('sub-refund', $refundId));
            } elseif ($orderId) {
                $result = $api->createRefund($orderId, [
                    'refund_amount' => $requestedAmount,
                    'refund_id'     => $refundId,
                    'refund_note'   => $note,
                    'refund_speed'  => 'STANDARD',
                ], $this->idempotencyKey('refund', $refundId));
            } else {
                return new \WP_Error('cffc_refund', __('Cashfree payment information required for refund is missing.', 'cashfree-fluentcart'));
            }

            if (is_wp_error($result)) return $result;

            $remoteId = (string) ($result['cf_refund_id'] ?? ($result['refund_id'] ?? ($result['subRefundId'] ?? $refundId)));
            $status = strtolower((string) ($result['refund_status'] ?? ($result['refundStatus'] ?? 'pending')));
            if (in_array($status, ['failed', 'cancelled', 'canceled'], true)) {
                return new \WP_Error('cffc_refund', __('Cashfree rejected the refund request.', 'cashfree-fluentcart'));
            }

            $fluentStatus = in_array($status, ['success', 'succeeded', 'completed'], true) ? 'refunded' : 'pending';
            $existing = OrderTransaction::query()->where('vendor_charge_id', $remoteId)->first();
            if ($existing) {
                $existing->status = $fluentStatus;
                $existing->save();
            } else {
                Refund::createOrRecordRefund([
                    'vendor_charge_id' => $remoteId,
                    'payment_method'   => 'cashfree',
                    'payment_mode'     => $transaction->payment_mode ?? $this->settings->mode(),
                    'status'           => $fluentStatus,
                    'total'            => $requestedAmount,
                ], $transaction);
            }

            return ['success' => true, 'refund_id' => $remoteId, 'status' => $status, 'amount' => $requestedAmount];
        } finally {
            delete_option($lockKey);
        }
    }

    public function handleIPN() {
        $raw = (string) file_get_contents('php://input');
        $result = $this->processWebhookRequest($raw, $this->getHeaderValue('x-webhook-signature'), $this->getHeaderValue('x-webhook-timestamp'));
        if (is_wp_error($result)) {
            $status = (int) $result->get_error_data('status') ?: 400;
            wp_send_json(['ok' => false, 'message' => $result->get_error_message()], ($status >= 400 && $status <= 599) ? $status : 400);
        }
        wp_send_json(['ok' => true], 200);
    }

    public function handleWebhook($request) {
        $raw = (string) $request->get_body();
        $sig = (string) ($request->get_header('x-webhook-signature') ?: $this->getHeaderValue('x-webhook-signature'));
        $time = (string) ($request->get_header('x-webhook-timestamp') ?: $this->getHeaderValue('x-webhook-timestamp'));

        $response = $this->processWebhookRequest($raw, $sig, $time);
        if (is_wp_error($response)) {
            return new \WP_REST_Response(['ok' => false, 'message' => $response->get_error_message()], $response->get_error_data('status') ?: 400);
        }
        return new \WP_REST_Response(['ok' => true], 200);
    }

    private function processWebhookRequest($raw, $signature, $timestamp) {
        if (!$raw || !$signature || !$timestamp) {
            return new \WP_Error('cffc_webhook', __('Missing Cashfree webhook signature or timestamp headers.', 'cashfree-fluentcart'), ['status' => 400]);
        }
        if (!$this->verifyWebhookSignature($timestamp, $raw, $signature)) {
            return new \WP_Error('cffc_webhook_signature', __('Invalid Cashfree webhook signature.', 'cashfree-fluentcart'), ['status' => 401]);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new \WP_Error('cffc_webhook_json', __('Invalid Cashfree webhook JSON body.', 'cashfree-fluentcart'), ['status' => 400]);
        }

        $dedupeKey = 'cffc_evt_' . md5($timestamp . '|' . $signature . '|' . $raw);
        if (get_transient($dedupeKey)) return true;

        $event = strtoupper((string) ($payload['type'] ?? ($payload['event'] ?? '')));
        $this->processWebhookPayload($event, $payload);
        set_transient($dedupeKey, 1, 2 * DAY_IN_SECONDS);
        return true;
    }

    public function verifyWebhookSignature($timestamp, $raw, $signature): bool {
        $timestamp = trim((string) $timestamp);
        $signature = trim((string) $signature);
        if (empty($timestamp) || empty($raw) || empty($signature)) return false;

        $timestampMs = is_numeric($timestamp) ? (float) $timestamp : 0;
        if ($timestampMs > 0) {
            $timestampSec = (strlen((string) (int) $timestampMs) > 10) ? (int) ($timestampMs / 1000) : (int) $timestampMs;
            $maxAge = (int) apply_filters('cffc_webhook_max_age_seconds', 600);
            if ($maxAge > 0 && abs(time() - $timestampSec) > $maxAge && !apply_filters('cffc_allow_expired_webhook_timestamp', false)) {
                return false;
            }
        }

        $signedPayload = $timestamp . $raw;
        $primarySecret = $this->settings->secretKey();
        if ($primarySecret && hash_equals(base64_encode(hash_hmac('sha256', $signedPayload, $primarySecret, true)), $signature)) {
            return true;
        }

        $altSecret = $this->settings->secretKey($this->settings->isTest() ? 'live' : 'test');
        if ($altSecret && $altSecret !== $primarySecret && hash_equals(base64_encode(hash_hmac('sha256', $signedPayload, $altSecret, true)), $signature)) {
            return true;
        }
        return false;
    }

    private function processWebhookPayload($event, array $payload) {
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $normalizedEvent = strtoupper(str_replace([' ', '-'], '_', trim((string) ($payload['type'] ?? ($payload['event'] ?? ($payload['event_type'] ?? $event))))));

        static $subEvents = [
            'SUBSCRIPTION_AUTH_STATUS' => 1, 'SUBSCRIPTION_CARD_EXPIRY_REMINDER' => 1,
            'SUBSCRIPTION_CONTROLLED_EXECUTION_STATUS' => 1, 'SUBSCRIPTION_CONTROLLED_NOTIFICATION_STATUS' => 1,
            'SUBSCRIPTION_PAYMENT_CANCELLED' => 1, 'SUBSCRIPTION_PAYMENT_FAILED' => 1,
            'SUBSCRIPTION_PAYMENT_NOTIFICATION_INITIATED' => 1, 'SUBSCRIPTION_PAYMENT_SUCCESS' => 1,
            'SUBSCRIPTION_REFUND_STATUS' => 1, 'SUBSCRIPTION_STATUS_CHANGED' => 1, 'SUBSCRIPTION_STATUS_CHANGE' => 1,
        ];

        if (isset($subEvents[$normalizedEvent])) {
            $this->processSubscriptionWebhook($normalizedEvent, $data, $payload);
            return;
        }

        if (strpos($normalizedEvent, 'REFUND_') === 0 || $normalizedEvent === 'ORDER_REFUND_COMPLETED') {
            $this->processRefundWebhook($normalizedEvent, $data);
            return;
        }

        if (in_array($normalizedEvent, ['INSTRUMENT_ACTIVE_WEBHOOK', 'INSTRUMENT_FAILED_WEBHOOK'], true)) return;

        $orderId = (string) ($data['order']['order_id'] ?? ($data['order_id'] ?? ''));
        if (!$orderId) return;

        $transaction = $this->findTransactionForOrder($orderId, $data);
        if (!$transaction) return;

        $payment = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : [];
        $status = strtoupper((string) ($payment['payment_status'] ?? ($data['payment_status'] ?? '')));
        $amount = $this->cashfreeAmount($payment['payment_amount'] ?? ($data['payment_amount'] ?? 0));

        $expectedCurrency = strtoupper((string) ($transaction->currency ?: 'INR'));
        $expected = $this->amount($transaction->total, $expectedCurrency);
        $currency = strtoupper((string) ($payment['payment_currency'] ?? ($data['payment_currency'] ?? $expectedCurrency)));

        if ($currency === $expectedCurrency && abs($amount - $expected) <= 0.01) {
            if (in_array($normalizedEvent, ['PAYMENT_SUCCESS_WEBHOOK', 'PAYMENT_SUCCESS', 'ORDER_PAID_WEBHOOK', 'ORDER_PAID'], true) && $status === 'SUCCESS') {
                $this->markTransactionSucceeded($transaction, (string) ($payment['cf_payment_id'] ?? ''), Order::find($transaction->order_id));
            }
        }
    }

    private function processSubscriptionWebhook($event, array $data, array $payload = []) {
        $subscriptionId = (string) ($data['subscription_id'] ?? ($data['subscription']['subscription_id'] ?? ($data['cf_subscription_id'] ?? ($payload['subscription_id'] ?? ''))));
        if (!$subscriptionId) return;

        $subscription = Subscription::query()->where('vendor_subscription_id', $subscriptionId)->first();
        if (!$subscription) {
            $tags = $data['subscription_tags'] ?? ($data['subscription']['subscription_tags'] ?? ($payload['subscription_tags'] ?? null));
            if (!empty($tags['fluent_subscription_id'])) {
                $subscription = Subscription::find((int) $tags['fluent_subscription_id']);
            }
        }
        if (!$subscription) {
            $anyTx = OrderTransaction::query()->where('payment_method', 'cashfree')->where('meta', 'LIKE', '%' . $subscriptionId . '%')->latest()->first();
            if ($anyTx && $anyTx->order_id) {
                $subscription = Subscription::query()->where('parent_order_id', $anyTx->order_id)->first();
            }
        }

        $order = ($subscription && $subscription->parent_order_id) ? Order::find($subscription->parent_order_id) : null;

        // 1. Authorization status
        if ($event === 'SUBSCRIPTION_AUTH_STATUS') {
            $auth = $data['authorization_details'] ?? ($data['authorization'] ?? ($data['payment'] ?? []));
            $authStatus = strtoupper((string) ($auth['authorization_status'] ?? ($auth['status'] ?? ($data['authorization_status'] ?? ''))));
            $authPaymentId = (string) ($auth['cf_payment_id'] ?? ($auth['payment_id'] ?? ($data['cf_payment_id'] ?? '')));
            $authRef = (string) ($auth['authorization_reference'] ?? ($data['authorization_reference'] ?? ''));
            $transaction = $this->findTransactionForSubscription($subscriptionId, $data);

            if ($authStatus === 'SUCCESS') {
                if ($transaction) {
                    $meta = is_array($transaction->meta) ? $transaction->meta : [];
                    if ($authPaymentId) $meta['cashfree_payment_id'] = $authPaymentId;
                    if ($authRef) $meta['cashfree_auth_reference'] = $authRef;
                    $meta['cashfree_auth_status'] = 'SUCCESS';
                    $transaction->meta = $meta;
                    $transaction->save();
                    $this->markTransactionSucceeded($transaction, $authPaymentId, $order ?: Order::find($transaction->order_id));
                }
                if ($subscription) {
                    $this->syncSubscriptionStatus($subscriptionId, 'ACTIVE');
                    $this->setSubscriptionMeta($subscription, ['cashfree_auth_status' => 'SUCCESS', 'cashfree_auth_payment_id' => $authPaymentId, 'cashfree_auth_reference' => $authRef]);
                }
                $this->addOrderNote($order, sprintf(__('Cashfree subscription mandate authorized (Ref: %1$s, Payment ID: %2$s).', 'cashfree-fluentcart'), $authRef ?: 'N/A', $authPaymentId ?: 'N/A'));
                do_action('fluent_cart/cashfree_subscription_auth_success', $subscription, $transaction, $data);
            } elseif (in_array($authStatus, ['FAILED', 'CANCELLED', 'DECLINED'], true)) {
                $reason = (string) ($data['failure_details']['failure_reason'] ?? ($auth['failure_reason'] ?? __('Mandate rejected.', 'cashfree-fluentcart')));
                if ($transaction && $transaction->status !== 'succeeded') {
                    $transaction->status = 'failed';
                    $meta = is_array($transaction->meta) ? $transaction->meta : [];
                    $meta['cashfree_failure_reason'] = $reason;
                    $transaction->meta = $meta;
                    $transaction->save();
                }
                if ($subscription) {
                    $this->syncSubscriptionStatus($subscriptionId, 'FAILED');
                    $this->setSubscriptionMeta($subscription, ['cashfree_auth_status' => $authStatus, 'cashfree_auth_failure' => $reason]);
                }
                $this->addOrderNote($order, sprintf(__('Cashfree mandate authorization %1$s: %2$s.', 'cashfree-fluentcart'), $authStatus, $reason));
                do_action('fluent_cart/cashfree_subscription_auth_failed', $subscription, $transaction, $data);
            }
            return;
        }

        // 2. Lifecycle status changed
        if ($event === 'SUBSCRIPTION_STATUS_CHANGED' || $event === 'SUBSCRIPTION_STATUS_CHANGE') {
            $status = strtoupper((string) ($data['subscription_status'] ?? ($data['status'] ?? '')));
            if ($status) $this->syncSubscriptionStatus($subscriptionId, $status);
            if ($subscription) {
                $this->setSubscriptionMeta($subscription, ['cashfree_status' => $status, 'cashfree_status_changed_at' => current_time('mysql')]);
            }
            $this->addOrderNote($order, sprintf(__('Cashfree subscription status changed to %s.', 'cashfree-fluentcart'), $status));
            do_action('fluent_cart/cashfree_subscription_status_changed', $subscription, $status, $data);
            return;
        }

        // 3. Subscription payment succeeded
        if ($event === 'SUBSCRIPTION_PAYMENT_SUCCESS') {
            $payment = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : (isset($data['payment_details']) && is_array($data['payment_details']) ? $data['payment_details'] : $data);
            $paymentStatus = strtoupper((string) ($payment['payment_status'] ?? ($data['payment_status'] ?? 'SUCCESS')));
            $paymentId = (string) ($payment['cf_payment_id'] ?? ($payment['payment_id'] ?? ($data['cf_payment_id'] ?? '')));
            $paymentAmount = $this->cashfreeAmount($payment['payment_amount'] ?? ($data['payment_amount'] ?? 0));
            $paymentCurrency = strtoupper((string) ($payment['payment_currency'] ?? ($data['payment_currency'] ?? 'INR')));
            $cycle = (int) ($payment['cycle'] ?? ($payment['payment_count'] ?? 1));

            if ($paymentStatus !== 'SUCCESS') return;

            $initialTx = $this->findTransactionForSubscription($subscriptionId, $data);
            if ($initialTx && $initialTx->status !== 'succeeded') {
                $expectedCurrency = strtoupper((string) ($initialTx->currency ?: 'INR'));
                $expectedAmount = $this->amount($initialTx->total, $expectedCurrency);
                if ($paymentCurrency === $expectedCurrency && abs($paymentAmount - $expectedAmount) <= 0.01) {
                    $this->markTransactionSucceeded($initialTx, $paymentId, $order ?: Order::find($initialTx->order_id));
                    $this->syncSubscriptionStatus($subscriptionId, 'ACTIVE');
                    do_action('fluent_cart/cashfree_subscription_payment_success', $subscription, $paymentId, $data);
                    return;
                }
            }

            $this->handleSubscriptionRenewalSuccess($subscription, $paymentId, $paymentAmount, $paymentCurrency, $cycle, $data, $order);
            return;
        }

        // 4. Subscription payment failed
        if ($event === 'SUBSCRIPTION_PAYMENT_FAILED') {
            $payment = isset($data['payment']) && is_array($data['payment']) ? $data['payment'] : $data;
            $paymentId = (string) ($payment['cf_payment_id'] ?? ($payment['payment_id'] ?? ''));
            $reason = (string) ($data['failure_details']['failure_reason'] ?? ($payment['payment_message'] ?? __('Debit attempt failed.', 'cashfree-fluentcart')));

            $transaction = $this->findTransactionForSubscription($subscriptionId, $data);
            if ($transaction && $transaction->status !== 'succeeded') {
                $transaction->status = 'failed';
                $meta = is_array($transaction->meta) ? $transaction->meta : [];
                $meta['cashfree_failure_reason'] = $reason;
                if ($paymentId) $meta['cashfree_payment_id'] = $paymentId;
                $transaction->meta = $meta;
                $transaction->save();
            }
            if ($subscription) {
                $this->setSubscriptionMeta($subscription, ['cashfree_last_failed_payment_id' => $paymentId, 'cashfree_last_failure_reason' => $reason]);
            }
            $this->addOrderNote($order, sprintf(__('Cashfree subscription payment failed (Payment ID: %1$s): %2$s.', 'cashfree-fluentcart'), $paymentId ?: 'N/A', $reason));
            do_action('fluent_cart/subscription_payment_failed', $subscription, $data);
            do_action('fluent_cart/cashfree_subscription_payment_failed', $subscription, $data);
            return;
        }

        // 5. Payment cancelled
        if ($event === 'SUBSCRIPTION_PAYMENT_CANCELLED') {
            $transaction = $this->findTransactionForSubscription($subscriptionId, $data);
            if ($transaction && $transaction->status !== 'succeeded') {
                $transaction->status = 'failed';
                $transaction->save();
            }
            $this->addOrderNote($order, __('Cashfree subscription payment cycle was cancelled.', 'cashfree-fluentcart'));
            do_action('fluent_cart/cashfree_subscription_payment_cancelled', $subscription, $data);
            return;
        }

        // 6. Pre-debit notice
        if ($event === 'SUBSCRIPTION_PAYMENT_NOTIFICATION_INITIATED') {
            $payment = $data['payment_details'] ?? ($data['payment'] ?? $data);
            $chargeDate = (string) ($payment['charge_date'] ?? '');
            $amount = $this->cashfreeAmount($payment['payment_amount'] ?? 0);
            $currency = strtoupper((string) ($payment['payment_currency'] ?? 'INR'));
            if ($subscription) {
                $this->setSubscriptionMeta($subscription, ['cashfree_pre_debit_notification_sent_at' => current_time('mysql'), 'cashfree_next_scheduled_charge_date' => $chargeDate, 'cashfree_next_scheduled_amount' => $amount]);
            }
            $this->addOrderNote($order, sprintf(__('Cashfree pre-debit notice dispatched for %1$s %2$s on %3$s.', 'cashfree-fluentcart'), $amount, $currency, $chargeDate ?: 'scheduled date'));
            do_action('fluent_cart/cashfree_subscription_payment_notification_initiated', $subscription, $data);
            return;
        }

        // 7. Controlled notification status
        if ($event === 'SUBSCRIPTION_CONTROLLED_NOTIFICATION_STATUS') {
            $notif = $data['notification'] ?? ($data['notification_details'] ?? $data);
            $status = strtoupper((string) ($notif['notification_status'] ?? ''));
            $notifId = (string) ($notif['cf_notification_id'] ?? ($notif['notification_id'] ?? ''));
            if ($subscription) {
                $this->setSubscriptionMeta($subscription, ['cashfree_controlled_notification_status' => $status, 'cashfree_controlled_notification_id' => $notifId]);
            }
            $this->addOrderNote($order, sprintf(__('Cashfree controlled notification %1$s (ID: %2$s).', 'cashfree-fluentcart'), $status, $notifId ?: 'N/A'));
            do_action('fluent_cart/cashfree_subscription_controlled_notification_status', $subscription, $status, $data);
            return;
        }

        // 8. Controlled execution status
        if ($event === 'SUBSCRIPTION_CONTROLLED_EXECUTION_STATUS') {
            $exec = $data['execution'] ?? ($data['execution_details'] ?? ($data['payment'] ?? $data));
            $execStatus = strtoupper((string) ($exec['execution_status'] ?? ($exec['payment_status'] ?? '')));
            $execPaymentId = (string) ($exec['cf_payment_id'] ?? ($exec['payment_id'] ?? ''));
            $amount = $this->cashfreeAmount($exec['payment_amount'] ?? 0);
            $currency = strtoupper((string) ($exec['payment_currency'] ?? 'INR'));
            if ($subscription) {
                $this->setSubscriptionMeta($subscription, ['cashfree_controlled_execution_status' => $execStatus, 'cashfree_controlled_execution_id' => $execPaymentId]);
            }
            if ($execStatus === 'SUCCESS') {
                $this->handleSubscriptionRenewalSuccess($subscription, $execPaymentId, $amount, $currency, 1, $data, $order);
            }
            $this->addOrderNote($order, sprintf(__('Cashfree controlled mandate execution: %1$s (Payment ID: %2$s).', 'cashfree-fluentcart'), $execStatus, $execPaymentId ?: 'N/A'));
            do_action('fluent_cart/cashfree_subscription_controlled_execution_status', $subscription, $execStatus, $data);
            return;
        }

        // 9. Card expiry reminder
        if ($event === 'SUBSCRIPTION_CARD_EXPIRY_REMINDER') {
            $card = $data['card_details'] ?? ($data['card'] ?? []);
            $network = (string) ($card['card_network'] ?? 'Card');
            $last4 = substr(preg_replace('/[^0-9]/', '', (string) ($card['card_number'] ?? '')), -4);
            $month = (string) ($card['card_expiry_month'] ?? 'MM');
            $year = (string) ($card['card_expiry_year'] ?? 'YY');
            if ($subscription) {
                $this->setSubscriptionMeta($subscription, ['cashfree_card_expiring' => 1, 'cashfree_card_network' => $network, 'cashfree_card_last4' => $last4]);
            }
            $this->addOrderNote($order, sprintf(__('Card Expiry Reminder: %1$s ending in %2$s expires on %3$s/%4$s.', 'cashfree-fluentcart'), $network, $last4 ?: '****', $month, $year));
            do_action('fluent_cart/cashfree_subscription_card_expiry_reminder', $subscription, $card, $data);
            return;
        }

        // 10. Subscription refund status
        if ($event === 'SUBSCRIPTION_REFUND_STATUS') {
            $this->processRefundWebhook($event, $data);
            do_action('fluent_cart/cashfree_subscription_refund_status', $subscription, $data);
            return;
        }
    }

    private function handleSubscriptionRenewalSuccess($subscription, $paymentId, $paymentAmount, $currency, $cycle = 1, array $data = [], $order = null) {
        if (!$paymentId) return;

        $existing = OrderTransaction::query()->where('vendor_charge_id', $paymentId)->first();
        if ($existing) {
            if ($existing->status !== 'succeeded') {
                $existing->status = 'succeeded';
                $existing->save();
            }
            return;
        }

        $orderId = (int) ($subscription ? $subscription->parent_order_id : ($order ? $order->id : 0));
        $order = $order ?: ($orderId ? Order::find($orderId) : null);

        $renewalTx = new OrderTransaction();
        $renewalTx->uuid = wp_generate_uuid4();
        $renewalTx->order_id = $orderId;
        if ($subscription && property_exists($renewalTx, 'subscription_id')) {
            $renewalTx->subscription_id = $subscription->id;
        }
        if (property_exists($renewalTx, 'transaction_type')) {
            $renewalTx->transaction_type = 'renewal';
        }
        $renewalTx->payment_method = 'cashfree';
        $renewalTx->payment_mode = $subscription ? ($subscription->payment_mode ?? $this->settings->mode()) : $this->settings->mode();
        $renewalTx->vendor_charge_id = $paymentId;
        $renewalTx->status = 'succeeded';
        $renewalTx->currency = $currency;
        $renewalTx->total = $paymentAmount;
        $renewalTx->meta = [
            'cashfree_subscription_id' => $subscription ? (string) $subscription->vendor_subscription_id : '',
            'cashfree_payment_id'      => $paymentId,
            'cycle'                    => $cycle,
            'event'                    => 'SUBSCRIPTION_PAYMENT_SUCCESS',
        ];
        $renewalTx->save();

        if ($order && class_exists(StatusHelper::class)) {
            (new StatusHelper($order))->syncOrderStatuses($renewalTx);
        }
        if ($subscription) {
            $subscription->status = 'active';
            if (property_exists($subscription, 'bill_count')) {
                $subscription->bill_count = (int) $subscription->bill_count + 1;
            }
            $subscription->save();
        }

        $this->addOrderNote($order, sprintf(__('Cashfree subscription renewal succeeded (Cycle #%1$d, Payment ID: %2$s, Amount: %3$s %4$s).', 'cashfree-fluentcart'), $cycle, $paymentId, $paymentAmount, $currency));
        if ($subscription) {
            do_action('fluent_cart/subscription_payment_succeeded', $subscription, $renewalTx);
        }
        do_action('fluent_cart/cashfree_subscription_payment_success', $subscription, $paymentId, $data);
    }

    private function setSubscriptionMeta($subscription, array $metaData) {
        if (!$subscription) return;
        $current = is_array($subscription->meta) ? $subscription->meta : [];
        $subscription->meta = array_merge($current, $metaData);
        $subscription->save();
    }

    private function addOrderNote($order, string $note) {
        if (!$order || empty($note)) return;
        if (method_exists($order, 'addNote')) {
            $order->addNote($note);
        } elseif (method_exists($order, 'add_order_note')) {
            $order->add_order_note($note);
        }
        do_action('fluent_cart/order_note_added', $order, $note);
    }

    private function processRefundWebhook($event, array $data) {
        $refund = isset($data['refund']) && is_array($data['refund']) ? $data['refund'] : (isset($data['refund_details']) && is_array($data['refund_details']) ? $data['refund_details'] : $data);
        $refundId = (string) ($refund['cf_refund_id'] ?? ($refund['refund_id'] ?? ($refund['merchant_refund_id'] ?? '')));
        if (!$refundId) return;

        $status = strtolower((string) ($refund['refund_status'] ?? ($refund['status'] ?? '')));
        $refundTx = OrderTransaction::query()->where('vendor_charge_id', $refundId)->first();
        if ($refundTx) {
            $refundTx->status = in_array($status, ['success', 'succeeded', 'completed', 'processed'], true) ? 'refunded' : (in_array($status, ['failed', 'cancelled', 'canceled'], true) ? 'failed' : $refundTx->status);
            $refundTx->save();
            return;
        }

        if (in_array($status, ['success', 'succeeded', 'completed', 'processed'], true)) {
            $paymentId = (string) ($refund['cf_payment_id'] ?? ($refund['payment_id'] ?? ''));
            $parentTx = $paymentId ? OrderTransaction::query()->where('vendor_charge_id', $paymentId)->first() : null;
            if ($parentTx && class_exists(Refund::class)) {
                $amt = $this->cashfreeAmount($refund['refund_amount'] ?? 0);
                Refund::createOrRecordRefund([
                    'vendor_charge_id' => $refundId,
                    'payment_method'   => 'cashfree',
                    'payment_mode'     => $parentTx->payment_mode ?? $this->settings->mode(),
                    'status'           => 'refunded',
                    'total'            => $amt > 0 ? $amt : $parentTx->total,
                ], $parentTx);
            }
        }
    }

    private function syncSubscriptionStatus($cashfreeSubscriptionId, $status) {
        if (!$cashfreeSubscriptionId) return null;
        $subscription = Subscription::query()->where('vendor_subscription_id', $cashfreeSubscriptionId)->first();
        if (!$subscription) {
            $tx = $this->findTransactionForSubscription($cashfreeSubscriptionId);
            if ($tx) $subscription = Subscription::query()->where('parent_order_id', $tx->order_id)->first();
        }
        if (!$subscription) return null;

        static $map = [
            'ACTIVE' => 'active', 'ON_HOLD' => 'on-hold', 'COMPLETED' => 'completed',
            'CUSTOMER_CANCELLED' => 'canceled', 'CANCELLED' => 'canceled', 'MERCHANT_CANCELLED' => 'canceled',
            'EXPIRED' => 'expired', 'CARD_EXPIRED' => 'expired', 'BANK_APPROVAL_PENDING' => 'pending',
            'INITIALIZED' => 'pending', 'FAILED' => 'failed',
        ];

        if (isset($map[$status])) {
            $subscription->status = $map[$status];
            $subscription->save();
        }
        return $subscription;
    }

    private function findTransactionForOrder($orderId, array $data = []) {
        $tags = $data['order']['order_tags'] ?? ($data['order_tags'] ?? null);
        if (!empty($tags['fluent_transaction_uuid'])) {
            $tx = OrderTransaction::query()->where('uuid', sanitize_text_field($tags['fluent_transaction_uuid']))->first();
            if ($tx) return $tx;
        }

        $cfPaymentId = (string) ($data['payment']['cf_payment_id'] ?? '');
        if ($cfPaymentId) {
            $tx = OrderTransaction::query()->where('payment_method', 'cashfree')->where('vendor_charge_id', $cfPaymentId)->first();
            if ($tx) return $tx;
        }

        if ($orderId) {
            return OrderTransaction::query()->where('payment_method', 'cashfree')->where('meta', 'LIKE', '%' . $orderId . '%')->latest()->first();
        }
        return null;
    }

    private function findTransactionForSubscription($subscriptionId, array $data = []) {
        $tags = $data['subscription_tags'] ?? ($data['subscription']['subscription_tags'] ?? null);
        if (!empty($tags['fluent_transaction_uuid'])) {
            $tx = OrderTransaction::query()->where('uuid', sanitize_text_field($tags['fluent_transaction_uuid']))->first();
            if ($tx) return $tx;
        }

        $payment = $data['payment'] ?? ($data['payment_details'] ?? $data);
        $paymentId = (string) ($payment['cf_payment_id'] ?? ($payment['payment_id'] ?? ''));
        if ($paymentId) {
            $tx = OrderTransaction::query()->where('payment_method', 'cashfree')->where('vendor_charge_id', $paymentId)->first();
            if ($tx) return $tx;
        }

        if ($subscriptionId) {
            $tx = OrderTransaction::query()->where('payment_method', 'cashfree')->where('meta', 'LIKE', '%' . $subscriptionId . '%')->where('status', 'pending')->latest()->first();
            if ($tx) return $tx;

            $sub = Subscription::query()->where('vendor_subscription_id', $subscriptionId)->first();
            if ($sub && $sub->parent_order_id) {
                return OrderTransaction::query()->where('order_id', $sub->parent_order_id)->where('status', 'pending')->latest()->first();
            }
        }
        return null;
    }

    public function confirmPayment() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'cffc_confirm')) {
            wp_send_json_error(['message' => __('Security check failed.', 'cashfree-fluentcart')], 403);
        }
        $tx = OrderTransaction::query()->where('uuid', sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? '')))->first();
        if (!$tx) {
            wp_send_json_error(['message' => __('Transaction not found.', 'cashfree-fluentcart')], 404);
        }
        $cfOrderId = sanitize_text_field(wp_unslash($_POST['cashfree_order_id'] ?? ''));
        if ($this->verifyAndSucceed($tx, null, $cfOrderId)) {
            wp_send_json_success(['redirect_url' => $this->getReceiptUrl($tx)]);
        }
        wp_send_json_error(['message' => __('Cashfree payment could not be confirmed yet. Please wait...', 'cashfree-fluentcart')], 400);
    }

    public function paymentStatus() {
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'] ?? '')), 'cffc_confirm')) {
            wp_send_json_error(['message' => __('Security check failed.', 'cashfree-fluentcart')], 403);
        }
        $tx = OrderTransaction::query()->where('uuid', sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? '')))->first();
        if (!$tx) {
            wp_send_json_error(['message' => __('Transaction not found.', 'cashfree-fluentcart')], 404);
        }
        if ($this->verifyAndSucceed($tx)) {
            wp_send_json_success(['transaction_status' => 'succeeded', 'redirect_url' => $this->getReceiptUrl($tx)]);
        }
        wp_send_json_success(['transaction_status' => (string) $tx->status]);
    }

    public function handleReturn() {
        if (empty($_GET['cffc_tx'])) return;
        $tx = OrderTransaction::query()->where('uuid', sanitize_text_field(wp_unslash($_GET['cffc_tx'])))->first();
        if ($tx && $tx->status !== 'succeeded') {
            $cfOrderId = isset($_GET['cffc_order_id']) ? sanitize_text_field(wp_unslash($_GET['cffc_order_id'])) : '';
            $subId = isset($_GET['cffc_subscription_id']) ? sanitize_text_field(wp_unslash($_GET['cffc_subscription_id'])) : '';
            $this->verifyAndSucceed($tx, null, $cfOrderId, $subId);
        }
    }

    private function verifyAndSucceed($transaction, $order = null, $cashfreeOrderId = '', $subscriptionId = ''): bool {
        if (!$transaction) return false;
        if ($transaction->status === 'succeeded') return true;

        $order = $order ?: Order::find($transaction->order_id);
        $orderId = $cashfreeOrderId ?: (string) ($transaction->meta['cashfree_order_id'] ?? '');
        $subId = $subscriptionId ?: (string) ($transaction->meta['cashfree_subscription_id'] ?? '');
        $api = new API($this->settings);

        if ($orderId) {
            $currency = strtoupper((string) ($transaction->currency ?: 'INR'));
            $expected = $this->amount($transaction->total, $currency);
            $payments = $api->getPayments($orderId);
            if (!is_wp_error($payments)) {
                foreach ($this->normalizeList($payments) as $p) {
                    if (strtoupper((string) ($p['payment_status'] ?? '')) === 'SUCCESS' && abs($this->cashfreeAmount($p['payment_amount'] ?? 0) - $expected) <= 0.01) {
                        $this->markTransactionSucceeded($transaction, (string) ($p['cf_payment_id'] ?? ''), $order);
                        return true;
                    }
                }
            }
        } elseif ($subId) {
            $sub = $api->getSubscription($subId);
            if (!is_wp_error($sub)) {
                $status = strtoupper((string) ($sub['subscription_status'] ?? ''));
                $auth = strtoupper((string) ($sub['authorization_details']['authorization_status'] ?? ''));
                if (in_array($status, ['ACTIVE', 'INITIALIZED'], true) && ($auth === 'SUCCESS' || $status === 'ACTIVE')) {
                    $chargeId = (string) ($sub['authorization_details']['cf_payment_id'] ?? ($sub['authorization_details']['payment_id'] ?? ''));
                    $this->markTransactionSucceeded($transaction, $chargeId, $order);
                    $this->syncSubscriptionStatus($subId, 'ACTIVE');
                    return true;
                }
            }
        }
        return false;
    }

    private function markTransactionSucceeded($transaction, $vendorChargeId, $order = null) {
        if (!$transaction || $transaction->status === 'succeeded') return;

        $transaction->status = 'succeeded';
        if ($vendorChargeId) $transaction->vendor_charge_id = $vendorChargeId;

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        if ($vendorChargeId) $meta['cashfree_payment_id'] = $vendorChargeId;
        $transaction->meta = $meta;
        $transaction->save();

        $order = $order ?: ($transaction->order_id ? Order::find($transaction->order_id) : null);
        if ($order) {
            (new StatusHelper($order))->syncOrderStatuses($transaction);
        }
        do_action('fluent_cart/payment_success_cashfree', $transaction, $order);
    }

    private function getReceiptUrl($transaction): string {
        if (class_exists(PaymentHelper::class)) {
            $url = (new PaymentHelper('cashfree'))->successUrl($transaction);
            if (!empty($url)) return (string) $url;
        }
        $order = Order::find($transaction->order_id);
        return ($order && method_exists($order, 'getReceiptUrl')) ? (string) $order->getReceiptUrl() : home_url('/');
    }

    private function checkoutResponse($transaction, $type, array $data) {
        $payload = array_merge($data, [
            'type'             => $type,
            'mode'             => $this->settings->isTest() ? 'sandbox' : 'production',
            'transaction_uuid' => (string) $transaction->uuid,
            'transaction_hash' => (string) $transaction->uuid,
            'ajax_url'         => admin_url('admin-ajax.php'),
            'nonce'            => wp_create_nonce('cffc_confirm'),
            'checkout_type'    => 'modal',
            'app_id'           => $this->settings->appId(),
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'cashfree',
            'actionName'   => 'custom',
            'message'      => ($type === 'subscription') ? __('Opening Cashfree subscription checkout...', 'cashfree-fluentcart') : __('Opening Cashfree checkout...', 'cashfree-fluentcart'),
            'payment_args' => $payload,
            'response'     => $payload,
            'data'         => $payload,
        ];
    }

    private function failed($message) {
        return ['status' => 'failed', 'message' => (string) $message];
    }

    public function isSupportedCurrency(string $currency): bool {
        static $lookup = null;
        $currency = strtoupper(trim($currency));
        if ($lookup === null) {
            $supported = apply_filters('cffc_supported_currencies', self::getDefaultSupportedCurrencies());
            $lookup = in_array('*', $supported, true) ? '*' : array_fill_keys(array_map('strtoupper', $supported), true);
        }
        return ($lookup === '*') ? (bool) preg_match('/^[A-Z]{3}$/', $currency) : isset($lookup[$currency]);
    }

    public static function getDefaultSupportedCurrencies(): array {
        return [
            'INR', 'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'SGD', 'AED', 'JPY',
            'NZD', 'CHF', 'HKD', 'MYR', 'THB', 'SAR', 'QAR', 'ZAR', 'SEK',
            'NOK', 'DKK', 'PLN', 'BRL', 'KWD', 'BHD', 'OMR', 'IDR', 'PHP',
            'TWD', 'KRW', 'MXN', 'TRY', 'VND', 'ILS', 'CZK', 'HUF', 'CLP'
        ];
    }

    private function getCurrencyDecimals(string $currency): int {
        static $zero = ['JPY' => 1, 'KRW' => 1, 'VND' => 1, 'CLP' => 1, 'PYG' => 1, 'UGX' => 1, 'RWF' => 1, 'BIF' => 1, 'DJF' => 1, 'GNF' => 1, 'KMF' => 1];
        static $three = ['BHD' => 1, 'KWD' => 1, 'OMR' => 1, 'JOD' => 1, 'TND' => 1];
        $currency = strtoupper(trim($currency));
        return isset($zero[$currency]) ? 0 : (isset($three[$currency]) ? 3 : 2);
    }

    private function amount($value, $currency = 'INR'): float {
        return (float) number_format((float) Helper::toDecimalWithoutComma((int) $value), $this->getCurrencyDecimals($currency), '.', '');
    }

    private function cashfreeAmount($value): float {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function normalizePhone($phone): string {
        $phone = preg_replace('/\D+/', '', (string) $phone);
        if (!$phone) {
            return '';
        }
        $phone = ltrim($phone, '0');
        if (strlen($phone) === 12 && substr($phone, 0, 2) === '91') {
            $phone = substr($phone, 2);
        } elseif (strlen($phone) > 10 && substr($phone, 0, 2) === '91') {
            $phone = substr($phone, -10);
        }
        return $phone;
    }

    private function resolveCustomerDetails($order): array {
        if (is_object($order) && method_exists($order, 'loadMissing')) {
            try {
                $order->loadMissing('customer', 'billingAddress', 'shippingAddress', 'addresses');
            } catch (\Throwable $e) {}
        }

        $customer = is_object($order) ? ($order->customer ?? null) : ($order['customer'] ?? null);

        // Helper to extract phone from any object/array/json
        $getPhone = function($target) {
            if (!$target) return '';
            if (is_string($target)) {
                $dec = json_decode($target, true);
                if (is_array($dec)) $target = $dec;
            }
            if (is_object($target)) {
                return (string) ($target->phone ?? ($target->phone_number ?? ($target->mobile ?? ($target->contact ?? ($target->telephone ?? ($target->cell ?? ''))))));
            }
            if (is_array($target)) {
                return (string) ($target['phone'] ?? ($target['phone_number'] ?? ($target['mobile'] ?? ($target['contact'] ?? ($target['telephone'] ?? ($target['cell'] ?? ''))))));
            }
            return '';
        };

        // Helper to extract email from any object/array/json
        $getEmail = function($target) {
            if (!$target) return '';
            if (is_string($target)) {
                $dec = json_decode($target, true);
                if (is_array($dec)) $target = $dec;
            }
            if (is_object($target)) {
                return (string) ($target->email ?? ($target->user_email ?? ($target->billing_email ?? '')));
            }
            if (is_array($target)) {
                return (string) ($target['email'] ?? ($target['user_email'] ?? ($target['billing_email'] ?? '')));
            }
            return '';
        };

        // Helper to extract name from any object/array/json
        $getName = function($target) {
            if (!$target) return '';
            if (is_string($target)) {
                $dec = json_decode($target, true);
                if (is_array($dec)) $target = $dec;
            }
            $n = '';
            if (is_object($target)) {
                $n = trim((string) ($target->name ?? ''));
                if (!$n) {
                    $n = trim(($target->first_name ?? '') . ' ' . ($target->last_name ?? ''));
                }
            } elseif (is_array($target)) {
                $n = trim((string) ($target['name'] ?? ''));
                if (!$n) {
                    $n = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));
                }
            }
            return $n;
        };

        // 1. Resolve Billing Entity from Order
        $billing = null;
        if (is_object($order)) {
            $billing = $order->billingAddress
                ?: ($order->billing_address
                ?: ($order->shippingAddress
                ?: ($order->shipping_address ?? null)));

            if (!$billing && method_exists($order, 'billingAddress')) {
                try { $billing = $order->billingAddress()->first(); } catch (\Throwable $e) {}
            }
            if (!$billing && method_exists($order, 'billing_address')) {
                try { $billing = $order->billing_address()->first(); } catch (\Throwable $e) {}
            }
            if (!$billing && !empty($order->addresses)) {
                foreach ($order->addresses as $addr) {
                    $type = is_object($addr) ? ($addr->type ?? '') : ($addr['type'] ?? '');
                    if ($type === 'billing') {
                        $billing = $addr;
                        break;
                    }
                }
                if (!$billing && !empty($order->addresses[0])) {
                    $billing = $order->addresses[0];
                }
            }
        } elseif (is_array($order)) {
            $billing = $order['billingAddress'] ?? ($order['billing_address'] ?? null);
        }

        // 2. Direct DB fallback on fluent_cart_order_addresses
        $dbAddress = null;
        if (is_object($order) && !empty($order->id)) {
            global $wpdb;
            try {
                $table = $wpdb->prefix . 'fluent_cart_order_addresses';
                $dbAddress = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE order_id = %d AND (type = 'billing' OR type IS NULL OR type = '') ORDER BY id DESC LIMIT 1",
                    (int) $order->id
                ), ARRAY_A);
                if (!$dbAddress) {
                    $dbAddress = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                        (int) $order->id
                    ), ARRAY_A);
                }
            } catch (\Throwable $e) {}
        }

        // 3. Parse active checkout request (JSON or POST)
        $reqData = [];
        $rawInput = @file_get_contents('php://input');
        if ($rawInput) {
            $decodedJson = json_decode($rawInput, true);
            if (is_array($decodedJson)) {
                $reqData = $decodedJson;
            }
        }

        // Resolve Phone
        $phoneCandidates = [
            $getPhone($billing),
            $getPhone($dbAddress),
            $getPhone($customer),
            $getPhone($order),
            $getPhone($reqData['billing_address'] ?? null),
            $getPhone($reqData['billingAddress'] ?? null),
            $getPhone($reqData['customer'] ?? null),
            $getPhone($reqData['address'] ?? null),
            $getPhone($_POST['billing_address'] ?? null),
            $getPhone($_POST['billingAddress'] ?? null),
            $getPhone($_POST['customer'] ?? null),
            $getPhone($_POST['address'] ?? null),
            $getPhone($_REQUEST['billing_address'] ?? null),
            $getPhone($_REQUEST['billingAddress'] ?? null),
            $reqData['phone'] ?? null,
            $reqData['billing_phone'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['billing_phone'] ?? null,
            $_REQUEST['phone'] ?? null,
        ];
        $rawPhone = '';
        foreach ($phoneCandidates as $cand) {
            if (!empty($cand) && is_string($cand) && strlen(trim($cand)) > 0) {
                $rawPhone = trim($cand);
                break;
            }
        }

        if (!$rawPhone && is_object($order) && method_exists($order, 'getMeta')) {
            $rawPhone = $order->getMeta('billing_phone') ?: ($order->getMeta('phone') ?: ($getPhone($order->getMeta('billing_address')) ?: $getPhone($order->getMeta('billingAddress'))));
        }

        $phone = $this->normalizePhone($rawPhone);
        if (!$phone || strlen($phone) < 10) {
            $phone = (string) apply_filters('cffc_customer_phone_fallback', '9876543210', $order);
            $phone = $this->normalizePhone($phone);
            if (strlen($phone) < 10) {
                $phone = '9876543210';
            }
        }

        // Resolve Email
        $emailCandidates = [
            $getEmail($customer),
            $getEmail($billing),
            $getEmail($dbAddress),
            $getEmail($order),
            $getEmail($reqData['billing_address'] ?? null),
            $getEmail($reqData['billingAddress'] ?? null),
            $getEmail($reqData['customer'] ?? null),
            $getEmail($_POST['billing_address'] ?? null),
            $getEmail($_POST['billingAddress'] ?? null),
            $getEmail($_POST['customer'] ?? null),
            $reqData['email'] ?? null,
            $_POST['email'] ?? null,
            $_REQUEST['email'] ?? null,
        ];
        $email = '';
        foreach ($emailCandidates as $cand) {
            if (!empty($cand) && is_string($cand) && strlen(trim($cand)) > 0) {
                $email = trim($cand);
                break;
            }
        }

        if (!$email && is_user_logged_in()) {
            $email = wp_get_current_user()->user_email;
        }
        if (!$email) {
            $email = (string) apply_filters('cffc_customer_email_fallback', get_option('admin_email'), $order);
        }
        $email = sanitize_email((string) $email);

        // Resolve Name
        $nameCandidates = [
            $getName($billing),
            $getName($dbAddress),
            $getName($customer),
            $getName($order),
            $getName($reqData['billing_address'] ?? null),
            $getName($reqData['billingAddress'] ?? null),
            $getName($reqData['customer'] ?? null),
            $getName($_POST['billing_address'] ?? null),
            $getName($_POST['billingAddress'] ?? null),
            $getName($_POST['customer'] ?? null),
            $reqData['name'] ?? null,
            $_POST['name'] ?? null,
            $_REQUEST['name'] ?? null,
        ];
        $name = '';
        foreach ($nameCandidates as $cand) {
            if (!empty($cand) && is_string($cand) && strlen(trim($cand)) > 0) {
                $name = trim($cand);
                break;
            }
        }

        if (!$name && is_user_logged_in()) {
            $user = wp_get_current_user();
            $name = trim($user->display_name ?: ($user->first_name . ' ' . $user->last_name));
        }
        $name = preg_replace('/[^a-zA-Z0-9\s\.\-]/', '', $name ?: 'Customer');
        $name = trim(substr($name, 0, 100)) ?: 'Customer';

        // Resolve Customer ID
        $cId = (is_object($customer) ? ($customer->id ?? 0) : ($customer['id'] ?? 0))
            ?: (is_object($order) ? ($order->customer_id ?? $order->user_id ?? 0) : ($order['customer_id'] ?? $order['user_id'] ?? 0));

        if ($cId > 0 && !empty($email)) {
            $customerKey = 'fc_cust_' . (int) $cId . '_' . substr(md5(strtolower($email)), 0, 8);
        } elseif ($cId > 0) {
            $customerKey = 'fc_cust_' . (int) $cId;
        } elseif (!empty($email)) {
            $customerKey = 'fc_cust_' . substr(md5(strtolower($email)), 0, 16);
        } else {
            $orderRef = is_object($order) ? ($order->id ?? $order->uuid ?? time()) : time();
            $customerKey = 'fc_cust_' . substr(md5((string) $orderRef . '_' . $phone), 0, 16);
        }

        return [
            'name'        => $name,
            'email'       => $email,
            'phone'       => substr($phone, 0, 15),
            'customer_id' => substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $customerKey), 0, 50),
        ];
    }

    private function resolveNotifyUrl(): string {
        $url = $this->settings->webhookUrl();
        return preg_match('/^(https?:\/\/)?(localhost|127\.0\.0\.1|::1|\.local|\.test|\.example)/i', $url) ? '' : $url;
    }

    private function getOrderDescription($order): string {
        $invoice = is_object($order) ? trim((string) ($order->invoice_no ?? ($order->receipt_number ? 'INV-' . $order->receipt_number : 'FC-' . $order->id))) : 'FC-Order';
        return 'Order #' . $invoice;
    }

    private function mapInterval($interval): ?array {
        static $map = [
            'day' => ['count' => 1, 'type' => 'DAY', 'label' => 'Daily'], 'daily' => ['count' => 1, 'type' => 'DAY', 'label' => 'Daily'],
            'week' => ['count' => 1, 'type' => 'WEEK', 'label' => 'Weekly'], 'weekly' => ['count' => 1, 'type' => 'WEEK', 'label' => 'Weekly'],
            'fortnightly' => ['count' => 2, 'type' => 'WEEK', 'label' => 'Fortnightly'],
            'month' => ['count' => 1, 'type' => 'MONTH', 'label' => 'Monthly'], 'monthly' => ['count' => 1, 'type' => 'MONTH', 'label' => 'Monthly'],
            'quarter' => ['count' => 3, 'type' => 'MONTH', 'label' => 'Quarterly'], 'quarterly' => ['count' => 3, 'type' => 'MONTH', 'label' => 'Quarterly'],
            'half_yearly' => ['count' => 6, 'type' => 'MONTH', 'label' => 'Half-Yearly'],
            'bi-monthly' => ['count' => 2, 'type' => 'MONTH', 'label' => 'Bi-Monthly'], 'bi_monthly' => ['count' => 2, 'type' => 'MONTH', 'label' => 'Bi-Monthly'],
            'year' => ['count' => 1, 'type' => 'YEAR', 'label' => 'Yearly'], 'yearly' => ['count' => 1, 'type' => 'YEAR', 'label' => 'Yearly'],
            'annual' => ['count' => 1, 'type' => 'YEAR', 'label' => 'Yearly'], 'annually' => ['count' => 1, 'type' => 'YEAR', 'label' => 'Yearly'],
        ];
        return $map[strtolower(trim((string) $interval))] ?? null;
    }

    private function calculateNextCycleTimestamp($intervalCount, $intervalType): int {
        $count = max(1, (int) $intervalCount);
        switch (strtoupper((string) $intervalType)) {
            case 'DAY':   return strtotime("+{$count} days");
            case 'WEEK':  return strtotime("+{$count} weeks");
            case 'MONTH': return strtotime("+{$count} months");
            case 'YEAR':  return strtotime("+{$count} years");
            default:      return strtotime('+1 month');
        }
    }

    private function stableId(string $prefix, string $seed, int $max): string {
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $seed)) ?: strtolower(wp_generate_password(16, false, false));
        return substr($prefix . $clean, 0, $max);
    }

    private function idempotencyKey(string $prefix, string $seed): string {
        $h = md5($prefix . '|' . $seed);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 12, 3) . '-8' . substr($h, 15, 3) . '-' . substr($h, 18, 12);
    }

    private function toIsoDate($date, $fallback): string {
        $t = is_numeric($date) ? (int) $date : strtotime((string) $date);
        return gmdate('c', $t ?: (int) $fallback);
    }

    private function getHeaderValue(string $key): string {
        $srv = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        if (!empty($_SERVER[$srv])) return (string) $_SERVER[$srv];
        $alt = strtoupper(str_replace('-', '_', $key));
        if (!empty($_SERVER[$alt])) return (string) $_SERVER[$alt];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $k => $v) {
                if (strcasecmp($k, $key) === 0) return (string) $v;
            }
        }
        return '';
    }

    private function normalizeList($response): array {
        if (!is_array($response)) return [];
        if (isset($response['data']) && is_array($response['data'])) return $response['data'];
        if (isset($response['payments']) && is_array($response['payments'])) return $response['payments'];
        return (array_keys($response) === range(0, count($response) - 1)) ? $response : [$response];
    }
}