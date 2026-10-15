<?php

namespace Tests\Feature;

use App\Contracts\Messaging\SmsSender;
use App\Jobs\SendBulkMessage;
use App\Mail\BulkMail;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\Contact;
use App\Models\User;
use App\Support\BulkMessaging;
use App\Support\Consents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BulkMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function contact(string $email, ?int $city = null, array $affiliations = [], ?string $phone = null, bool $consent = true): Contact
    {
        $contact = Contact::create(['first_name' => ucfirst(strtok($email, '@')), 'last_name' => 'Test', 'email' => $email, 'phone' => $phone, 'city_id' => $city]);
        foreach ($affiliations as $key) {
            $contact->affiliate($key);
        }
        app(Consents::class)->set($contact, ['email' => $consent, 'sms' => $consent], 'admin');

        return $contact;
    }

    private function message(array $attributes = []): BulkMessage
    {
        return BulkMessage::create($attributes + [
            'channels' => ['email'],
            'criteria' => [],
            'informational' => true,
            'subject' => 'Merhaba {{first_name}}',
            'body' => '<p>Sayın {{name}}, {{organization}} duyurusu.</p>',
        ]);
    }

    public function test_criteria_pick_volunteers_in_the_chosen_cities(): void
    {
        $this->contact('ankara@example.org', 6, ['volunteer']);
        $this->contact('bolu@example.org', 14, ['volunteer']);
        $this->contact('izmir@example.org', 35, ['volunteer']);
        $this->contact('uye@example.org', 6, ['member']);

        $audience = app(BulkMessaging::class)->audience($this->message(['criteria' => ['affiliation' => ['volunteer'], 'city' => ['6', '14']]]));

        $this->assertSame(['ankara@example.org', 'bolu@example.org'], $audience['recipients']->pluck('email')->sort()->values()->all());
    }

    public function test_informational_messages_go_only_to_consenting_contacts_and_once_per_address(): void
    {
        $this->contact('izinli@example.org');
        $this->contact('izinsiz@example.org', consent: false);
        $this->contact('izinli@example.org');
        Contact::create(['first_name' => 'Adressiz']);

        $audience = app(BulkMessaging::class)->audience($this->message());
        $this->assertSame(['izinli@example.org'], $audience['recipients']->pluck('email')->all());
        $this->assertSame(1, $audience['no_consent']);
        $this->assertSame(1, $audience['duplicates']);
        $this->assertSame(1, $audience['no_address']);

        // A notice about the member's own process goes regardless of consent.
        $audience = app(BulkMessaging::class)->audience($this->message(['informational' => false]));
        $this->assertSame(['izinli@example.org', 'izinsiz@example.org'], $audience['recipients']->pluck('email')->all());
    }

    public function test_sending_goes_in_batches_with_a_pause_and_fills_placeholders(): void
    {
        config(['messaging.bulk_per_batch' => 2, 'messaging.bulk_pause' => 60]);
        Queue::fake();
        Mail::fake();
        foreach (['ayse', 'veli', 'can'] as $i => $name) {
            $this->contact("{$name}@example.org", phone: '0555 111 22 3'.$i);
        }
        $message = $this->message(['channels' => ['email', 'sms'], 'sms' => 'Sn {{name}}, toplanti cuma.']);
        $bulk = app(BulkMessaging::class);

        $this->assertSame(3, $bulk->start($message, User::factory()->create()->id));
        $this->assertSame(BulkMessage::SENDING, $message->fresh()->status);
        Queue::assertPushed(SendBulkMessage::class, 1);

        $bulk->work($message->id);
        Mail::assertSent(BulkMail::class, 2);
        Mail::assertSent(BulkMail::class, fn (BulkMail $mail) => $mail->hasTo('ayse@example.org') && $mail->mailSubject === 'Merhaba Ayse'
            && str_contains($mail->body->toHtml(), 'Sayın Ayse Test'));
        $this->assertCount(2, app(SmsSender::class)->sent);
        $this->assertSame('Sn Ayse Test, toplanti cuma.', app(SmsSender::class)->sent[0]['text']);
        Queue::assertPushed(SendBulkMessage::class, fn (SendBulkMessage $job) => $job->delay !== null);

        $bulk->work($message->id);
        Mail::assertSent(BulkMail::class, 3);
        $this->assertSame(BulkMessage::DONE, $message->fresh()->status);
        $this->assertSame(['email' => ['sent' => 3, 'failed' => 0, 'pending' => 0], 'sms' => ['sent' => 3, 'failed' => 0, 'pending' => 0]], $bulk->progress($message));

        // Starting twice does nothing.
        $this->assertSame(0, $bulk->start($message->fresh(), 1));
        $this->assertSame(3, $message->recipients()->count());
    }

    public function test_a_stopped_message_sends_no_more(): void
    {
        config(['messaging.bulk_per_batch' => 1]);
        Queue::fake();
        Mail::fake();
        $this->contact('bir@example.org');
        $this->contact('iki@example.org');
        $message = $this->message();
        $bulk = app(BulkMessaging::class);
        $bulk->start($message, User::factory()->create()->id);

        $bulk->work($message->id);
        $message->update(['status' => BulkMessage::CANCELLED]);
        $bulk->work($message->id);

        Mail::assertSent(BulkMail::class, 1);
        $this->assertSame(1, $message->recipients()->whereNull('processed_at')->count());
    }

    public function test_management_drafts_reviews_and_starts_a_message(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['role' => 1]);
        $this->contact('gonullu@example.org', 6, ['volunteer']);
        $this->contact('baska@example.org', 6, ['member']);

        $this->actingAs($owner)->get('/admin/bulk-messages/create')->assertOk()->assertSee('Sıfat (sürmekte olan)')->assertSee('İl');

        $this->actingAs($owner)->post('/admin/bulk-messages', [
            'channels' => ['email'],
            'informational' => '1',
            'subject' => 'Gönüllü buluşması',
            'body' => '<p>Merhaba {{first_name}}</p><script>alert(1)</script>',
            'criteria' => ['affiliation' => ['volunteer'], 'city' => ['6'], 'tag' => [], 'type' => ''],
        ])->assertRedirect();

        $message = BulkMessage::sole();
        $this->assertSame(['affiliation' => ['volunteer'], 'city' => ['6']], $message->criteria);
        $this->assertStringNotContainsString('<script>', $message->body);

        $this->actingAs($owner)->get("/admin/bulk-messages/{$message->id}")->assertOk()
            ->assertSee('Sıfat (sürmekte olan): Gönüllü')->assertSee('gonullu@example.org')->assertDontSee('baska@example.org')
            ->assertSee('1 alıcıya gönderimi başlat');

        $this->actingAs($owner)->post("/admin/bulk-messages/{$message->id}/start")->assertRedirect();
        Mail::assertSent(BulkMail::class, fn (BulkMail $mail) => $mail->hasTo('gonullu@example.org'));
        $this->assertSame(BulkMessage::DONE, $message->fresh()->status);
        $this->assertSame(BulkMessageRecipient::SENT, $message->recipients()->sole()->email_status);
        $this->actingAs($owner)->get("/admin/bulk-messages/{$message->id}")->assertOk()->assertSee('gitti');

        // A sent message is no longer editable.
        $this->actingAs($owner)->get("/admin/bulk-messages/{$message->id}/edit")->assertNotFound();
    }

    public function test_bulk_messaging_needs_its_permission(): void
    {
        $user = User::factory()->create(['role' => 3]);

        $this->actingAs($user)->get('/admin/bulk-messages')->assertForbidden();
    }
}
