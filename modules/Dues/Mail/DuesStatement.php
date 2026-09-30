<?php

namespace Modules\Dues\Mail;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The member's dues balance with a personal payment link: sent on a
 * balance lookup (/odeme) and as a reminder by management.
 */
class DuesStatement extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{label: string, remaining: string}>  $open
     */
    public function __construct(public Contact $contact, public string $balance, public array $open, public string $link, public bool $reminder = false)
    {
    }

    public function build(): self
    {
        return $this->subject($this->reminder ? 'Aidat borcu hatırlatması' : 'Aidat bakiyeniz')->view('dues::emails.statement');
    }
}
