<?php

namespace Modules\Membership\Mail;

use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Membership\Models\MembershipReference;

class ReferenceDeclined extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public MembershipReference $reference)
    {
    }

    public function build(): self
    {
        $reference = $this->reference;

        return $this->template('membership.reference-declined', [
            'name' => $reference->applicant->display_name,
            'referee' => $reference->referee->display_name,
            'application_no' => $reference->application->reference_no,
            'application_url' => route('membership.application'),
        ]);
    }
}
