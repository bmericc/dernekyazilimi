<?php

namespace Modules\Membership\Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Support\IdentityCheck;
use App\Support\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Membership\Mail\ApplicationDecided;
use Modules\Membership\Mail\ApplicationReady;
use Modules\Membership\Mail\ReferenceDeclined;
use Modules\Membership\Mail\ReferenceInvitation;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipReference;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    public bool $identityValid = true;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(IdentityCheck::class, new class($test) extends IdentityCheck
        {
            public function __construct(private ApplicationTest $test)
            {
            }

            public function verify(string $identityNumber, string $name, string $surname, int $birthYear): bool
            {
                return $this->test->identityValid;
            }
        });

        Mail::fake();
        $this->settings(['membership_applications_open' => '1', 'membership_references_required' => '2', 'notification_email' => 'yk@example.org']);
    }

    private function settings(array $values): void
    {
        app(Organization::class)->save($values);
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
            'references' => [['number' => '10', 'surname' => 'Torvalds'], ['number' => '11', 'surname' => 'Işık']],
        ], $overrides);
    }

    /**
     * @return array<int, string> reference id => token from the invitation mails
     */
    private function invitationLinks(): array
    {
        $links = [];
        Mail::assertQueued(ReferenceInvitation::class, function (ReferenceInvitation $mail) use (&$links) {
            $links[$mail->reference->id] = $mail->link;

            return true;
        });

        return $links;
    }

    public function test_an_applicant_applies_with_member_references_who_confirm_by_link(): void
    {
        $first = $this->member('10', 'Torvalds');
        $second = $this->member('11', 'IŞIK');
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->get('/membership/apply')->assertOk()->assertSee('Referanslarınız');
        $this->actingAs($applicant)->post('/membership/apply', $this->form())->assertRedirect('/membership/application');

        $application = MembershipApplication::sole();
        $this->assertSame(now()->year.'-0001', $application->reference_no);
        $this->assertSame(MembershipApplication::REFERENCES_PENDING, $application->status);
        $this->assertSame('Lovelace', $application->answer('last_name'));
        $this->assertSame(Membership::APPLICANT, $application->membership->status);
        $this->assertSame(2, $application->references()->count());
        Mail::assertQueued(ReferenceInvitation::class, 2);

        $links = $this->invitationLinks();
        $firstReference = MembershipReference::where('referee_contact_id', $first->contact_id)->sole();
        $url = $links[$firstReference->id];

        // Only the invited member, signed in, can answer.
        $this->actingAs($second)->get($url)->assertOk()->assertSee('başka bir üyeye');
        $this->actingAs($first)->get(str_replace(substr($url, -10), 'xxxxxxxxxx', $url))->assertOk()->assertSee('geçerli değil');
        $this->actingAs($first)->get($url)->assertOk()->assertSee('Kabul ediyorum');
        $this->actingAs($first)->post($url, ['answer' => 'accept'])->assertRedirect();
        $this->assertSame(MembershipReference::ACCEPTED, $firstReference->fresh()->status);
        $this->assertSame(MembershipApplication::REFERENCES_PENDING, $application->fresh()->status);

        $secondReference = MembershipReference::where('referee_contact_id', $second->contact_id)->sole();
        $this->actingAs($second)->post($links[$secondReference->id], ['answer' => 'accept']);
        $this->assertSame(MembershipApplication::READY, $application->fresh()->status);
        Mail::assertQueued(ApplicationReady::class, fn ($mail) => $mail->hasTo('yk@example.org'));

        // A second answer is refused.
        $this->actingAs($second)->post($links[$secondReference->id], ['answer' => 'decline'])->assertForbidden();

        $this->actingAs($applicant)->get('/membership/application')->assertOk()->assertSee($application->reference_no)->assertSee('Kabul etti');
        $this->actingAs($applicant)->get('/membership/apply')->assertRedirect('/membership/application');
    }

    public function test_referees_must_be_active_members_matching_the_surname(): void
    {
        $this->member('10', 'Torvalds');
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => [['number' => '10', 'surname' => 'Torvalds'], ['number' => '10', 'surname' => 'Torvalds']]]))
            ->assertSessionHasErrors('references.1.number');
        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => [['number' => '10', 'surname' => 'Başka'], ['number' => '99', 'surname' => 'Yok']]]))
            ->assertSessionHasErrors('references.0.number');
        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => [['number' => '10', 'surname' => 'Torvalds']]]))
            ->assertSessionHasErrors('references');

        $this->identityValid = false;
        $this->member('11', 'Işık');
        $this->actingAs($applicant)->post('/membership/apply', $this->form())->assertSessionHasErrors('identity_number');

        $this->assertSame(0, MembershipApplication::count());
    }

    public function test_the_yearly_limit_counts_the_calendar_year(): void
    {
        $this->settings(['membership_references_required' => '1', 'membership_reference_limit_yearly' => '1']);
        $referee = $this->member('10', 'Torvalds');

        $earlier = Contact::create(['first_name' => 'X', 'last_name' => 'Y']);
        $old = MembershipReference::forceCreate([
            'application_id' => MembershipApplication::forceCreate([
                'membership_id' => Membership::create(['contact_id' => $earlier->id, 'status' => Membership::ACTIVE])->id,
                'contact_id' => $earlier->id, 'reference_no' => '2020-0001', 'status' => 'approved', 'data' => [], 'submitted_at' => now(),
            ])->id,
            'applicant_contact_id' => $earlier->id,
            'referee_contact_id' => $referee->contact_id,
            'position' => 1,
            'status' => MembershipReference::ACCEPTED,
            'token_hash' => 'x',
            'invited_at' => now()->startOfYear()->subDay(),
            'expires_at' => now()->startOfYear(),
        ]);

        $form = $this->form(['references' => [['number' => '10', 'surname' => 'Torvalds']]]);
        $this->actingAs(User::factory()->create())->post('/membership/apply', $form)->assertSessionHasNoErrors();

        // Last year's reference did not count, this year's does.
        $this->actingAs(User::factory()->create())->post('/membership/apply', $form)->assertSessionHasErrors('references.0.number');
        $this->assertNotNull($old);
    }

    public function test_a_declined_reference_is_replaced_by_the_applicant(): void
    {
        $this->settings(['membership_references_required' => '1']);
        $referee = $this->member('10', 'Torvalds');
        $this->member('12', 'Stallman');
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => [['number' => '10', 'surname' => 'Torvalds']]]));
        $reference = MembershipReference::sole();
        $this->actingAs($referee)->post($this->invitationLinks()[$reference->id], ['answer' => 'decline', 'note' => 'Tanımıyorum']);
        Mail::assertQueued(ReferenceDeclined::class);

        $this->actingAs($applicant)->put("/membership/references/{$reference->id}/replace", ['number' => '10', 'surname' => 'Torvalds'])->assertSessionHasErrors('replace_'.$reference->id);
        $this->actingAs($applicant)->put("/membership/references/{$reference->id}/replace", ['number' => '12', 'surname' => 'Stallman'])->assertSessionHasNoErrors();

        $this->assertSame(MembershipReference::WITHDRAWN, $reference->fresh()->status);
        $this->assertSame(MembershipReference::PENDING, MembershipReference::latest('id')->first()->status);
    }

    public function test_management_approves_an_application_and_the_applicant_becomes_a_member(): void
    {
        $this->settings(['membership_references_required' => '0']);
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => null]))->assertSessionHasNoErrors();
        $application = MembershipApplication::sole();
        $this->assertSame(MembershipApplication::READY, $application->status);

        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/membership-applications')->assertOk()->assertSee($application->reference_no);
        $this->actingAs($owner)->get("/admin/membership-applications/{$application->id}")->assertOk()->assertSee('Kabul et');
        $this->actingAs($owner)->patch("/admin/membership-applications/{$application->id}/signed-form")->assertRedirect();
        $this->assertNotNull($application->fresh()->signed_form_received_at);

        $this->actingAs($owner)->patch("/admin/membership-applications/{$application->id}/approve", ['number' => '2001', 'joined_at' => '2026-09-20', 'decision_date' => '2026-09-19', 'decision_number' => '2026/7'])->assertRedirect();

        $membership = $application->membership->fresh();
        $this->assertTrue($membership->isActive());
        $this->assertSame('2001', $membership->number);
        $this->assertSame('2026/7', $membership->decision_number);
        $this->assertTrue($applicant->contact->fresh()->hasAffiliation('member'));
        $this->assertSame(MembershipApplication::APPROVED, $application->fresh()->status);
        Mail::assertQueued(ApplicationDecided::class, fn ($mail) => $mail->approved);

        $this->actingAs($applicant)->get('/membership/apply')->assertRedirect()->assertSessionHas('danger-status', 'Zaten üyesiniz.');
    }

    public function test_management_rejects_an_application_with_a_reason(): void
    {
        $this->settings(['membership_references_required' => '0']);
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => null]));
        $application = MembershipApplication::sole();

        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->patch("/admin/membership-applications/{$application->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($owner)->patch("/admin/membership-applications/{$application->id}/reject", ['note' => 'Eksik bilgi'])->assertRedirect();

        $this->assertSame(MembershipApplication::REJECTED, $application->fresh()->status);
        $this->assertSame(Membership::REJECTED, $application->membership->fresh()->status);
        Mail::assertQueued(ApplicationDecided::class, fn ($mail) => ! $mail->approved);

        // A rejected applicant may apply again.
        $this->actingAs($applicant)->get('/membership/apply')->assertOk();
    }

    public function test_the_application_form_is_rendered_as_pdf(): void
    {
        $this->settings(['membership_references_required' => '0']);
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post('/membership/apply', $this->form(['references' => null]));

        $response = $this->actingAs($applicant)->get('/membership/application/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $html = view('membership::pdf.application', app(\Modules\Membership\Support\ApplicationPdf::class)->viewData(MembershipApplication::sole()))->render();
        $this->assertStringContainsString('ÜYELİK BAŞVURU BELGESİ', $html);
        $this->assertStringContainsString('Lovelace', $html);
    }

    public function test_applications_can_be_closed_and_settings_are_owner_managed(): void
    {
        $this->settings(['membership_applications_open' => '0']);
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->get('/membership/apply')->assertRedirect('/membership/application');
        $this->actingAs($applicant)->get('/admin/memberships/settings')->assertForbidden();

        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/memberships/settings')->assertOk();
        $this->actingAs($owner)->put('/admin/memberships/settings', ['applications_open' => '1', 'references_required' => 3, 'reference_limit_yearly' => 1, 'reference_days' => 10, 'letter' => '<p>Başkanlığına</p><script>x</script>'])->assertRedirect();

        $organization = app(Organization::class);
        $this->assertSame('1', $organization->get('membership_applications_open'));
        $this->assertSame('3', $organization->get('membership_references_required'));
        $this->assertNull($organization->get('membership_reference_limit_total'));
        $this->assertStringNotContainsString('script', $organization->get('membership_application_letter'));
    }
}
