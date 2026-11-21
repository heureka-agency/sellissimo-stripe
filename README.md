# Stripe for Sellissimo

## Licensing

Stripe for Sellissimo is a free extension for Sellissimo Core. Install it on any site covered by your Core license. Development and staging are free. Your payment provider charges its own processing fees. [License agreement](LICENSE) · [Site licensing guide](https://sellissimo.heureka-kreativ.hu/docs/licensing)

## Setup

Install the matching packages from your Statamic site:

```sh
composer require sellissimo/core sellissimo/stripe
php please sellissimo:install
```

Use your own Stripe account. Stripe charges its payment processing fees separately. [Stripe pricing](https://stripe.com/pricing)

Open **Store → Settings → Payments**. Under **Stripe → Manage shared credentials**, choose **Test** and enter a secret key and webhook signing secret from the same Stripe test account. Embedded Checkout also uses the corresponding publishable key. **Verify and save credentials** reads the account identity from Stripe. For the selected site, turn off inherited payment methods, enable Stripe, select the test connection and save. Place a test order in the storefront. The page shows the last verified callback and completed checkout separately.

Connect the live account when ready, then select **Live** for the intended site. Connections are shared across sites. Each payment retains its connection, mode and presentation for callbacks, pending sessions and refunds. A replacement secret verified for the same account and mode can service earlier payments. Verify a hosting-managed account before revoking its key and keep access to accounts with outstanding refunds. Back up the commerce database and protect the Laravel encryption key needed to read the credentials.

To manage this connection in hosting, set `SELLISSIMO_STRIPE_SECRET`, `SELLISSIMO_STRIPE_WEBHOOK_SECRET` and, for embedded checkout, `SELLISSIMO_STRIPE_PUBLISHABLE_KEY`. `SELLISSIMO_STRIPE_LOCK_CREDENTIALS=true` restricts new payments to this source and prevents changing shared Stripe credentials in the CP. Rebuild cached configuration after changing hosting values.

Create a Stripe webhook destination pointing to `https://your-site.example/api/sellissimo/stripe/webhook`. Subscribe to `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, and `checkout.session.async_payment_failed`. Use the signing secret for this destination. In local test mode, Stripe CLI can forward these events to the same path on localhost; use its displayed signing secret for that session.

The package defaults to Stripe-hosted Checkout for one-time purchases. Sellissimo supplies a single charge for its frozen order total and retains the itemized receipt. A verified server-side payment commits the reservation. Pending payments remain pending, including payments that settle after the buyer leaves checkout. Expired or failed sessions release stock. The scheduled reconciler recovers missed callbacks.

Issue refunds through the Sellissimo order screen to retain line attribution and download revocation. Use matching test-mode credentials and a test-mode webhook when verifying the checkout and refunds.

If Sellissimo reports that Stripe captured a different amount, compare the order receipt with the original payment in the Stripe Dashboard before fulfilling the order or issuing a refund. This can affect existing orders in currencies such as Serbian dinars and Albanian lek. Review previously settled orders in those currencies as well. Sellissimo leaves mismatches for manual review and preserves the existing records.

### Dashboard refunds and disputes

Also subscribe the webhook destination to `refund.created`, `refund.updated`, `refund.failed`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.updated`, and `charge.dispute.closed`. Stripe may retry these events or send them out of order. Sellissimo reads the current refund or dispute from Stripe and matches its PaymentIntent to the original payment or an amendment.

Dashboard refunds update the order balance and refund history. A partial Dashboard refund has no product attribution. To attribute a partial refund to a purchased item or return, initiate it from the order screen. For a refund already issued in Stripe, use its exact `re_…` ID when recording it manually. Disputes appear in **Orders → Payment disputes** with their amount, state and response deadline. Staff can review the dispute before dispatch and submit evidence in Stripe.

To recover missed events or import earlier Dashboard activity for a particular sale, run `php artisan sellissimo:stripe:reconcile ORDER_UUID`. The command checks each captured Stripe payment associated with the order. Repeating it uses the same refund and dispute records.

### On-site payment

Publish the Stripe configuration with `php artisan vendor:publish --tag=sellissimo-stripe-config`. To embed Stripe Checkout on a particular site, set `'presentation' => ['default' => 'embedded']` in `config/sellissimo-stripe.php` and set `SELLISSIMO_STRIPE_PUBLISHABLE_KEY` to the publishable key for the same Stripe account and mode as the secret key. Unlisted sites use hosted Checkout. An embedded site with no publishable key omits Stripe from new payment choices.

After order review the buyer opens a signed payment page on the shop domain. Stripe.js mounts the payment form there. The charge shows the displayed order number. The secret needed to mount a payment stays out of links and order records. Both presentations use Checkout Sessions, the existing verified webhooks and the same one-time stock and payment reconciliation. Returning from Stripe alone does not mark a purchase paid. Pending attempts retain their original presentation when configuration changes. Additional payments for a return or order edit use the parent order number and retain their own payment reference.

For product support, email [the Sellissimo team](mailto:hello@heureka-kreativ.hu).
