<?php

namespace App\Mail\Concerns;

use App\Modules\MailTemplates;

/**
 * A mailable whose subject and body come from an editable email template
 * (App\Modules\MailTemplates), filled when the mail is built: a queued mail
 * goes out with the text in force at sending time.
 */
trait UsesMailTemplate
{
    /**
     * @param  array<string, string|int|float|\Illuminate\Support\HtmlString|null>  $values
     */
    protected function template(string $key, array $values = []): static
    {
        ['subject' => $subject, 'body' => $body] = app(MailTemplates::class)->render($key, $values);

        return $this->subject($subject)->view('emails.template', ['body' => $body]);
    }
}
