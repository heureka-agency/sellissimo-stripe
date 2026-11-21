<?php

namespace Sellissimo\Stripe;

use Illuminate\Support\Facades\Route;
use Sellissimo\Core\Registry;
use Statamic\Providers\AddonServiceProvider;
use Stripe\StripeClient;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $commands = [ReconcileOrder::class];

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stripe.php', 'sellissimo-stripe');
        $this->app->bind('sellissimo.stripe.client', fn ($app, array $parameters) => new StripeClient($parameters['secret'] ?? config('sellissimo-stripe.secret')));
    }

    public function bootAddon()
    {
        app(Registry::class)->payment('stripe', new Gateway);
        $this->publishes([__DIR__.'/../config/stripe.php' => config_path('sellissimo-stripe.php')], 'sellissimo-stripe-config');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sellissimo-stripe');
        if (! $this->app->routesAreCached()) {
            Route::get('api/sellissimo/stripe/pay/{payment}', EmbeddedCheckoutController::class)
                ->whereUuid('payment')->middleware(['web', \Sellissimo\Core\Http\PrivateResponse::class, 'signed', 'throttle:60,1'])->name('sellissimo.stripe.embedded');
            Route::post('api/sellissimo/stripe/webhook', WebhookController::class)->name('sellissimo.stripe.webhook');
        }
    }
}
