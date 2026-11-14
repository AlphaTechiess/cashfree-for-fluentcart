# Cashfree Payments for FluentCart

[![License: GPL v3+](https://img.shields.io/badge/License-GPL%20v3%2B-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4-8892BF.svg)](https://www.php.net/)
[![FluentCart Compatibility](https://img.shields.io/badge/FluentCart-%3E%3D%201.0-6C2BD9.svg)](https://fluentcart.com/)
[![Cashfree API Version](https://img.shields.io/badge/Cashfree%20API-2026--01--01-00B09B.svg)](https://www.cashfree.com/)

Accept one-time payments and recurring subscriptions seamlessly in **FluentCart** using **Cashfree Payments**. Built for maximum performance, security, and a frictionless checkout experience for businesses in India and across the globe.

---

## ✨ Features

- **Seamless Modal Checkout**: Embedded popup overlay that keeps customers on your store without redirecting away.
- **Complete Payment Suite**:
  - **UPI**: Instant payments via Google Pay, PhonePe, Paytm, BHIM, Cred, and all UPI apps.
  - **Cards**: Credit & Debit cards (Visa, Mastercard, RuPay, Maestro, American Express, Diners Club).
  - **Net Banking**: 50+ major Indian banks supported.
  - **Wallets**: Popular digital wallets including Paytm, Mobikwik, Freecharge, Airtel Money, and JioMoney.
  - **Pay Later / Cardless EMI**: Flexible installment options via Cashfree partners.
- **Full Subscriptions & Recurring Payments**:
  - Supports automatic recurring billing with **UPI Autopay**, **eNACH / Net Banking Mandates**, and **Recurring Cards**.
  - Flexible intervals (daily, weekly, monthly, quarterly, semi-annually, yearly).
  - Synchronized subscription status changes and automated renewal payment tracking.
- **Full & Partial Refunds**: Initiate refunds directly from the FluentCart order management screen.
- **Multi-Currency Support**: Accept payments in **INR** and **140+ international currencies** (USD, EUR, GBP, AUD, CAD, AED, SGD, and more).
- **Automated Webhooks (API Version `2026-01-01`)**: Real-time cryptographic signature verification using HMAC-SHA256 to ensure zero lost transactions.
- **Digital Product & Phone Number Compatibility**: Works with digital stores requiring no shipping, accurately extracting customer contact and billing details without manual hassle.
- **Ultra-Lightweight & Secure**: Built with AES-256 encrypted credential storage, memory-cached token handling, zero external dependencies, and a clean footprint under 130 KB.
- **Zero-Footprint Uninstaller**: Full database cleanup upon uninstallation, completely purging all stored options and transients.

---

## 📋 Requirements

* WordPress **5.6** or higher
* PHP **7.4** or higher (PHP 8.0, 8.1, 8.2, 8.3 fully supported)
* **FluentCart** active on your WordPress installation
* An active **[Cashfree Merchant Account](https://www.cashfree.com/)** (Sandbox for testing, Production for live sales)

---

## 🚀 Installation

### Option 1: WordPress Admin (Recommended)
1. Download the latest `cashfree.zip` release from the [Releases](https://github.com/fluentcart/cashfree-for-fluentcart/releases) tab.
2. In your WordPress dashboard, navigate to **Plugins &rarr; Add New &rarr; Upload Plugin**.
3. Choose the downloaded ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

### Option 2: Manual (FTP / SFTP)
1. Extract `cashfree.zip`.
2. Upload the `cashfree` folder to your server's `/wp-content/plugins/` directory.
3. Activate the plugin via **Plugins &rarr; Installed Plugins** in your WordPress admin.

---

## ⚙️ Configuration

### 1. Enable Cashfree in FluentCart
1. In your WordPress admin, go to **FluentCart &rarr; Settings &rarr; Payment Methods**.
2. Click on **Cashfree Payments**.
3. Toggle **Enable Cashfree Payments** to `Active`.

### 2. Enter API Credentials
Log into your **[Cashfree Merchant Dashboard](https://merchant.cashfree.com/)**:
* **For Testing (Sandbox)**:
  1. Switch to **Sandbox** mode in the dashboard header.
  2. Go to **Payment Gateway &rarr; Developers &rarr; API Keys**.
  3. Generate or copy your **Test App ID** and **Test Secret Key**.
  4. Paste them into FluentCart under the **Test credentials** tab.
* **For Production (Live)**:
  1. Switch to **Production** mode in your Cashfree dashboard.
  2. Go to **Payment Gateway &rarr; Developers &rarr; API Keys**.
  3. Generate or copy your **Live App ID** and **Live Secret Key**.
  4. Paste them into FluentCart under the **Live credentials** tab.

### 3. Configure Webhooks
Webhooks allow Cashfree to communicate transaction status updates to FluentCart in real time.

1. In the Cashfree Gateway settings inside FluentCart, copy the generated **Webhook URL**:
   ```text
   https://yourdomain.com/?fluent-cart=fct_payment_listener_ipn&method=cashfree
   ```
2. In your Cashfree Dashboard, navigate to **Payment Gateway &rarr; Developers &rarr; Webhooks**.
3. Click **Add Webhook** and paste the Webhook URL.
4. Select API Version: **`2026-01-01`**.
5. Enable the following events:
   - `PAYMENT_SUCCESS_WEBHOOK`
   - `PAYMENT_FAILED_WEBHOOK`
   - `PAYMENT_USER_DROPPED_WEBHOOK`
   - `ORDER_PAID`
   - `REFUND_STATUS_WEBHOOK`
   - `SUBSCRIPTION_STATUS_CHANGED`
   - `SUBSCRIPTION_PAYMENT_SUCCESS`
   - `SUBSCRIPTION_PAYMENT_FAILED`
6. If you sell subscriptions, also add the same Webhook URL under **Subscriptions &rarr; Webhooks** in Cashfree.

---

## 🔒 Security

* **HMAC-SHA256 Webhook Verification**: Every incoming webhook payload is cryptographically validated using your App Secret Key and timestamp header to defend against replay and forgery attacks.
* **Credential Encryption**: Client secrets are encrypted with WordPress-grade AES encryption before persisting to the database.
* **Idempotency Protection**: Order creation and refund requests contain unique idempotency keys to prevent duplicate billing.

---

## 🧹 Complete Uninstallation

This plugin adheres to strict WordPress data hygiene standards. When deleted via **Plugins &rarr; Delete**, `uninstall.php` automatically executes a complete purge:
- Purges all stored API keys, preferences, and custom table rows.
- Deletes all webhook deduplication transients and memory locks.
- Leaves zero residual database bloat.

---

## 📄 License

This plugin is licensed under the [GNU General Public License v3.0 or later](https://www.gnu.org/licenses/gpl-3.0.html).
