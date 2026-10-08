{{--
    For a form that starts a payment: the checkbox of the payment terms in
    force and the logos the card providers of the purpose ask to be shown.
--}}
<x-agreement-checkbox :key="\App\Models\Agreement::PAYMENT_TERMS" name="payment_terms" />

@if ($logos = app(\App\Support\Payments\Payments::class)->logos($purpose ?? null))
    <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
        @foreach ($logos as $logo)
            <img src="{{ $logo['url'] }}" alt="{{ $logo['label'] }}, Visa, Mastercard" style="height: 28px; max-width: 100%;">
        @endforeach
    </div>
@endif
