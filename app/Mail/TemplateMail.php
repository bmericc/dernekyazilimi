<?php

namespace App\Mail;

use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * An email made only of a template and its placeholder values, for notices
 * that need no mailable of their own.
 */
class TemplateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    /**
     * @param  array<string, string|int|float|\Illuminate\Support\HtmlString|null>  $values
     */
    public function __construct(public string $key, public array $values = [])
    {
    }

    public function build(): self
    {
        return $this->template($this->key, $this->values);
    }
}
