<?php

namespace App\Events;

use App\Models\Contact;
use App\Models\SentNotice;
use App\Support\Notifier;
use Illuminate\Support\Carbon;

/**
 * Once a day (notices:send): the core and the modules send their scheduled
 * notices, e.g. birthday greetings or payment reminders. Each listener
 * picks its people and calls notify(), which skips anyone who already got
 * the notice in the given period and only counts in a dry run.
 */
class ScheduledNoticesDue
{
    /** @var array<string, int> notice key => people it went (or would go) to */
    public array $report = [];

    public function __construct(public bool $dryRun = false)
    {
    }

    /**
     * @param  Carbon|null  $since  not again to someone who got it after this moment;
     *                              null: whenever the listener calls
     * @param  bool  $informational  only on the channels the person consented to
     */
    public function notify(Contact $contact, string $key, array $values, ?string $email, ?string $phone, ?Carbon $since = null, bool $informational = true): bool
    {
        if ($since && SentNotice::where('contact_id', $contact->id)->where('key', $key)->where('sent_at', '>=', $since)->exists()) {
            return false;
        }

        $channels = $this->dryRun
            ? app(Notifier::class)->channels($key, $email, $phone, $contact, $informational)
            : app(Notifier::class)->send($key, $values, $email, $phone, $contact, $informational);
        if ($channels === []) {
            return false;
        }
        if (! $this->dryRun) {
            SentNotice::create(['contact_id' => $contact->id, 'key' => $key, 'sent_at' => now()]);
        }

        $this->report[$key] = ($this->report[$key] ?? 0) + 1;

        return true;
    }
}
