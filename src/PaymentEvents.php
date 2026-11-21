<?php

namespace Sellissimo\Stripe;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Sellissimo\Core\Amendments;
use Sellissimo\Core\Checkout;
use Sellissimo\Core\Contracts\PaymentUnavailable;
use Sellissimo\Core\PaymentSettings;
use Sellissimo\Core\Refunds;
use Sellissimo\Core\Store;

class PaymentEvents
{
    public function __construct(private Store $store, private Checkout $checkout) {}

    public function session(object $session, ?string $connection = null): void
    {
        if (!Str::isUuid($session->client_reference_id ?? '')) {
            return;
        }
        $order = $this->store->table('orders')->where('id', $session->client_reference_id)->where('gateway', 'stripe')->first();
        if ($order && app(PaymentSettings::class)->sameAccount('stripe', $connection, $order->payment_connection_id) && (!$order->session_id || $order->session_id === $session->id)) {
            $this->store->table('orders')->where('id', $order->id)->whereNull('session_id')->update(['session_id' => $session->id, 'checkout_url' => Gateway::paymentUrl($session, $order)]);
            $this->checkout->reconcile($order->id);
            return;
        }
        $amendment = $this->store->table('amendments')->where('id', $session->client_reference_id)->where('gateway', 'stripe')
            ->where('status', 'awaiting_payment')->whereNotNull('started_at')->first();
        if ($amendment && app(PaymentSettings::class)->sameAccount('stripe', $connection, $amendment->payment_connection_id) && (!$amendment->session_id || $amendment->session_id === $session->id)) {
            $payment = app(Amendments::class)->payment($amendment, $this->checkout->order($amendment->order_id));
            $this->store->table('amendments')->where('id', $amendment->id)->whereNull('session_id')->update(['session_id' => $session->id, 'checkout_url' => Gateway::paymentUrl($session, $payment)]);
            app(Amendments::class)->reconcile($amendment->id);
        }
    }

    public function target(object $object): ?object
    {
        $column = isset($object->client_reference_id) ? 'id' : 'payment_ref';
        $value = $object->client_reference_id ?? $object->payment_intent ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 255 || ($column === 'id' && !Str::isUuid($value))) {
            return null;
        }
        foreach (['orders', 'amendments'] as $table) {
            if ($row = $this->store->table($table)->where('gateway', 'stripe')->where($column, $value)->first(['payment_connection_id', 'payment_mode'])) {
                return $row;
            }
        }
        return null;
    }

    private function capture(string $intent, ?string $connection): ?array
    {
        $order = $this->store->table('orders')->where('gateway', 'stripe')->where('payment_ref', $intent)->first();
        if ($order && app(PaymentSettings::class)->sameAccount('stripe', $connection, $order->payment_connection_id)) {
            return [$order->id, null];
        }
        $charge = $this->store->table('amendments')->where('gateway', 'stripe')->where('payment_ref', $intent)->where('status', 'applied')->where('difference', '>', 0)->first();
        return $charge && app(PaymentSettings::class)->sameAccount('stripe', $connection, $charge->payment_connection_id) ? [$charge->order_id, $charge->id] : null;
    }

    public function update(string $type, object $object, ?string $connection = null): void
    {
        $intent = $object->payment_intent ?? null;
        if (!is_string($intent) || $intent === '') {
            return;
        }
        Gateway::request(fn ($client) => $this->updateCapture($client, $intent, $type, $object, $connection), $connection);
    }

    private function updateCapture(object $client, string $intent, string $type, object $object, ?string $connection): void
    {
        $capture = $this->capture($intent, $connection);
        if (!$capture) {
            // Stripe can deliver a refund or dispute before its Checkout completion event.
            foreach ($client->checkout->sessions->all(['payment_intent' => $intent, 'limit' => 1])->data as $session) {
                $this->session($session, $connection);
            }
            $capture = $this->capture($intent, $connection);
        }
        if (!$capture) {
            return;
        }

        [$orderId, $captureId] = $capture;
        $this->store->db()->transaction(function () use ($client, $intent, $type, $object, $orderId, $captureId) {
            $order = $this->store->table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            if (str_starts_with($type, 'charge.dispute.')) {
                $dispute = $client->disputes->retrieve($object->id);
                throw_unless($dispute->payment_intent === $intent, 'Dispute belongs to another payment.');
                $currency = strtoupper($dispute->currency);
                $deadline = $dispute->evidence_details->due_by ?? null;
                $data = ['order_id' => $orderId, 'capture_id' => $captureId, 'status' => $dispute->status,
                    'amount' => Gateway::minorAmount($dispute->amount, $currency), 'currency' => $currency, 'reason' => $dispute->reason, 'cash_checked_at' => now(),
                    'respond_by' => $deadline ? Carbon::createFromTimestampUTC($deadline)->toDateTimeString() : null];
                $disputes = $this->store->table('disputes');
                $previous = (clone $disputes)->where('id', $dispute->id)->first();
                throw_if($previous && ($previous->order_id !== $orderId || $previous->capture_id !== $captureId), 'Dispute belongs to another capture.');
                $disputes->updateOrInsert(['id' => $dispute->id], $data);
                foreach ($dispute->balance_transactions ?? [] as $transaction) {
                    $settlementCurrency = strtoupper($transaction->currency);
                    app(\Sellissimo\Core\DisputeMovements::class)->record('stripe:'.$transaction->id, $dispute->id, $orderId,
                        $settlementCurrency, Gateway::minorAmount($transaction->amount, $settlementCurrency),
                        Gateway::minorAmount($transaction->fee, $settlementCurrency),
                        \Carbon\CarbonImmutable::createFromTimestampUTC($transaction->created));
                }
                if ($previous?->status !== $dispute->status) {
                    $this->store->recordActivity($orderId, 'dispute', 'Payment dispute '.$dispute->id.' · '.Str::headline($dispute->status).'.');
                }
                return;
            }

            $refunds = $type === 'charge.refunded'
                ? $client->refunds->all(['charge' => $object->id, 'limit' => 100])->autoPagingIterator()
                : [$client->refunds->retrieve($object->id)];
            foreach ($refunds as $refund) {
                throw_unless($refund->payment_intent === $intent && strtoupper($refund->currency) === $order->currency, 'Refund belongs to another payment or currency.');
                $amount = Gateway::minorAmount($refund->amount, $order->currency);
                $localId = $refund->metadata->sellissimo_refund ?? null;
                if ($localId) {
                    $booked = $this->store->table('refunds')->where('order_id', $orderId)->where('capture_id', $captureId)->where('id', $localId)->value('amount');
                    throw_if($booked !== null && (int) $booked !== $amount, PaymentUnavailable::class,
                        'The Stripe refund differs from the recorded store refund. Review the existing records before reconciling it.');
                }
                app(Refunds::class)->syncProviderRefund($order, $captureId, $refund->id, $localId, $amount, Gateway::refundStatus($refund->status));
            }
        }, 3);
    }
}
