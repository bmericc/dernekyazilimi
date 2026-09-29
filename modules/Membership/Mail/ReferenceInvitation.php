<?php

namespace Modules\Membership\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipReference;

class ReferenceInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public MembershipReference $reference, public string $link)
    {
    }

    public function build(): self
    {
        return $this->subject($this->reference->applicant->display_name.' sizi üyelik başvurusunda referans gösterdi')->view('membership::emails.reference-invitation');
    }
}
