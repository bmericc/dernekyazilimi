<?php

namespace App\Listeners;

use App\Events\ScheduledNoticesDue;
use App\Models\Contact;
use App\Support\Organization;

/**
 * Birthday greetings to people holding the affiliations chosen in the
 * organization settings; informational, so only on consented channels.
 * Someone born on 29 February is greeted on the 28th in other years.
 */
class SendBirthdayGreetings
{
    public function __construct(private Organization $organization)
    {
    }

    public function handle(ScheduledNoticesDue $event): void
    {
        $types = array_filter(array_map('intval', explode(',', (string) $this->organization->get('birthday_greeting_types'))));
        if ($types === []) {
            return;
        }

        $today = today();
        $days = [$today->day];
        if ($today->month === 2 && $today->day === 28 && ! $today->isLeapYear()) {
            $days[] = 29;
        }

        Contact::query()
            ->whereNotNull('birthday')
            ->whereMonth('birthday', $today->month)
            ->where(fn ($query) => collect($days)->each(fn (int $day) => $query->orWhereDay('birthday', $day)))
            ->whereHas('affiliations', fn ($query) => $query->active()->whereIn('affiliation_type_id', $types))
            ->each(function (Contact $contact) use ($event, $today) {
                $event->notify($contact, 'contact.birthday', ['name' => $contact->display_name], $contact->email, $contact->phone, $today->copy()->startOfYear());
            });
    }
}
