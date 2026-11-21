@extends('sellissimo::layout')
@section('title', __('sellissimo::shop.payment'))
@section('surface', 'ss-document')
@push('head')
<script defer src="https://js.stripe.com/endive/stripe.js"></script>
@endpush
@section('content')
<header class="ss-page-head">
    <h1>{{ __('sellissimo::shop.payment') }}</h1>
    <p>{{ __('sellissimo::shop.order') }} {{ $number }}</p>
</header>
<div class="ss-panel">
    <p id="stripe-loading" role="status" hidden>{{ __('sellissimo::shop.loading_payment') }}</p>
    <div id="stripe-checkout"></div>
    <noscript>{{ __('sellissimo::shop.stripe_requires_script') }}</noscript>
    <p id="stripe-error" role="alert" hidden>{{ __('sellissimo::shop.online_payment_is_currently_unavailable') }}</p>
    <p><a href="{{ $receiptUrl }}">{{ __('sellissimo::shop.order_receipt') }}</a></p>
</div>
<script>
const paymentLoading = document.getElementById('stripe-loading');
paymentLoading.hidden = false;
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const stripe = Stripe({{ Illuminate\Support\Js::from($key) }});
        const checkout = await stripe.createEmbeddedCheckoutPage({
            fetchClientSecret: async () => {{ Illuminate\Support\Js::from($secret) }},
        });
        checkout.mount('#stripe-checkout');
    } catch {
        document.getElementById('stripe-error').hidden = false;
    } finally {
        paymentLoading.hidden = true;
    }
});
</script>
@endsection
