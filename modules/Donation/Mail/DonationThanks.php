<?php

namespace Modules\Donation\Mail;

use App\Models\Payment;
use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DonationThanks extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public Payment $payment)
    {
    }

    public function build(): self
    {
        return $this->template('donation.thanks', [
            'name' => $this->payment->payer_name,
            'date' => $this->payment->paid_at?->format('d.m.Y'),
            'amount' => $this->payment->formattedAmount(),
            'reference' => $this->payment->reference,
        ]);
    }
}
