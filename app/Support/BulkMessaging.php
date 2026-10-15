<?php

namespace App\Support;

use App\Contracts\Messaging\SmsSender;
use App\Jobs\SendBulkMessage;
use App\Mail\BulkMail;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\Contact;
use App\Modules\Audiences;
use App\Modules\MailTemplates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Bulk email and SMS to the contacts matching criteria. The recipients are
 * fixed when sending starts; the queue worker then sends a batch
 * (messaging.bulk_per_batch) and comes back after a pause
 * (messaging.bulk_pause), so the mail server and the SMS provider are not
 * flooded. Placeholders are filled per recipient at sending time.
 */
class BulkMessaging
{
    public function __construct(private Audiences $audiences, private MailTemplates $templates, private Consents $consents)
    {
    }

    /**
     * Contacts matching the message's criteria.
     *
     * @return Builder<Contact>
     */
    public function query(BulkMessage $message): Builder
    {
        return $this->audiences->apply(Contact::query(), $message->criteria ?? []);
    }

    /**
     * Who would receive the message on which channel. An address or number
     * shared by several contacts is sent to once.
     *
     * @return array{matched: int, recipients: Collection<int, array{contact: Contact, email: ?string, phone: ?string}>, no_address: int, no_consent: int, duplicates: int}
     */
    public function audience(BulkMessage $message): array
    {
        $consented = [];
        if ($message->informational) {
            foreach (['email', 'sms'] as $channel) {
                if ($message->uses($channel)) {
                    $consented[$channel] = array_flip($this->consents->grantedQuery($channel)->pluck('contact_id')->all());
                }
            }
        }

        $recipients = collect();
        $seen = ['email' => [], 'sms' => []];
        $matched = $noAddress = $noConsent = $duplicates = 0;

        foreach ($this->query($message)->orderBy('id')->lazyById(500) as $contact) {
            $matched++;
            $email = $phone = null;
            $refused = $duplicate = false;

            $address = strtolower(trim((string) $contact->email));
            if ($message->uses('email') && filter_var($address, FILTER_VALIDATE_EMAIL)) {
                if (isset($consented['email']) && ! isset($consented['email'][$contact->id])) {
                    $refused = true;
                } elseif (isset($seen['email'][$address])) {
                    $duplicate = true;
                } else {
                    $email = $seen['email'][$address] = $address;
                }
            }

            $number = preg_replace('/[^\d+]/', '', (string) $contact->phone);
            if ($message->uses('sms') && strlen(preg_replace('/\D/', '', $number)) >= 10) {
                if (isset($consented['sms']) && ! isset($consented['sms'][$contact->id])) {
                    $refused = true;
                } elseif (isset($seen['sms'][$number])) {
                    $duplicate = true;
                } else {
                    $phone = $seen['sms'][$number] = $number;
                }
            }

            if ($email || $phone) {
                $recipients->push(['contact' => $contact, 'email' => $email, 'phone' => $phone]);
            } elseif ($duplicate) {
                $duplicates++;
            } elseif ($refused) {
                $noConsent++;
            } else {
                $noAddress++;
            }
        }

        return ['matched' => $matched, 'recipients' => $recipients, 'no_address' => $noAddress, 'no_consent' => $noConsent, 'duplicates' => $duplicates];
    }

    /**
     * Fix the recipients and hand the sending to the queue worker.
     */
    public function start(BulkMessage $message, int $userId): int
    {
        // Claim the draft first: a second click finds it no longer a draft.
        if (! BulkMessage::whereKey($message->id)->where('status', BulkMessage::DRAFT)->update(['status' => BulkMessage::SENDING, 'started_at' => now()])) {
            return 0;
        }

        $recipients = $this->audience($message)['recipients'];

        DB::transaction(function () use ($message, $recipients, $userId) {
            $now = now();
            foreach ($recipients->chunk(500) as $chunk) {
                BulkMessageRecipient::insert($chunk->map(fn (array $recipient) => [
                    'bulk_message_id' => $message->id,
                    'contact_id' => $recipient['contact']->id,
                    'email' => $recipient['email'],
                    'phone' => $recipient['phone'],
                    'email_status' => $recipient['email'] ? BulkMessageRecipient::PENDING : null,
                    'sms_status' => $recipient['phone'] ? BulkMessageRecipient::PENDING : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            $message->update([
                'status' => $recipients->isEmpty() ? BulkMessage::DONE : BulkMessage::SENDING,
                'total' => $recipients->count(),
                'started_by' => $userId,
                'started_at' => $now,
                'finished_at' => $recipients->isEmpty() ? $now : null,
            ]);
        });

        if ($recipients->isNotEmpty()) {
            SendBulkMessage::dispatch($message->id);
        }

        return $recipients->count();
    }

    /**
     * One batch, run by the queue worker; queues the next batch after the
     * pause while recipients remain. A lock keeps two workers (e.g. after
     * "resume") from sending to the same people.
     */
    public function work(int $messageId): void
    {
        $lock = Cache::lock('bulk-message:'.$messageId, 900);
        if (! $lock->get()) {
            return;
        }

        try {
            $message = BulkMessage::find($messageId);
            if (! $message || $message->status !== BulkMessage::SENDING) {
                return;
            }

            $batch = $message->recipients()->whereNull('processed_at')->orderBy('id')->limit(max(1, (int) config('messaging.bulk_per_batch')))->with('contact')->get();
            foreach ($batch as $recipient) {
                // Stopped from the panel in the middle of a batch.
                if (BulkMessage::whereKey($messageId)->value('status') !== BulkMessage::SENDING) {
                    return;
                }
                $this->deliver($message, $recipient);
            }

            $remaining = $message->recipients()->whereNull('processed_at')->exists();
            if (! $remaining) {
                $message->update(['status' => BulkMessage::DONE, 'finished_at' => now()]);
            }
        } finally {
            $lock->release();
        }

        if ($remaining) {
            SendBulkMessage::dispatch($messageId)->delay(now()->addSeconds((int) config('messaging.bulk_pause')));
        }
    }

    /**
     * Send the message to one recipient (also used for the test to oneself).
     */
    public function deliver(BulkMessage $message, BulkMessageRecipient $recipient): void
    {
        $values = $this->audiences->values($recipient->contact, $message->subject.' '.$message->body.' '.$message->sms);
        $errors = [];

        if ($recipient->email_status === BulkMessageRecipient::PENDING) {
            try {
                ['subject' => $subject, 'body' => $body] = $this->templates->fill((string) $message->subject, (string) $message->body, $values);
                Mail::to($recipient->email)->send(new BulkMail($subject, $body, $message->informational));
                $recipient->email_status = BulkMessageRecipient::SENT;
            } catch (Throwable $e) {
                report($e);
                $recipient->email_status = BulkMessageRecipient::FAILED;
                $errors[] = 'E-posta: '.$e->getMessage();
            }
        }

        if ($recipient->sms_status === BulkMessageRecipient::PENDING) {
            try {
                app(SmsSender::class)->send($recipient->phone, $this->templates->fillSms((string) $message->sms, $values));
                $recipient->sms_status = BulkMessageRecipient::SENT;
            } catch (Throwable $e) {
                report($e);
                $recipient->sms_status = BulkMessageRecipient::FAILED;
                $errors[] = 'SMS: '.$e->getMessage();
            }
        }

        if ($recipient->exists) {
            $recipient->forceFill(['error' => $errors ? mb_substr(implode(' / ', $errors), 0, 500) : null, 'processed_at' => now()])->save();
        } elseif ($errors) {
            throw new \RuntimeException(implode(' / ', $errors));
        }
    }

    /**
     * Sent / failed / waiting per channel.
     *
     * @return array<string, array{sent: int, failed: int, pending: int}>
     */
    public function progress(BulkMessage $message): array
    {
        $progress = [];
        foreach (['email', 'sms'] as $channel) {
            $counts = $message->recipients()->whereNotNull($channel.'_status')
                ->selectRaw($channel.'_status as status, count(*) as total')->groupBy($channel.'_status')->pluck('total', 'status');
            $progress[$channel] = [
                'sent' => (int) ($counts[BulkMessageRecipient::SENT] ?? 0),
                'failed' => (int) ($counts[BulkMessageRecipient::FAILED] ?? 0),
                'pending' => (int) ($counts[BulkMessageRecipient::PENDING] ?? 0),
            ];
        }

        return $progress;
    }
}
