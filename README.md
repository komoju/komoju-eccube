# KOMOJU Payment Plugin for EC-CUBE

The official [KOMOJU](https://komoju.com) payment gateway plugin for [EC-CUBE](https://www.ec-cube.net), Japan's leading open-source e-commerce platform.

Customers are redirected to KOMOJU's hosted payment page at checkout. Card data never touches your EC-CUBE server (PCI DSS compliant).

## Versions

This repository contains two plugin packages. Use the one that matches your EC-CUBE version:

| Directory | EC-CUBE Version | Plugin Code | Latest Version |
|---|---|---|---|
| [`komoju-eccube-4-main/`](komoju-eccube-4-main/) | 4.0 - 4.1 | `Komoju` | 1.0.9 |
| [`komoju-eccube-4-4.2-main/`](komoju-eccube-4-4.2-main/) | 4.2+ | `Komoju42` | 1.1.2 |

Each directory is a self-contained EC-CUBE plugin with its own `composer.json`. See the README inside each directory for version-specific details (Japanese).

## Supported Payment Methods

Payment methods enabled in your KOMOJU account are automatically synced to EC-CUBE:

- Credit cards (Visa, Mastercard, JCB, AMEX, Diners, Discover)
- Konbini (convenience store payments)
- Bank transfer
- Pay-easy
- PayPay
- LINE Pay
- Merpay
- Carrier billing
- E-money (WebMoney, BitCash, NET CASH)

## Features

- **Hosted payment page** -- no card data stored on your server
- **Authorize & capture** or **authorize only** (manual capture from admin)
- **Full and partial refunds** from the EC-CUBE order edit page
- **Webhook-driven status sync** -- order statuses update automatically when payments are captured, refunded, cancelled, expired, or failed
- **Admin audit log** with search, CSV export, retention policy, and toggle
- **KOMOJU dashboard link** on every order for quick reference

## Installation

1. Download the ZIP for your EC-CUBE version from [Releases](https://github.com/komoju/komoju-eccube/releases) (or zip the appropriate directory yourself).
2. In the EC-CUBE admin panel, go to **Owner's Store > Plugins > Upload and Install** and upload the ZIP.
3. Enable the plugin and clear the cache (**Content Management > Cache Management**).
4. Go to **KOMOJU Payment > Settings** and enter your API keys from the [KOMOJU dashboard](https://app.komoju.com).
5. Click **Connect to KOMOJU** to sync available payment methods.
6. Register the displayed webhook endpoint URL and secret in your KOMOJU dashboard's webhook settings.
7. Under **Shop Settings > Delivery Method**, associate KOMOJU payment with your delivery methods.

## Webhook Events

| Event | Action |
|---|---|
| `payment.captured` | Order status set to **Paid** |
| `payment.refunded` | Refund recorded, order status set to **Cancelled** |
| `payment.cancelled` | Order cancelled |
| `payment.expired` | Order cancelled |
| `payment.failed` | Order cancelled |
| `payment.updated` | Status updated based on payment state |

Webhooks are verified using HMAC-SHA256 signature validation.

## Development

There is no standalone build step. The plugins are Symfony bundles managed by EC-CUBE's plugin system.

```bash
# Install PHP dependencies (if working outside EC-CUBE)
composer install

# Regenerate autoload files
composer dump-autoload
```

Plugin installation, enabling, and disabling are done through the EC-CUBE admin panel.

## Testing

Use [KOMOJU test cards](https://docs.komoju.com/en/api/overview/#payment_details) in your test environment to verify the payment flow.

## Security

- HMAC-SHA256 webhook signature verification
- CSRF protection on all admin operations
- No card data passes through EC-CUBE (PCI DSS compliant)
- Pessimistic locking on refund operations to prevent race conditions

## Links

- [KOMOJU Documentation](https://docs.komoju.com)
- [KOMOJU Dashboard](https://app.komoju.com)
- [EC-CUBE Official Site](https://www.ec-cube.net)

## License

See the individual plugin directories for license details.
