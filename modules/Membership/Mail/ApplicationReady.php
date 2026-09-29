<?php

namespace Modules\Membership\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipApplication;

class ApplicationReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MembershipApplication $application)
    {
    }

    public function build(): self
    {
        return $this->subject('Karar bekleyen üyelik başvurusu: '.$this->application->reference_no)->view('membership::emails.application-ready');
    }
}
