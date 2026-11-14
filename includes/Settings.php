<?php
namespace CashfreePaymentsFC;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentHelper;

class Settings extends BaseGatewaySettings {
    public $methodHandler = 'fluent_cart_payment_settings_cashfree';

    /**
     * In-memory cache for decrypted secret keys and resolved values to eliminate redundant OpenSSL calls.
     */
    private $decryptedKeys = [];
    private $resolvedMode = null;
    private $cachedWebhookUrl = null;

    public static function getDefaults() {
        return [
            'is_active'       => 'no',
            'title'           => __('Cashfree Payments', 'cashfree-fluentcart'),
            'description'     => __('Pay securely using UPI, Cards, NetBanking, and Wallets via Cashfree.', 'cashfree-fluentcart'),
            'payment_mode'    => 'live',
            'live_app_id'     => '',
            'live_secret_key' => '',
            'test_app_id'     => '',
            'test_secret_key' => '',
        ];
    }

    /**
     * Hook before saving settings into database: handle encryption, masking, and sanitization.
     */
    public static function beforeSettingsUpdate($data, $oldSettings = []): array {
        if (!is_array($oldSettings)) {
            $oldSettings = [];
        }

        foreach (['live_secret_key', 'test_secret_key'] as $key) {
            $newVal = isset($data[$key]) ? trim((string) $data[$key]) : '';
            $oldVal = isset($oldSettings[$key]) ? (string) $oldSettings[$key] : '';

            // If empty or masked asterisks, retain the old value
            if ($newVal === '' || preg_match('/^\*+$/', $newVal)) {
                $data[$key] = $oldVal;
            } elseif (method_exists(Helper::class, 'isValueEncrypted') && method_exists(Helper::class, 'encryptKey')) {
                if (!Helper::isValueEncrypted($newVal)) {
                    $data[$key] = Helper::encryptKey($newVal);
                }
            }
        }

        if (isset($data['description'])) {
            $data['description'] = sanitize_textarea_field(trim((string) $data['description']));
        }
        if (isset($data['live_app_id'])) {
            $data['live_app_id'] = sanitize_text_field(trim((string) $data['live_app_id']));
        }
        if (isset($data['test_app_id'])) {
            $data['test_app_id'] = sanitize_text_field(trim((string) $data['test_app_id']));
        }
        if (isset($data['payment_mode'])) {
            $data['payment_mode'] = ($data['payment_mode'] === 'test') ? 'test' : 'live';
        }
        if (isset($data['is_active'])) {
            $data['is_active'] = ($data['is_active'] === 'yes') ? 'yes' : 'no';
        }

        return $data;
    }

    public function get($key = '', $default = null) {
        if ($key === '' || $key === null) {
            return $this->settings;
        }
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function getMode(): string {
        if ($this->resolvedMode !== null) {
            return $this->resolvedMode;
        }
        return $this->resolvedMode = ($this->get('payment_mode', 'live') === 'test' ? 'test' : 'live');
    }

    public function isTest(): bool {
        return $this->getMode() === 'test';
    }

    public function isActive(): bool {
        return $this->get('is_active', 'no') === 'yes';
    }

    public function mode(): string {
        return $this->getMode();
    }

    public function appId($mode = null): string {
        $mode = $mode ?: $this->getMode();
        $key = ($mode === 'test') ? 'test_app_id' : 'live_app_id';
        return trim((string) $this->get($key, ''));
    }

    public function secretKey($mode = null): string {
        $mode = $mode ?: $this->getMode();
        if (isset($this->decryptedKeys[$mode])) {
            return $this->decryptedKeys[$mode];
        }

        $key = ($mode === 'test') ? 'test_secret_key' : 'live_secret_key';
        $value = $this->get($key, '');
        if (!$value) {
            return $this->decryptedKeys[$mode] = '';
        }

        if (method_exists(Helper::class, 'isValueEncrypted') && Helper::isValueEncrypted($value)) {
            $decrypted = Helper::decryptKey($value);
            return $this->decryptedKeys[$mode] = ($decrypted === false ? '' : (string) $decrypted);
        }

        return $this->decryptedKeys[$mode] = (string) $value;
    }

    /**
     * Primary webhook listener URL for FluentCart (memoized per request).
     */
    public function webhookUrl(): string {
        if ($this->cachedWebhookUrl !== null) {
            return $this->cachedWebhookUrl;
        }

        if (class_exists(PaymentHelper::class)) {
            $listener = (new PaymentHelper('cashfree'))->listenerUrl();
            if (!empty($listener['listener_url'])) {
                return $this->cachedWebhookUrl = (string) $listener['listener_url'];
            }
        }

        return $this->cachedWebhookUrl = site_url('/?fluent-cart=fct_payment_listener_ipn&method=cashfree');
    }
}
