<?php

namespace App\Jobs;

use App\Contracts\Messaging\SmsSender;
use App\Modules\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The SMS of a template, filled and sent by the queue worker with the text
 * in force at sending time.
 */
class SendTemplateSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, string|int|float|\Illuminate\Support\HtmlString|null>  $values
     */
    public function __construct(public string $key, public array $values, public string $phone)
    {
    }

    public function handle(MailTemplates $templates, SmsSender $sms): void
    {
        if ($text = $templates->renderSms($this->key, $this->values)) {
            $sms->send($this->phone, $text);
        }
    }
}
