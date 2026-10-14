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
        return $this->template('donation.thanks', self::values($this->payment));
    }

    public static function values(Payment $payment): array
    {
        return [
            'name' => $payment->payer_name,
            'date' => $payment->paid_at?->format('d.m.Y'),
            'amount' => $payment->formattedAmount(),
            'reference' => $payment->reference,
        ];
    }
}
