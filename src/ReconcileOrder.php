<?php

namespace Sellissimo\Stripe;

use Illuminate\Console\Command;
use Sellissimo\Core\Store;

class ReconcileOrder extends Command
{
    protected $signature = 'sellissimo:stripe:reconcile {order : Sellissimo order ID}';

    protected $description = 'Recover Stripe refunds and disputes for one order and its additional payments';

    public function handle(Store $store, PaymentEvents $payments): int
    {
        $order = $store->table('orders')->where('id', $this->argument('order'))->first();
        if (!$order) {
            $this->error('Order not found.');
            return self::FAILURE;
        }
        $intents = $store->table('amendments')->where('order_id', $order->id)->where('gateway', 'stripe')
            ->where('status', 'applied')->where('difference', '>', 0)->whereNotNull('payment_ref')->get(['payment_ref', 'payment_connection_id']);
        if ($order->gateway === 'stripe' && $order->payment_ref) {
            $intents->push($order);
        }
        if ($intents->isEmpty()) {
            $this->error('This order has no captured Stripe payment.');
            return self::FAILURE;
        }

        foreach ($intents as $capture) {
            Gateway::request(function ($client) use ($capture, $payments) {
                $intent = $capture->payment_ref;
                $filter = ['payment_intent' => $intent, 'limit' => 100];
                foreach ($client->refunds->all($filter)->autoPagingIterator() as $refund) {
                    $payments->update('refund.updated', $refund, $capture->payment_connection_id);
                }
                foreach ($client->disputes->all($filter)->autoPagingIterator() as $dispute) {
                    $payments->update('charge.dispute.updated', $dispute, $capture->payment_connection_id);
                }
            }, $capture->payment_connection_id);
        }
        $this->info('Refunds and disputes are up to date.');
        return self::SUCCESS;
    }
}
