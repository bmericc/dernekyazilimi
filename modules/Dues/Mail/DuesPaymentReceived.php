<?php

namespace Modules\Dues\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DuesPaymentReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment, public string $balance)
    {
    }

    public function build(): self
    {
        return $this->subject('Aidat ödemeniz alındı')->view('dues::emails.received');
    }
}
