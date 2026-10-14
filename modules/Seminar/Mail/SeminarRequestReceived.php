<?php

namespace Modules\Seminar\Mail;

use Modules\Seminar\Models\SeminarRequests;
use App\Mail\Concerns\UsesMailTemplate;
use Modules\Seminar\Mail\Concerns\SeminarValues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SeminarRequestReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate, SeminarValues;

    public function __construct(public SeminarRequests $seminarRequest)
    {
    }

    public function build(): self
    {
        return $this->template('seminar.request-received', $this->requestValues($this->seminarRequest));
    }
}
