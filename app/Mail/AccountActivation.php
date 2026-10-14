<?php

namespace App\Mail;

use App\Support\AccountActivation as Activation;
use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountActivation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    public function __construct(public string $name, public string $link)
    {
    }

    public function build(): self
    {
        return $this->template('account.activation', ['name' => $this->name, 'link' => $this->link, 'hours' => Activation::LINK_HOURS]);
    }
}
