<?php

namespace Modules\MailForwarding\Mail;

use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForwardingWelcome extends Mailable
{
    use Queueable, SerializesModels, UsesMailTemplate;

    private $data;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): self
    {
        return $this->template('mail-forwarding.welcome', [
            'name' => trim($this->data->name.' '.$this->data->surname),
            'alias' => $this->data->alias,
            'email' => $this->data->email,
            'domain' => ucfirst(substr(strrchr((string) $this->data->alias, '@') ?: '@'.config('mail-forwarding.domain'), 1)),
        ]);
    }
}
