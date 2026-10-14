<?php

namespace Modules\Membership\Mail;

use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipReference;

class ReferenceInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public MembershipReference $reference, public string $link)
    {
    }

    public function build(): self
    {
        $reference = $this->reference;

        return $this->template('membership.reference-invitation', [
            'name' => $reference->referee->display_name,
            'applicant' => $reference->applicant->display_name,
            'application_no' => $reference->application->reference_no,
            'submitted_at' => $reference->application->submitted_at->format('d.m.Y'),
            'link' => $this->link,
            'expires' => $reference->expires_at->format('d.m.Y'),
        ]);
    }
}
