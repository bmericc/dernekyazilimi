<?php

namespace Tests\Feature;

use App\Contracts\Messaging\SmsSender;
use App\Jobs\SendTemplateSms;
use App\Mail\TemplateMail;
use App\Models\AffiliationType;
use App\Models\Contact;
use App\Models\MailTemplate;
use App\Models\SentNotice;
use App\Models\User;
use App\Modules\MailTemplates;
use App\Support\Consents;
use App\Support\Notifier;
use App\Support\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScheduledNoticesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: string}> */
    public array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00');
        $test = $this;
        $this->app->instance(SmsSender::class, new class($test) implements SmsSender
        {
            public function __construct(private ScheduledNoticesTest $test)
            {
            }

            public function send(string $phone, string $text): void
            {
                $this->test->sms[] = [$phone, $text];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(array $attributes, ?string $affiliation = 'member', array $consents = ['email' => true, 'sms' => true]): Contact
    {
        $contact = Contact::create($attributes + ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $affiliation && $contact->affiliate($affiliation);
        $consents && app(Consents::class)->set($contact, $consents, 'test');

        return $contact;
    }

    public function test_the_sms_of_a_template_is_edited_turned_on_and_sent_with_the_email(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/mail-templates')->assertOk()->assertSee('SMS kapalı');
        $this->actingAs($owner)->get('/admin/mail-templates/contact.birthday')->assertOk()->assertSee('E-postayla birlikte SMS de gönder');

        // Off by default: only the email goes.
        Mail::fake();
        $this->assertSame(['email'], app(Notifier::class)->send('contact.birthday', ['name' => 'Ada'], 'ada@example.org', '05551112233'));

        $this->actingAs($owner)->put('/admin/mail-templates/contact.birthday', [
            'subject' => 'Doğum gününüz kutlu olsun',
            'body' => app(MailTemplates::class)->get('contact.birthday')['body'],
            'sms_enabled' => '1',
            'sms' => 'İyi ki doğdun {{name}}! {{organization_short}}',
        ])->assertRedirect();
        $this->assertTrue(MailTemplate::where('key', 'contact.birthday')->value('sms_enabled'));

        $this->assertSame(['email', 'sms'], app(Notifier::class)->send('contact.birthday', ['name' => 'Ada'], 'ada@example.org', '05551112233'));
        $this->assertSame([['05551112233', 'İyi ki doğdun Ada! '.app(Organization::class)->shortName()]], $this->sms);

        // A template without an SMS never sends one.
        $this->assertSame(['email'], app(Notifier::class)->send('account.activation', ['name' => 'Ada'], 'ada@example.org', '05551112233'));
    }

    public function test_birthday_greetings_go_once_a_year_to_the_chosen_affiliations_with_consent(): void
    {
        Mail::fake();
        Bus::fake([SendTemplateSms::class]);
        MailTemplate::create(['key' => 'contact.birthday', 'sms_enabled' => true]);

        $member = $this->person(['email' => 'uye@example.org', 'phone' => '05550000001', 'birthday' => '1990-10-15']);
        $this->person(['email' => 'izinsiz@example.org', 'birthday' => '1985-10-15'], consents: []);
        $this->person(['email' => 'gonullu@example.org', 'birthday' => '1992-10-15'], 'volunteer');
        $this->person(['email' => 'yarin@example.org', 'birthday' => '1990-10-16']);

        // Nothing until an affiliation is chosen.
        $this->artisan('notices:send')->expectsOutput('Gönderilecek bildirim yok.')->assertSuccessful();
        Mail::assertNothingQueued();

        app(Organization::class)->save(['birthday_greeting_types' => (string) AffiliationType::where('key', 'member')->value('id')]);

        $this->artisan('notices:send --dry-run')->expectsOutput('[deneme] contact.birthday: 1 kişi')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->artisan('notices:send')->expectsOutput('contact.birthday: 1 kişi')->assertSuccessful();
        Mail::assertQueued(TemplateMail::class, 1);
        Mail::assertQueued(TemplateMail::class, fn (TemplateMail $mail) => $mail->hasTo('uye@example.org') && $mail->key === 'contact.birthday');
        Bus::assertDispatched(SendTemplateSms::class, fn (SendTemplateSms $job) => $job->phone === '05550000001');
        $this->assertSame(1, SentNotice::where('contact_id', $member->id)->count());

        // Not twice the same year.
        $this->artisan('notices:send')->expectsOutput('Gönderilecek bildirim yok.');
        Mail::assertQueued(TemplateMail::class, 1);
    }

    public function test_a_29_february_birthday_is_greeted_on_the_28th_in_other_years(): void
    {
        Mail::fake();
        Carbon::setTestNow('2027-02-28 10:00');
        app(Organization::class)->save(['birthday_greeting_types' => (string) AffiliationType::where('key', 'member')->value('id')]);
        $this->person(['email' => 'subat@example.org', 'birthday' => '2000-02-29']);

        $this->artisan('notices:send')->expectsOutput('contact.birthday: 1 kişi');
    }
}
