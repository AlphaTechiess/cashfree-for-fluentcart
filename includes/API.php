<?php
namespace CashfreePaymentsFC;

if (!defined('ABSPATH')) {
    exit;
}

class API {
    const API_VERSION = '2026-01-01';

    private $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function getApiVersion(): string {
        return (string) apply_filters('cashfree_fluentcart_api_version', self::API_VERSION);
    }

    private function baseUrl(): string {
        return $this->settings->isTest() ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
    }

    private function request($method, $path, $body = null, $idempotencyKey = '', $version = null) {
        $clientId = $this->settings->appId();
        $clientSecret = $this->settings->secretKey();

        if (!$clientId || !$clientSecret) {
            return new \WP_Error('cffc_credentials', __('Cashfree App ID and Secret Key are required. Please check your settings.', 'cashfree-fluentcart'));
        }

        $headers = [
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'Accept-Encoding' => 'gzip, deflate',
            'x-api-version'   => $version ?: $this->getApiVersion(),
            'x-client-id'     => $clientId,
            'x-client-secret' => $clientSecret,
            'x-request-id'    => wp_generate_uuid4(),
        ];

        if (!empty($idempotencyKey)) {
            $headers['x-idempotency-key'] = (string) $idempotencyKey;
        }

        $args = [
            'method'      => strtoupper($method),
            'timeout'     => 30,
            'httpversion' => '1.1',
            'headers'     => $headers,
        ];

        if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $response = wp_remote_request($this->baseUrl() . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['raw_response' => $raw];
        }

        if ($status >= 400) {
            $msg = '';
            if (!empty($data['message']) && is_string($data['message'])) {
                $msg = $data['message'];
            } elseif (!empty($data['error']) && is_string($data['error'])) {
                $msg = $data['error'];
            } elseif (!empty($data['error']['message']) && is_string($data['error']['message'])) {
                $msg = $data['error']['message'];
            } elseif (!empty($data['type']) && is_string($data['type'])) {
                $msg = $data['type'];
            }
            if (!$msg) {
                $msg = sprintf(__('Cashfree API error (%d).', 'cashfree-fluentcart'), $status);
            }
            return new \WP_Error('cffc_api_' . $status, $msg, ['status' => $status, 'response' => $data]);
        }

        return $data;
    }

    public function createOrder(array $data, $idempotencyKey = '') {
        return $this->request('POST', '/orders', $data, $idempotencyKey);
    }

    public function getOrder($orderId) {
        return $this->request('GET', '/orders/' . rawurlencode((string) $orderId));
    }

    public function getPayments($orderId) {
        return $this->request('GET', '/orders/' . rawurlencode((string) $orderId) . '/payments');
    }

    public function createRefund($orderId, array $data, $idempotencyKey = '') {
        return $this->request('POST', '/orders/' . rawurlencode((string) $orderId) . '/refunds', $data, $idempotencyKey);
    }

    public function createPlan(array $data, $idempotencyKey = '') {
        return $this->request('POST', '/plans', $data, $idempotencyKey);
    }

    public function getPlan($planId) {
        return $this->request('GET', '/plans/' . rawurlencode((string) $planId));
    }

    public function createSubscription(array $data, $idempotencyKey = '') {
        return $this->request('POST', '/subscriptions', $data, $idempotencyKey);
    }

    public function getSubscription($subscriptionId) {
        return $this->request('GET', '/subscriptions/' . rawurlencode((string) $subscriptionId));
    }

    public function getSubscriptionPayments($subscriptionId) {
        return $this->request('GET', '/subscriptions/' . rawurlencode((string) $subscriptionId) . '/payments');
    }

    public function createSubscriptionRefund($subscriptionId, array $data, $idempotencyKey = '') {
        return $this->request('POST', '/subscriptions/' . rawurlencode((string) $subscriptionId) . '/refunds', $data, $idempotencyKey);
    }

    public function manageSubscription($subscriptionId, $action, array $actionDetails = []) {
        $body = [
            'subscription_id' => (string) $subscriptionId,
            'action'          => strtoupper((string) $action),
        ];
        if (!empty($actionDetails)) {
            $body['action_details'] = $actionDetails;
        }
        return $this->request('POST', '/subscriptions/' . rawurlencode((string) $subscriptionId) . '/manage', $body);
    }
}