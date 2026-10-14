<?php

namespace Modules\EmailChange\Mail;

use Modules\EmailChange\Models\EmailChangeRequest;
use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailChangeRequestProcessed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public EmailChangeRequest $emailChangeRequest)
    {
    }

    public function build(): self
    {
        $request = $this->emailChangeRequest;

        return $this->template($request->status === 'approved' ? 'email-change.approved' : 'email-change.rejected', [
            'name' => trim($request->user?->name.' '.$request->user?->surname),
            'current_email' => $request->current_email,
            'requested_email' => $request->requested_email,
        ]);
    }
}
