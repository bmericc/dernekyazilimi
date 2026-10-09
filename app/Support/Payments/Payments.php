<?php

namespace App\Support\Payments;

use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collections shared by the modules (donations, dues...): card payments
 * through the configured gateways, bank transfers confirmed by management,
 * and cash recorded by management. Modules register their purpose and
 * react to PaymentSucceeded / PaymentFailed.
 */
class Payments
{
    /** @var array<string, array{label: string, link: ?Closure}> */
    private array $purposes = [];

    /**
     * @param  Closure(Payment): ?string|null  $link  admin URL of the payable
     */
    public function registerPurpose(string $key, string $label, ?Closure $link = null): void
    {
        $this->purposes[$key] = ['label' => $label, 'link' => $link];
    }

    /**
     * @return array<string, string>
     */
    public function purposes(): array
    {
        return array_map(fn ($purpose) => $purpose['label'], $this->purposes);
    }

    public function purposeLabel(string $key): string
    {
        return $this->purposes[$key]['label'] ?? $key;
    }

    public function payableLink(Payment $payment): ?string
    {
        $link = $this->purposes[$payment->purpose]['link'] ?? null;

        return $link ? $link($payment) : null;
    }

    /**
     * Logos of the active card providers, for the purpose or for all of
     * them: the providers ask for these to be shown where payments are made.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function logos(?string $purpose = null): array
    {
        try {
            $gateways = $purpose === null
                ? PaymentGateway::active()->get()->filter(fn (PaymentGateway $gateway) => $gateway->driverClass())
                : PaymentGateway::for($purpose);
        } catch (Throwable) {
            // Before the payment tables exist (fresh install, migrations).
            return [];
        }

        return $gateways->map(fn (PaymentGateway $gateway) => $gateway->driverClass())->unique()
            ->filter(fn (string $driver) => $driver::logo() && is_file(public_path($driver::logo())))
            ->map(fn (string $driver) => ['label' => $driver::label(), 'url' => asset($driver::logo())])
            ->values()->all();
    }

    /**
     * A pending payment for the payable.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $payer
     */
    public function create(string $purpose, ?Model $payable, float|string $amount, string $method, array $payer = [], ?Contact $contact = null, array $extra = []): Payment
    {
        $payment = new Payment(array_merge([
            'uuid' => (string) Str::uuid(),
            'reference' => $this->newReference(),
            'purpose' => $purpose,
            'contact_id' => $contact?->id,
            'payer_name' => $payer['name'] ?? $contact?->display_name,
            'payer_email' => $payer['email'] ?? $contact?->email,
            'payer_phone' => $payer['phone'] ?? $contact?->phone,
            'amount' => $amount,
            'currency' => config('payments.currency', 'TRY'),
            'method' => $method,
            'status' => Payment::PENDING,
            'ip' => request()?->ip(),
        ], $extra));

        if ($payable) {
            $payment->payable()->associate($payable);
        }
        $payment->save();

        return $payment;
    }

    /**
     * Send the payer to the gateway's payment page; returns its URL.
     */
    public function startCard(Payment $payment, PaymentGateway $gateway, string $returnUrl, array $buyer = []): string
    {
        $payment->forceFill(['payment_gateway_id' => $gateway->id, 'return_url' => $returnUrl])->save();

        return $gateway->client()->start($payment, $buyer, route('payments.callback', $gateway));
    }

    /**
     * The gateway refused to open its payment page: the payment is cancelled
     * and keeps the gateway's own words, for management to read.
     */
    public function startFailed(Payment $payment, Throwable $e): void
    {
        report($e);
        $this->cancel($payment, Str::limit('Kart ödemesi başlatılamadı: '.$e->getMessage(), 250));
    }

    /**
     * The gateway's callback: verify with the gateway and settle the payment.
     */
    public function completeCard(PaymentGateway $gateway, string $token): ?Payment
    {
        $payment = Payment::where('payment_gateway_id', $gateway->id)->where('gateway_token', $token)->first();
        if (! $payment || ! $payment->isPending()) {
            return $payment;
        }

        try {
            $result = $gateway->client()->complete($payment);
        } catch (Throwable $e) {
            report($e);
            $result = new GatewayResult(false, null, 'Ödeme sağlayıcısına ulaşılamadı.');
        }

        $payment->forceFill(['gateway_payment_id' => $result->paymentId, 'gateway_response' => $result->raw, 'note' => $result->message]);

        $result->succeeded ? $this->succeed($payment, now()) : $this->fail($payment);

        return $payment;
    }

    /**
     * Management confirms that a transfer arrived (or records cash).
     */
    public function confirm(Payment $payment, Carbon $paidAt, ?BankAccount $account = null, ?string $note = null): void
    {
        $payment->forceFill([
            'bank_account_id' => $account?->id ?? $payment->bank_account_id,
            'recorded_by' => Auth::id(),
            'note' => $note ?? $payment->note,
        ]);

        $this->succeed($payment, $paidAt);
    }

    public function cancel(Payment $payment, ?string $note = null): void
    {
        $payment->forceFill(['status' => Payment::CANCELLED, 'recorded_by' => Auth::id(), 'note' => $note ?? $payment->note])->save();
    }

    /**
     * Mark a collected payment refunded (the refund itself is made at the
     * bank or in the gateway's panel).
     */
    public function refund(Payment $payment, ?string $note = null): void
    {
        $payment->forceFill(['status' => Payment::REFUNDED, 'recorded_by' => Auth::id(), 'note' => $note ?? $payment->note])->save();
    }

    private function succeed(Payment $payment, Carbon $paidAt): void
    {
        DB::transaction(fn () => $payment->forceFill(['status' => Payment::SUCCEEDED, 'paid_at' => $paidAt])->save());
        event(new PaymentSucceeded($payment));
    }

    private function fail(Payment $payment): void
    {
        $payment->forceFill(['status' => Payment::FAILED])->save();
        event(new PaymentFailed($payment));
    }

    /**
     * "7K3M-9QX2": easy to read out and to write in a transfer description.
     */
    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPRSTUVYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = substr($code, 0, 4).'-'.substr($code, 4);
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }
}
