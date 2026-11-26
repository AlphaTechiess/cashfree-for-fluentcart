<?php
namespace CashfreePaymentsFC;

if (!defined('ABSPATH')) exit;

class API {
    public const API_VERSION = '2025-01-01';

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function getApiVersion(): string {
        return (string)apply_filters('cffc_api_version', self::API_VERSION);
    }

    private function baseUrl(): string {
        return $this->settings->isTest() ? 'https://sandbox.cashfree.com/pg' : 'https://api.cashfree.com/pg';
    }

    private function request(string $method, string $path, ?array $body = null, string $idempotencyKey = '', ?string $version = null) {
        $clientId = $this->settings->appId();
        $clientSecret = $this->settings->secretKey();
        if (!$clientId || !$clientSecret) {
            return new \WP_Error('cffc_credentials', __('Cashfree App ID and Secret Key are required. Please check your settings.', 'cashfree-for-fluentcart'));
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-api-version' => $version ?: $this->getApiVersion(),
            'x-client-id' => $clientId,
            'x-client-secret' => $clientSecret,
            'x-request-id' => wp_generate_uuid4(),
        ];
        if ($idempotencyKey !== '') $headers['x-idempotency-key'] = $idempotencyKey;

        $verb = strtoupper($method);
        $args = [
            'method' => $verb,
            'timeout' => 30,
            'httpversion' => '1.1',
            'headers' => $headers,
        ];
        if ($body !== null && in_array($verb, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        $res = wp_remote_request($this->baseUrl() . $path, $args);
        if (is_wp_error($res)) return $res;

        $status = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw_response' => $raw];

        if ($status >= 400) {
            $msg = '';
            if (!empty($data['message']) && is_string($data['message'])) $msg = $data['message'];
            elseif (!empty($data['error']) && is_string($data['error'])) $msg = $data['error'];
            elseif (!empty($data['error']['message']) && is_string($data['error']['message'])) $msg = $data['error']['message'];
            elseif (!empty($data['type']) && is_string($data['type'])) $msg = $data['type'];
            if (!$msg) {
                /* translators: %d: HTTP status code returned by the Cashfree API. */
                $msg = sprintf(__('Cashfree API error (%d).', 'cashfree-for-fluentcart'), $status);
            }

            return new \WP_Error('cffc_api_' . $status, $msg, ['status' => $status, 'response' => $data]);
        }

        return $data;
    }

    public function createOrder(array $data, string $idempotencyKey = '') { return $this->request('POST', '/orders', $data, $idempotencyKey); }
    public function getOrder(string $orderId) { return $this->request('GET', '/orders/' . rawurlencode($orderId)); }
    public function getPayments(string $orderId) { return $this->request('GET', '/orders/' . rawurlencode($orderId) . '/payments'); }
    public function createRefund(string $orderId, array $data, string $idempotencyKey = '') { return $this->request('POST', '/orders/' . rawurlencode($orderId) . '/refunds', $data, $idempotencyKey); }
    public function createPlan(array $data, string $idempotencyKey = '') { return $this->request('POST', '/plans', $data, $idempotencyKey); }
    public function getPlan(string $planId) { return $this->request('GET', '/plans/' . rawurlencode($planId)); }
    public function createSubscription(array $data, string $idempotencyKey = '') { return $this->request('POST', '/subscriptions', $data, $idempotencyKey); }
    public function getSubscription(string $subId) { return $this->request('GET', '/subscriptions/' . rawurlencode($subId)); }
    public function createSubscriptionRefund(string $subId, array $data, string $idempotencyKey = '') { return $this->request('POST', '/subscriptions/' . rawurlencode($subId) . '/refunds', $data, $idempotencyKey); }

    public function manageSubscription(string $subId, string $action, array $actionDetails = []) {
        $body = ['subscription_id' => $subId, 'action' => strtoupper($action)];
        if ($actionDetails) $body['action_details'] = $actionDetails;
        return $this->request('POST', '/subscriptions/' . rawurlencode($subId) . '/manage', $body);
    }
}
