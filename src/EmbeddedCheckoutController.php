<?php

namespace Sellissimo\Stripe;

use Illuminate\Http\Request;
use Sellissimo\Core\Amendments;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Contracts\PaymentUnavailable;
use Sellissimo\Core\Http\PrivateResponse;
use Sellissimo\Core\OrderLinks;
use Sellissimo\Core\OrderNumber;
use Sellissimo\Core\Store;

class EmbeddedCheckoutController
{
    public function __invoke(Request $request, string $payment, Store $store)
    {
        $attempt = $store->table('orders')->where('id', $payment)->where('gateway', 'stripe')->first();
        $order = $attempt;
        if (!$attempt) {
            $attempt = $store->table('amendments')->where('id', $payment)->where('gateway', 'stripe')->firstOrFail();
            $order = app(Checkout::class)->order($attempt->order_id);
        }
        abort_unless(OrderLinks::valid($request, $order), 404);
        PrivateResponse::forSite($order->site);
        if (!in_array($attempt->status, ['pending', 'awaiting_payment'], true)) {
            return redirect(Checkout::link($order));
        }
        abort_unless($attempt->payment_presentation === 'embedded' && $attempt->session_id, 404);
        try {
            $session = Gateway::request(fn ($client) => $client->checkout->sessions->retrieve($attempt->session_id, [], ['stripe_version' => '2026-10-28.endive']), $attempt->payment_connection_id ?? null);
            abort_unless($session->client_reference_id === $payment, 404);
            if ($session->status !== 'open') {
                if ($payment === $order->id) {
                    app(Checkout::class)->reconcile($payment);
                } else {
                    app(Amendments::class)->reconcile($payment);
                }
                return redirect(Checkout::link($order));
            }
            $key = app(\Sellissimo\Core\PaymentSettings::class)->credentials('stripe', $attempt->payment_connection_id ?? null)['publishable_key'] ?? null;
            if (blank($session->client_secret) || blank($key)) {
                throw new PaymentUnavailable('Check the Stripe embedded session and SELLISSIMO_STRIPE_PUBLISHABLE_KEY.');
            }
        } catch (PaymentUnavailable $e) {
            report($e);
            return response()->view('sellissimo::payment-unavailable', [
                'retryUrl' => $request->fullUrl(), 'receiptUrl' => Checkout::link($order),
            ], 503, ['Retry-After' => '300']);
        }
        return view('sellissimo-stripe::embedded', [
            'number' => OrderNumber::display($order), 'key' => $key, 'secret' => $session->client_secret,
            'receiptUrl' => Checkout::link($order),
        ]);
    }
}
