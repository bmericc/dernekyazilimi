<?php

namespace Modules\Membership\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipReference;

class ReferenceDeclined extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MembershipReference $reference)
    {
    }

    public function build(): self
    {
        return $this->subject('Referans yanıtı: '.$this->reference->application->reference_no)->view('membership::emails.reference-declined');
    }
}
