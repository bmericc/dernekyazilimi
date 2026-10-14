<?php

namespace Modules\EmailChange\Mail;

use Modules\EmailChange\Models\EmailChangeRequest;
use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailChangeRequestSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public EmailChangeRequest $emailChangeRequest)
    {
    }

    public function build(): self
    {
        $request = $this->emailChangeRequest;

        return $this->template('email-change.submitted', [
            'name' => trim($request->user?->name.' '.$request->user?->surname),
            'current_email' => $request->current_email,
            'requested_email' => $request->requested_email,
            'reason' => $request->reason,
            'admin_url' => route('admin.email-change-requests'),
        ]);
    }
}
