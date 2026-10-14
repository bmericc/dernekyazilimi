<?php

namespace Modules\Membership\Mail;

use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipApplication;

class ApplicationDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public MembershipApplication $application, public bool $approved)
    {
    }

    public function build(): self
    {
        $application = $this->application;

        return $this->template($this->approved ? 'membership.approved' : 'membership.rejected', [
            'name' => $application->contact->display_name,
            'application_no' => $application->reference_no,
            'member_number' => $application->membership->number,
            'joined_at' => $application->membership->joined_at?->format('d.m.Y'),
            'reason' => $application->decision_note,
        ]);
    }
}
