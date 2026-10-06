<?php

namespace App\Mail;

use App\Support\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * To a person whose account was opened from the association's web site:
 * the link to set the password.
 */
class AccountCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public string $link)
    {
    }

    public function build(): self
    {
        return $this->subject(app(Organization::class)->name().' portalına hoş geldiniz')
            ->view('emails.account-created', ['minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]);
    }
}
