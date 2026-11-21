<?php

namespace Sellissimo\Stripe;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Sellissimo\Core\Contracts\DeterminesPaymentAvailability;
use Sellissimo\Core\Contracts\PaymentGateway;
use Sellissimo\Core\Contracts\ChecksPaymentConnection;
use Sellissimo\Core\Contracts\PaymentUnavailable;
use Sellissimo\Core\Contracts\PaymentRejected;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\PermissionException;
use Sellissimo\Core\Contracts\ReportsPaymentConfiguration;
use Stripe\Exception\ApiErrorException;
use Sellissimo\Core\Money;
use Sellissimo\Core\OrderLinks;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\Store;
use Sellissimo\Core\PaymentSettings;
use Stripe\Exception\InvalidRequestException;

class Gateway implements PaymentGateway, ChecksPaymentConnection, DeterminesPaymentAvailability, ReportsPaymentConfiguration
{
    public static function client(?string $id): object
    {
        $key = app(PaymentSettings::class)->credentials('stripe', $id)['secret'] ?? null;
        throw_unless($key, PaymentUnavailable::class, 'Configure the Stripe secret key to resume payments.');
        return $key === config('sellissimo-stripe.secret') ? app('sellissimo.stripe.client')
            : app()->makeWith('sellissimo.stripe.client', ['secret' => $key]);
    }

    public static function checkConnection(array $credentials): string
    {
        $client = app()->makeWith('sellissimo.stripe.client', ['secret' => $credentials['secret']]);
        $id = $client->accounts->retrieve()->id;
        throw_unless(is_string($id) && str_starts_with($id, 'acct_'), PaymentUnavailable::class, 'Stripe could not verify this account.');
        return $id;
    }

    public function isAvailable(array $quote, string $site): bool
    {
        $settings = app(PaymentSettings::class);
        $presentation = $settings->preferences($site)['presentation'];
        $credentials = $settings->forSite('stripe', $site);
        return isset($credentials['secret']) && ($presentation === 'hosted'
            || ($presentation === 'embedded' && isset($credentials['publishable_key'])));
    }

    public function configurationWarnings(string $site): array
    {
        $issues = [];
        $settings = app(PaymentSettings::class);
        $presentation = $settings->preferences($site)['presentation'];
        $credentials = $settings->forSite('stripe', $site);
        if (!isset($credentials['secret'])) {
            $issues[] = __('sellissimo::shop.set_payment_setting', ['provider' => 'Stripe', 'setting' => 'SELLISSIMO_STRIPE_SECRET']);
        }
        if (!in_array($presentation, ['hosted', 'embedded'], true)) {
            $issues[] = __('sellissimo::shop.stripe_invalid_presentation');
        } elseif ($presentation === 'embedded' && !isset($credentials['publishable_key'])) {
            $issues[] = __('sellissimo::shop.set_payment_setting', ['provider' => 'Stripe Embedded Checkout', 'setting' => 'SELLISSIMO_STRIPE_PUBLISHABLE_KEY']);
        }
        if (!isset($credentials['webhook_secret'])) {
            $issues[] = __('sellissimo::shop.set_payment_setting', ['provider' => 'Stripe webhooks', 'setting' => 'SELLISSIMO_STRIPE_WEBHOOK_SECRET']);
        }

        return $issues;
    }

    /** Run a Checkout API request without exposing its payload or headers in logs or responses. */
    public static function request(callable $operation, ?string $id = null): mixed
    {
        try {
            return $operation(self::client($id));
        } catch (ApiErrorException $error) {
            $status = $error->getHttpStatus() ?: 'connection error';
            throw new PaymentUnavailable("Stripe Checkout is unavailable ($status). Check the Stripe API logs.");
        }
    }

    public function start(object $order): array
    {
        return self::request(fn ($client) => $this->startSession($client, $order), $order->payment_connection_id ?? null);
    }

    private function startSession(object $client, object $order): array
    {
        $created = Carbon::parse($order->created_at)->timestamp;
        $expires = $created + 3600;
        if (now()->timestamp >= $expires - 1860) {
            // The original request may have succeeded before the local write failed.
            foreach ($client->checkout->sessions->all(['created' => ['gte' => $created - 120, 'lte' => $expires], 'limit' => 100])->autoPagingIterator() as $session) {
                if ($session->client_reference_id === $order->id) {
                    return ['id' => $session->id, 'url' => self::paymentUrl($session, $order)];
                }
            }
            // Wait out the creation window and in-flight API requests before releasing stock.
            if (now()->timestamp < $expires + 120) {
                throw new PaymentUnavailable('Waiting for Stripe checkout session reconciliation.');
            }

            return ['status' => 'expired'];
        }
        $return = json_decode($order->receipt, true)['return_url'] ?? URL::temporarySignedRoute('sellissimo.receipt', Carbon::createFromTimestamp($created)->addDay(), ['order' => $order->id]);
        $table = isset($order->order_id) ? 'amendments' : 'orders';
        $payment = app(Store::class)->table($table)->where('id', $order->id);
        $presentation = $payment->value('payment_presentation');
        if ($presentation === null) {
            $requested = app(PaymentSettings::class)->preferences($order->site)['presentation'];
            throw_unless(in_array($requested, ['hosted', 'embedded'], true), PaymentUnavailable::class, 'Choose hosted or embedded in the Stripe presentation settings.');
            (clone $payment)->whereNull('payment_presentation')->update(['payment_presentation' => $requested]);
            $presentation = $payment->value('payment_presentation');
        }
        if (isset($order->order_id) && $presentation !== 'legacy_hosted') {
            // A gateway retry must send precisely the same redirect parameters to Stripe.
            $return = OrderLinks::signed($order, 'sellissimo.receipt', Carbon::createFromTimestamp($created)->addDays(config('sellissimo.access_days')), ['order' => $order->order_id]);
        }
        $embedded = $presentation === 'embedded';
        $credentials = app(PaymentSettings::class)->credentials('stripe', $order->payment_connection_id ?? null);
        throw_if($embedded && !isset($credentials['publishable_key']), PaymentUnavailable::class, 'Set SELLISSIMO_STRIPE_PUBLISHABLE_KEY for embedded payments.');
        $options = ['idempotency_key' => 'sellissimo-checkout-'.$order->id];
        if ($embedded) {
            $options['stripe_version'] = '2026-10-28.endive';
        }
        $display = OrderNumber::display((object) ['id' => $order->order_id ?? $order->id, 'order_number' => $order->order_number ?? null]);
        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment', 'client_reference_id' => $order->id, 'customer_email' => $order->email,
                ...($embedded ? ['ui_mode' => 'embedded_page', 'return_url' => $return] : ['success_url' => $return, 'cancel_url' => $return]), 'expires_at' => $expires,
                'adaptive_pricing' => ['enabled' => false],
                'line_items' => [['quantity' => 1, 'price_data' => [
                    'currency' => strtolower($order->currency), 'unit_amount' => self::stripeAmount((int) $order->total, $order->currency),
                    'product_data' => ['name' => 'Order '.($presentation === 'legacy_hosted' ? $order->id : $display)],
                ]]],
            ], $options);
        } catch (AuthenticationException|PermissionException|InvalidRequestException $error) {
            $status = $error->getHttpStatus();
            if (!in_array($status, [400, 401, 403, 422], true) || $error->getError()?->type === 'idempotency_error') {
                throw $error;
            }
            throw new PaymentRejected("Stripe refused the Checkout Session request (HTTP $status). Check the Stripe API logs.");
        }

        if (!filled($session->id) || $session->client_reference_id !== $order->id) {
            throw new PaymentUnavailable('Stripe returned an unrecognized Checkout Session.');
        }
        return ['id' => $session->id, 'url' => self::paymentUrl($session, $order)];
    }

    public static function paymentUrl(object $session, object $payment): ?string
    {
        if (!in_array($session->ui_mode ?? '', ['embedded', 'embedded_page'], true)) {
            return $session->url ?? null;
        }
        $expires = $session->expires_at ?? Carbon::parse($payment->created_at)->addHour()->timestamp;
        return OrderLinks::signed($payment, 'sellissimo.stripe.embedded', Carbon::createFromTimestamp($expires), ['payment' => $payment->id]);
    }

    public function outcome(object $order): array
    {
        $session = self::request(fn ($client) => $client->checkout->sessions->retrieve($order->session_id, ['expand' => ['payment_intent']]), $order->payment_connection_id ?? null);
        throw_unless($session->client_reference_id === $order->id, 'Stripe session belongs to another order.');
        if ($session->payment_status === 'paid') {
            $currency = strtoupper($session->currency);
            throw_unless($currency === $order->currency && $session->amount_total === self::stripeAmount((int) $order->total, $currency),
                PaymentUnavailable::class, 'The amount paid on Stripe differs from the order. Review the payment before fulfilling it.');

            return ['status' => 'paid', 'amount' => (int) $order->total, 'currency' => $currency, 'reference' => $session->payment_intent->id];
        }
        $failed = $session->status === 'complete' && in_array($session->payment_intent?->status, ['canceled', 'requires_payment_method'], true);

        return ['status' => $session->status === 'expired' || $failed ? 'expired' : 'pending'];
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(object $order, object $refund): string
    {
        return self::request(fn ($client) => $this->refundPayment($client, $order, $refund), $order->payment_connection_id ?? null);
    }

    private function refundPayment(object $client, object $order, object $refund): string
    {
        $api = $client->refunds;
        // Dashboard refunds are observed only. Never create a second payment to the buyer.
        if ($refund->provider_ref ?? null) {
            $current = $api->retrieve($refund->provider_ref);
            throw_unless($current->payment_intent === $order->payment_ref && strtoupper($current->currency) === $order->currency
                && self::minorAmount($current->amount, $order->currency) === (int) $refund->amount, 'Unexpected Stripe refund.');
            return self::refundStatus($current->status);
        }
        foreach ($api->all(['payment_intent' => $order->payment_ref, 'limit' => 100])->autoPagingIterator() as $existing) {
            if (($existing->metadata->sellissimo_refund ?? null) === $refund->id) {
                throw_unless($existing->payment_intent === $order->payment_ref && strtoupper($existing->currency) === $order->currency
                    && self::minorAmount($existing->amount, $order->currency) === (int) $refund->amount,
                    PaymentUnavailable::class, 'The existing Stripe refund differs from the store refund. Review it in Stripe.');
                return $this->remember($refund, $existing);
            }
        }
        $amount = self::stripeAmount((int) $refund->amount, $order->currency);
        // An earlier integration sent the stored integer unchanged, except for ISK and UGX.
        // Check the original capture before sending money against a possibly understated charge.
        if (!in_array($order->currency, ['ISK', 'UGX'], true) && self::stripeDigits($order->currency) !== Money::fractionDigits($order->currency)) {
            $intent = $client->paymentIntents->retrieve($order->payment_ref);
            throw_unless($intent->id === $order->payment_ref && strtoupper($intent->currency) === $order->currency
                && $intent->amount_received === self::stripeAmount((int) $order->total, $order->currency),
                PaymentUnavailable::class, 'Stripe captured a different amount from the original store payment. Review it in Stripe before issuing a refund.');
        }
        try {
            $result = $api->create(['payment_intent' => $order->payment_ref, 'amount' => $amount,
                'metadata' => ['sellissimo_refund' => $refund->id]], ['idempotency_key' => 'sellissimo-refund-'.$refund->id]);
        } catch (InvalidRequestException $error) {
            // A Dashboard refund or dispute can consume the charge after local reservation.
            if (in_array($error->getStripeCode(), ['charge_already_refunded', 'amount_too_large', 'charge_disputed', 'charge_not_refundable', 'refund_disputed_payment'], true)) {
                return 'failed';
            }
            throw $error;
        }

        return $this->remember($refund, $result);
    }

    private function remember(object $local, object $stripe): string
    {
        app(Store::class)->table('refunds')->where('id', $local->id)->whereNull('provider_ref')->update(['provider_ref' => $stripe->id]);
        return self::refundStatus($stripe->status);
    }

    public static function minorAmount(int $amount, string $currency): int
    {
        return self::rescale($amount, self::stripeDigits($currency), Money::fractionDigits($currency), $currency);
    }

    private static function stripeAmount(int $amount, string $currency): int
    {
        return self::rescale($amount, Money::fractionDigits($currency), self::stripeDigits($currency), $currency);
    }

    private static function stripeDigits(string $currency): int
    {
        // Stripe uses hundredths for ISK and UGX, including whole-unit charges and refunds.
        return match ($currency) {
            'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' => 0,
            'BHD', 'JOD', 'KWD', 'OMR', 'TND' => 3,
            default => 2,
        };
    }

    private static function rescale(int $amount, int $fromDigits, int $toDigits, string $currency): int
    {
        $factor = 10 ** abs($toDigits - $fromDigits);
        if ($toDigits >= $fromDigits) {
            $converted = $amount * $factor;
            throw_unless(is_int($converted), PaymentUnavailable::class, "The $currency payment exceeds the supported amount.");
            return $converted;
        }
        throw_if($amount % $factor !== 0, PaymentUnavailable::class, "The $currency amount cannot be recorded exactly. Review the payment in Stripe.");
        return intdiv($amount, $factor);
    }

    public static function refundStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => 'succeeded', 'failed', 'canceled' => 'failed', default => 'pending',
        };
    }
}
