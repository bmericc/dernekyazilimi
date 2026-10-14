<?php

namespace Modules\Dues\Mail;

use App\Models\Payment;
use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DuesPaymentReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public Payment $payment, public string $balance)
    {
    }

    public function build(): self
    {
        return $this->template('dues.payment-received', [
            'name' => $this->payment->payer_name,
            'date' => $this->payment->paid_at?->format('d.m.Y'),
            'amount' => $this->payment->formattedAmount(),
            'balance' => $this->balance,
            'reference' => $this->payment->reference,
        ]);
    }
}
