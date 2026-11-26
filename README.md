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
- **Order confirmation emails** -- EC-CUBE's configured order email and mail history are generated when KOMOJU first finalizes the order, through either the browser return or a webhook
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

## Interrupted Checkout

Returning from KOMOJU before the payment outcome is known shows a payment-status page instead of restarting checkout. The customer can recheck the status, resume the same pending, unexpired session, or cancel it and return to checkout. These actions require the original browser session. Cart edits and another checkout are blocked while that cart has an unresolved KOMOJU order, preserving its items, reserved stock, and points. Accepted payments continue to the normal order-completion page.

Reserved stock and points are restored when the order can no longer be paid:

- **No payment can complete on the session** (it is cancelled, completed, or expired, and its payment is absent, failed, cancelled, or expired): the return or status check restores the reservation and reopens the order for checkout. KOMOJU's "back" link and the status page's cancel button first cancel a session that has no submitted payment.
- **A payment is still in flight** (for example, a session cancelled while its payment is pending): KOMOJU cancels that payment asynchronously, and the `payment.cancelled`/`payment.expired` webhook restores the reservation.

If a payment is nevertheless captured after its reservation was restored, the order is not revived; the capture is recorded in the order's KOMOJU log for review and refund.

KOMOJU sends no notification when a customer leaves a session without paying and never returns. Such orders stay in 決済処理中 with their reservation held until the customer returns to the shop in the same browser. The plugin does not run background cleanup.

## Development

Plugin installation, enabling, and disabling are done through the EC-CUBE admin panel.

## Testing

Use [KOMOJU test cards](https://docs.komoju.com/en/api/overview/#payment_details) in your test environment to verify the payment flow.

Order confirmations use the shop's EC-CUBE mail service when a browser return or webhook first accepts the order, including authorized payments awaiting collection. Repeated callbacks and later capture do not resend. Delivery is not retried automatically; check the mail logs before resending from the admin order. Mail history is not proof of SMTP delivery. Previously completed orders are not emailed retroactively.

Confirmation delivery follows EC-CUBE's normal request transaction: mail can be sent before the outer database commit. A later rollback cannot unsend it, and mail-history database errors can affect that transaction.

CI runs `tests/integration/order_confirmation.php` against fresh EC-CUBE 4.2.3/PHP 7.4 and 4.3.1/PHP 8.1 installations with MailCatcher on `[self-hosted, public-release]` runners. Installation and regression checks run as the isolated non-root build user, with the validation database inside that job's `TMPDIR`. The checks cover actual SMTP delivery, customer and shop Bcc recipients, text/HTML content, duplicate callbacks, authorization followed by capture, rejected amounts, and SMTP failure without automatic retry.

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
