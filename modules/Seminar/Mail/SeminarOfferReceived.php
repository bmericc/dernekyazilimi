<?php

namespace Modules\Seminar\Mail;

use Modules\Seminar\Models\SeminarOffers;
use App\Mail\Concerns\UsesMailTemplate;
use Modules\Seminar\Mail\Concerns\SeminarValues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SeminarOfferReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate, SeminarValues;

    public function __construct(public SeminarOffers $seminarOffer) {}
    public function build(): self
    {
        return $this->template('seminar.offer-received', $this->offerValues($this->seminarOffer));
    }
}
