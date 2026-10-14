<?php

namespace App\Mail;

use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class Welcome extends Mailable implements ShouldQueue
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
    public function build()
    {
        return $this->template('account.welcome', [
            'name' => trim($this->data->name.' '.$this->data->surname),
            'email' => $this->data->email,
        ]);
    }
}
