<?php

namespace App\Mail;

use App\Support\Organization;
use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;

/**
 * One recipient's copy of a bulk message, placeholders filled. Sent by the
 * queue worker's batch itself, so it is not queued again.
 */
class BulkMail extends Mailable
{
    public function __construct(public string $mailSubject, public HtmlString $body, public bool $informational = true)
    {
    }

    public function build(): self
    {
        [$address, $name] = app(Organization::class)->sender();

        return $this->from($address, $name)->subject($this->mailSubject)
            ->view('emails.bulk', ['body' => $this->body, 'informational' => $this->informational]);
    }
}
