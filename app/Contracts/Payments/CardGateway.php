<?php

namespace App\Contracts\Payments;

use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Payments\GatewayResult;
use Illuminate\Http\Request;

/**
 * A card payment provider with a hosted payment page: card details never
 * reach this application. Drivers are listed in config('payments.drivers')
 * and constructed with their PaymentGateway record (credentials, test mode).
 */
interface CardGateway
{
    public function __construct(PaymentGateway $gateway);

    public static function label(): string;

    /**
     * Credential inputs of the admin form.
     *
     * @return array<string, array{label: string, secret?: bool}>
     */
    public static function credentialFields(): array;

    /**
     * Start the hosted payment for a pending payment and return the URL to
     * send the payer to. Stores the provider's token on the payment.
     *
     * @param  array{identity_number?: ?string, address?: ?string, city?: ?string, item?: string}  $buyer
     */
    public function start(Payment $payment, array $buyer, string $callbackUrl): string;

    /**
     * The provider's token in the callback request, to find the payment.
     */
    public static function tokenFrom(Request $request): ?string;

    /**
     * Ask the provider for the outcome (the callback itself is not trusted).
     */
    public function complete(Payment $payment): GatewayResult;
}
