# KOMOJU Payment Plugin for EC-CUBE

The official [KOMOJU](https://komoju.com) payment gateway plugin for [EC-CUBE](https://www.ec-cube.net).

Customers are redirected to KOMOJU's hosted payment page at checkout. Card data never touches your EC-CUBE server (PCI DSS compliant).

## Supported EC-CUBE Versions
4.3.0, 4.3.1, 4.2.0, 4.2.1, 4.2.2, 4.2.3

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

1. Download the plugin archive from [Releases](https://github.com/komoju/komoju-eccube/releases) (or archive the repository root yourself).
2. In the EC-CUBE admin panel, go to **Owner's Store > Plugins > Upload and Install** and upload the archive.
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

## Log Maintenance

New configurations keep operational logs for **90 days**. Existing retention settings
are unchanged: blank or `0` means unlimited. Set a finite period in the plugin settings
if an existing shop should start expiring logs. Protected order-timeline history is
never deleted by retention or the operational-log bulk-delete action.

Schedule this command from the EC-CUBE application, using its normal PHP runtime and
application user (for example, daily during off-peak hours):

```sh
php bin/console komoju:logs:purge --env=prod --no-debug
```

Example crontab entry, adjusting the PHP and application paths for the shop:

```cron
0 3 * * * /usr/bin/php /path/to/eccube/bin/console komoju:logs:purge --env=prod --no-debug
```

The command reports the number of deleted records and exits nonzero on failure.
It skips deletion when configuration is absent or retention is unlimited, and runs
even when operational logging is disabled. Monitor scheduler failures and database
growth. Viewing the log page no longer deletes records; **configure the scheduler
when upgrading**. Run the first cleanup of a large existing log table off-peak.

Webhook verification failures and return/cancel requests rejected before successful
state validation do not create database log entries. This prevents unauthenticated
request noise from growing the plugin log table across workers, without shared
counters. Infrastructure exceptions still reach application logs; authenticated
processing failures and protected payment history remain available. Use web-server
metrics and appropriate edge request limits to monitor rejected requests. This is
not an HTTP rate limiter or a volume cap on authenticated payment events.

Log keyword searches match literal substrings, including `%`, `_`, and `!` rather
than treating them as wildcard syntax. Invalid search submissions retain the last
valid filters. CSV export prefixes potentially executable spreadsheet cells (and
invalid UTF-8 cells) with an apostrophe; automated CSV consumers should account for
this safety prefix. Ordinary Unicode text and CSV quoting are preserved.

## Development

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

See [LICENSE.txt](LICENSE.txt) for license details.
