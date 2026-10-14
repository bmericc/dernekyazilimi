<?php

namespace Modules\Dues\Mail;

use App\Models\Contact;
use App\Mail\Concerns\UsesMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;
use Modules\Dues\Support\DuesService;

/**
 * The member's dues balance with a personal payment link: sent on a
 * balance lookup (/odeme) and as a reminder by management.
 */
class DuesStatement extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, UsesMailTemplate;

    /**
     * @param  array<int, array{label: string, remaining: string}>  $open
     */
    public function __construct(public Contact $contact, public string $balance, public array $open, public string $link, public bool $reminder = false)
    {
    }

    public function build(): self
    {
        $charges = $this->open === [] ? '' : '<ul>'.collect($this->open)->map(fn (array $item) => '<li>'.e($item['label']).': '.e($item['remaining']).'</li>')->implode('').'</ul>';

        return $this->template($this->reminder ? 'dues.reminder' : 'dues.statement', [
            'name' => $this->contact->display_name,
            'balance' => $this->balance,
            'charges' => new HtmlString($charges),
            'link' => $this->link,
            'days' => DuesService::LINK_DAYS,
            'date' => today()->format('d.m.Y'),
        ]);
    }
}
