<?php

namespace Sellissimo\Stripe;

use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Sellissimo\Core\PaymentSettings;

class WebhookController
{
    public function __invoke(Request $request, PaymentEvents $payments)
    {
        $raw = $request->getContent();
        $object = json_decode($raw)->data->object ?? null;
        $target = is_object($object) ? $payments->target($object) : null;
        $settings = app(PaymentSettings::class);
        $configured = false;
        foreach ($settings->signingConnections($target) as [$id, $secret]) {
            if (!$secret) {
                continue;
            }
            $configured = true;
            try {
                $event = Webhook::constructEvent($raw, $request->header('Stripe-Signature', ''), $secret);
                $connection = $target ? $target->payment_connection_id : $id;
                break;
            } catch (\UnexpectedValueException|SignatureVerificationException) {
                continue;
            }
        }
        abort_unless($configured, 503);
        abort_unless(isset($event), 400, 'Invalid webhook.');
        if (is_bool($event->livemode ?? null) && in_array($target?->payment_mode, ['live', 'test'], true)) {
            abort_unless($event->livemode === ($target->payment_mode === 'live'), 400, 'Invalid webhook mode.');
        }
        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'], true)) {
            $payments->session($event->data->object, $connection);
        } elseif (in_array($event->type, ['refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated', 'charge.refunded',
            'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'], true)) {
            $payments->update($event->type, $event->data->object, $connection);
        }

        $settings->callback($connection ?? $settings->deploymentId('stripe'));
        return response()->noContent();
    }
}
