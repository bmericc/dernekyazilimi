<?php

namespace Modules\Donation\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DonationThanks extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment)
    {
    }

    public function build(): self
    {
        return $this->subject('Bağışınız için teşekkürler')->view('donation::emails.thanks');
    }
}
