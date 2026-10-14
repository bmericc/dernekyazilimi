<?php

namespace App\Mail;

use App\Mail\Concerns\UsesMailTemplate;
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
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public string $name, public string $link)
    {
    }

    public function build(): self
    {
        return $this->template('account.created', [
            'name' => $this->name,
            'link' => $this->link,
            'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            'reset_url' => route('password.request'),
        ]);
    }
}
