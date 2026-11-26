<?php
namespace CashfreePaymentsFC;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentHelper;

class Settings extends BaseGatewaySettings {
    public $methodHandler = 'fluent_cart_payment_settings_cashfree';

    private array $decryptedKeys = [];
    private ?string $resolvedMode = null;
    private ?string $cachedWebhookUrl = null;

    public static function getDefaults(): array {
        return [
            'is_active'       => 'no',
            'title'           => 'Cashfree Payments',
            'description'     => 'Pay securely using UPI, Cards, NetBanking, and Wallets via Cashfree.',
            'payment_mode'    => 'live',
            'live_app_id'     => '',
            'live_secret_key' => '',
            'test_app_id'     => '',
            'test_secret_key' => '',
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings = []): array {
        if (!is_array($data)) return [];
        if (!is_array($oldSettings)) $oldSettings = [];

        foreach (['live_secret_key', 'test_secret_key'] as $key) {
            $newVal = isset($data[$key]) ? trim((string)$data[$key]) : '';
            $oldVal = isset($oldSettings[$key]) ? (string)$oldSettings[$key] : '';

            if ($newVal === '' || preg_match('/^\*+$/', $newVal)) {
                $data[$key] = $oldVal;
            } elseif (class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && method_exists(Helper::class, 'encryptKey')) {
                if (!Helper::isValueEncrypted($newVal)) {
                    $data[$key] = Helper::encryptKey($newVal);
                }
            }
        }

        if (isset($data['title'])) $data['title'] = sanitize_text_field(trim((string)$data['title']));
        if (isset($data['description'])) $data['description'] = sanitize_textarea_field(trim((string)$data['description']));
        if (isset($data['live_app_id'])) $data['live_app_id'] = sanitize_text_field(trim((string)$data['live_app_id']));
        if (isset($data['test_app_id'])) $data['test_app_id'] = sanitize_text_field(trim((string)$data['test_app_id']));
        if (isset($data['payment_mode'])) $data['payment_mode'] = ($data['payment_mode'] === 'test') ? 'test' : 'live';
        if (isset($data['is_active'])) $data['is_active'] = ($data['is_active'] === 'yes') ? 'yes' : 'no';

        return $data;
    }

    public function get($key = '', $default = null) {
        if ($key === '' || $key === null) return $this->settings;
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function getMode(): string {
        if ($this->resolvedMode !== null) return $this->resolvedMode;
        if (class_exists('\FluentCart\Api\StoreSettings')) {
            try {
                $orderMode = (new \FluentCart\Api\StoreSettings())->get('order_mode');
                if ($orderMode === 'test' || $orderMode === 'live') {
                    return $this->resolvedMode = $orderMode;
                }
            } catch (\Throwable $e) {}
        }
        return $this->resolvedMode = ($this->get('payment_mode', 'live') === 'test' ? 'test' : 'live');
    }

    public function mode(): string { return $this->getMode(); }
    public function isTest(): bool { return $this->getMode() === 'test'; }
    public function isActive(): bool { return $this->get('is_active', 'no') === 'yes'; }

    public function appId(?string $mode = null): string {
        $m = $mode ?: $this->getMode();
        return trim((string)$this->get($m === 'test' ? 'test_app_id' : 'live_app_id', ''));
    }

    public function secretKey(?string $mode = null): string {
        $m = $mode ?: $this->getMode();
        if (isset($this->decryptedKeys[$m])) return $this->decryptedKeys[$m];

        $val = (string)$this->get($m === 'test' ? 'test_secret_key' : 'live_secret_key', '');
        if ($val === '') return $this->decryptedKeys[$m] = '';

        if (class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && Helper::isValueEncrypted($val)) {
            $dec = Helper::decryptKey($val);
            return $this->decryptedKeys[$m] = ($dec === false ? '' : trim((string)$dec));
        }
        return $this->decryptedKeys[$m] = trim($val);
    }

    public function webhookUrl(): string {
        if ($this->cachedWebhookUrl !== null) return $this->cachedWebhookUrl;
        if (class_exists(PaymentHelper::class)) {
            $listener = (new PaymentHelper('cashfree'))->listenerUrl();
            if (!empty($listener['listener_url'])) {
                $raw = html_entity_decode((string)$listener['listener_url'], ENT_QUOTES, 'UTF-8');
                return $this->cachedWebhookUrl = esc_url_raw($raw);
            }
        }
        return $this->cachedWebhookUrl = esc_url_raw(add_query_arg(['fluent-cart' => 'fct_payment_listener_ipn', 'method' => 'cashfree'], home_url('/')));
    }
}
