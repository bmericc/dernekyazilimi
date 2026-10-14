<?php

namespace Modules\Dues\Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\User;
use App\Support\IdentityCheck;
use App\Support\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Support\DuesService;
use Modules\Membership\Mail\ApplicationDecided;
use Modules\Membership\Mail\ApplicationReady;
use Modules\Membership\Mail\ReferenceInvitation;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipFee;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

/**
 * The whole way in: the form is entered by management, the references
 * confirm by email link, the entry fee is paid, the board accepts.
 */
class MembershipProcessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        Mail::fake();
        $this->app->instance(IdentityCheck::class, new class extends IdentityCheck
        {
            public function __construct()
            {
            }

            public function verify(string $identityNumber, string $name, string $surname, int $birthYear): bool
            {
                return true;
            }
        });
        $this->owner = User::factory()->create(['role' => 1]);
        MembershipFee::create(['year' => 2026, 'entry_fee' => 150, 'annual_fee' => 400]);
        app(Organization::class)->save(['membership_references_required' => '2', 'membership_entry_fee' => '1', 'notification_email' => 'yk@example.org']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $number, string $surname): User
    {
        $user = User::factory()->create(['surname' => $surname]);
        app(MembershipService::class)->start($user->contact, $number, today()->subYears(2));

        return $user;
    }

    private function form(array $overrides = []): array
    {
        return array_replace([
            'submitted_on' => '2026-10-10',
            'signed_form' => '1',
            'gender' => 'female',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'address' => 'Kızılay, Ankara',
            'email' => 'ada@example.org',
            'phone' => '05551112233',
            'nationality_type' => 'tr',
            'identity_number' => '10000000146',
            'nationality' => 'T.C.',
            'mother_name' => 'Anne',
            'birthday' => '1990-12-10',
            'photo_choice' => 'card',
            'references' => [['number' => '10', 'surname' => 'Torvalds'], ['number' => '11', 'surname' => 'Stallman']],
        ], $overrides);
    }

    private function sentTemplate(string $key, string $to): ?TemplateMail
    {
        return Mail::queued(TemplateMail::class, fn (TemplateMail $mail) => $mail->key === $key && $mail->hasTo($to))->first();
    }

    public function test_an_application_goes_from_paper_form_to_member(): void
    {
        $this->member('10', 'Torvalds');
        $this->member('11', 'Stallman');

        $this->actingAs($this->owner)->get('/admin/membership-applications/create')->assertOk()->assertSee('Başvuru gir')->assertSee('Referanslarınız');
        $this->actingAs($this->owner)->post('/admin/membership-applications', $this->form())->assertRedirect()->assertSessionHasNoErrors();

        // A person without an account, the form marked as received.
        $application = MembershipApplication::sole();
        $contact = Contact::where('email', 'ada@example.org')->sole();
        $this->assertSame($contact->id, $application->contact_id);
        $this->assertNull($contact->user);
        $this->assertSame('2026-10-10', $application->submitted_at->toDateString());
        $this->assertNotNull($application->signed_form_received_at);
        $this->assertSame(MembershipApplication::REFERENCES_PENDING, $application->status);

        $received = $this->sentTemplate('membership.application-received', 'ada@example.org');
        $this->assertNotNull($received);
        $this->assertStringContainsString('2 üyeye onay bağlantısı', $received->values['steps']->toHtml());
        $this->assertStringContainsString('Giriş aidatını', $received->values['steps']->toHtml());

        // Both references confirm from the emailed link.
        $links = [];
        Mail::assertQueued(ReferenceInvitation::class, function (ReferenceInvitation $mail) use (&$links) {
            $links[] = $mail->link;

            return true;
        });
        $this->assertCount(2, $links);
        auth()->forgetGuards();
        foreach ($links as $link) {
            $this->post($link, ['answer' => 'accept'])->assertRedirect();
        }

        // The entry fee is charged and asked for; the board does not see it yet.
        $application->refresh();
        $this->assertSame(MembershipApplication::FEE_PENDING, $application->status);
        $this->assertSame(['entry'], DuesCharge::where('contact_id', $contact->id)->pluck('kind')->all());
        $request = $this->sentTemplate('dues.entry-fee', 'ada@example.org');
        $this->assertSame('150 TL', $request->values['amount']);
        $this->assertStringContainsString('/odeme/'.$contact->id.'/', $request->values['link']);
        Mail::assertNotQueued(ApplicationReady::class);
        $this->actingAs($this->owner)->get("/admin/membership-applications/{$application->id}")->assertOk()->assertSee('Giriş aidatı henüz ödenmedi')->assertDontSee('Kabul et, üye yap');
        $this->actingAs($this->owner)->patch("/admin/membership-applications/{$application->id}/approve", ['number' => '3001', 'joined_at' => '2026-10-20'])->assertForbidden();

        // Paid: on to the board.
        app(DuesService::class)->record($contact, 150, Payment::CASH, now(), null, null);
        $this->assertSame(MembershipApplication::READY, $application->fresh()->status);
        Mail::assertQueued(ApplicationReady::class, fn ($mail) => $mail->hasTo('yk@example.org'));

        // The board accepts: welcome, and the yearly dues with the payment link.
        $this->actingAs($this->owner)->patch("/admin/membership-applications/{$application->id}/approve", ['number' => '3001', 'joined_at' => '2026-10-20', 'decision_date' => '2026-10-20', 'decision_number' => '2026/9'])->assertRedirect();
        $this->assertTrue($contact->fresh()->hasAffiliation('member'));
        Mail::assertQueued(ApplicationDecided::class, fn ($mail) => $mail->approved && $mail->hasTo('ada@example.org'));
        $this->assertEqualsCanonicalizing(['entry', 'annual'], DuesCharge::where('contact_id', $contact->id)->pluck('kind')->all());
        $dues = $this->sentTemplate('dues.new-member', 'ada@example.org');
        $this->assertSame('400 TL', $dues->values['annual_fee']);
        $this->assertSame('400 TL', $dues->values['balance']);
    }

    public function test_without_references_and_entry_fee_the_application_goes_straight_to_the_board(): void
    {
        app(Organization::class)->save(['membership_references_required' => '0', 'membership_entry_fee' => '0']);

        $this->actingAs($this->owner)->post('/admin/membership-applications', $this->form(['references' => null]))->assertSessionHasNoErrors();

        $application = MembershipApplication::sole();
        $this->assertSame(MembershipApplication::READY, $application->status);
        Mail::assertQueued(ApplicationReady::class);
        Mail::assertNotQueued(ReferenceInvitation::class);
        $this->assertSame(0, DuesCharge::count());

        $steps = $this->sentTemplate('membership.application-received', 'ada@example.org')->values['steps']->toHtml();
        $this->assertStringNotContainsString('Referans', $steps);
        $this->assertStringNotContainsString('Giriş aidatı', $steps);
    }

    public function test_a_waived_entry_fee_lets_the_application_go_on(): void
    {
        app(Organization::class)->save(['membership_references_required' => '0']);
        $this->actingAs($this->owner)->post('/admin/membership-applications', $this->form(['references' => null]))->assertSessionHasNoErrors();
        $application = MembershipApplication::sole();
        $this->assertSame(MembershipApplication::FEE_PENDING, $application->status);

        app(DuesService::class)->cancel(DuesCharge::sole(), 'Yönetim kurulu kararıyla alınmadı');

        $this->assertSame(MembershipApplication::READY, $application->fresh()->status);
    }

    public function test_an_existing_person_or_member_is_recognised(): void
    {
        $member = $this->member('12', 'Lovelace');
        $member->update(['email' => 'ada@example.org']);
        app(Organization::class)->save(['membership_references_required' => '0']);

        $this->actingAs($this->owner)->post('/admin/membership-applications', $this->form(['references' => null, 'identity_number' => '10000000078']))
            ->assertSessionHasErrors('email');
        $this->assertSame(0, MembershipApplication::count());
    }
}
