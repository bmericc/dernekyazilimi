<?php

namespace Modules\Membership\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipApplication;

class ApplicationDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MembershipApplication $application, public bool $approved)
    {
    }

    public function build(): self
    {
        return $this->subject('Üyelik başvurunuz: '.($this->approved ? 'kabul edildi' : 'sonuçlandı'))->view('membership::emails.application-decided');
    }
}
