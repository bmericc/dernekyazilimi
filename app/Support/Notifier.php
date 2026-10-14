<?php

namespace App\Support;

use App\Jobs\SendTemplateSms;
use App\Mail\TemplateMail;
use App\Models\Contact;
use App\Modules\MailTemplates;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a template to a person: the email and, when the template's SMS is
 * on, the SMS too. Transactional notices (a step of a process the person
 * started) go regardless of consent; informational ones (birthday,
 * reminders) only on the channels the contact consented to.
 */
class Notifier
{
    public function __construct(private MailTemplates $templates, private Consents $consents)
    {
    }

    /**
     * @param  array<string, string|int|float|\Illuminate\Support\HtmlString|null>  $values
     * @param  Mailable|null  $mailable  the template's own mailable, else a TemplateMail
     * @return string[] channels it went out on
     */
    public function send(string $key, array $values, ?string $email, ?string $phone, ?Contact $contact = null, bool $informational = false, ?Mailable $mailable = null): array
    {
        $sent = [];

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->may($contact, 'email', $informational)) {
            try {
                Mail::to($email)->send($mailable ?? new TemplateMail($key, $values));
                $sent[] = 'email';
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($this->sms($key, $values, $phone, $contact, $informational)) {
            $sent[] = 'sms';
        }

        return $sent;
    }

    /**
     * The channels send() would use, without sending (for a dry run).
     *
     * @return string[]
     */
    public function channels(string $key, ?string $email, ?string $phone, ?Contact $contact = null, bool $informational = false): array
    {
        return array_values(array_filter([
            $email && filter_var($email, FILTER_VALIDATE_EMAIL) && $this->may($contact, 'email', $informational) ? 'email' : null,
            trim((string) $phone) !== '' && $this->templates->source($key)['sms_enabled'] && $this->may($contact, 'sms', $informational) ? 'sms' : null,
        ]));
    }

    /**
     * Queue only the SMS of a template, e.g. next to a mailable sent
     * elsewhere. False when it has no SMS, it is off or there is no number.
     */
    public function sms(string $key, array $values, ?string $phone, ?Contact $contact = null, bool $informational = false): bool
    {
        $phone = trim((string) $phone);
        if ($phone === '' || ! $this->templates->source($key)['sms_enabled'] || ! $this->may($contact, 'sms', $informational)) {
            return false;
        }

        SendTemplateSms::dispatch($key, $values, $phone);

        return true;
    }

    private function may(?Contact $contact, string $channel, bool $informational): bool
    {
        return ! $informational || ($contact && $this->consents->allows($contact, $channel));
    }
}
