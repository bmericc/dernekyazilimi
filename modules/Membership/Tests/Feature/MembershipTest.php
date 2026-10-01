<?php

namespace Modules\Membership\Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementAcceptance;
use App\Models\Contact;
use App\Models\User;
use App\Modules\ContactFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Membership\Models\Membership;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_member_numbers_and_member_affiliations_are_carried_over(): void
    {
        $numbered = User::factory()->create(['lkd_user_id' => 506]);
        $unnumbered = Contact::create(['first_name' => 'Kurul', 'last_name' => 'Üyesi']);
        $unnumbered->affiliate('member', ['started_at' => '2021-03-01']);
        User::factory()->create();
        DB::table('membership_events')->delete();
        DB::table('memberships')->delete();

        $migration = require base_path('modules/Membership/database/migrations/2026_09_27_100000_create_membership_tables.php');
        (fn () => $this->backfill())->call($migration);

        $this->assertSame(2, Membership::count());
        $this->assertSame('506', Membership::where('contact_id', $numbered->contact_id)->value('number'));
        $carried = Membership::where('contact_id', $unnumbered->id)->sole();
        $this->assertNull($carried->number);
        $this->assertSame('2021-03-01', $carried->joined_at->toDateString());
        $this->assertSame(['joined'], $carried->events()->pluck('type')->all());
    }

    public function test_managers_make_a_contact_a_member_with_a_number_they_type(): void
    {
        Membership::create(['contact_id' => Contact::create(['first_name' => 'A', 'last_name' => 'B'])->id, 'number' => '1506']);
        $owner = User::factory()->create(['role' => 1]);
        $contact = Contact::create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        // The largest number is only a hint; the field starts empty.
        $this->actingAs($owner)->get("/admin/contacts/{$contact->id}")->assertOk()->assertSee('Üye yap')->assertSee('placeholder="En büyük: 1506"', false)->assertDontSee('value="1507"', false);

        $this->actingAs($owner)->post("/admin/contacts/{$contact->id}/membership", ['number' => '1506', 'joined_at' => '2026-09-01'])->assertSessionHasErrors('number');
        $this->actingAs($owner)->post("/admin/contacts/{$contact->id}/membership", ['number' => '1507', 'joined_at' => '2026-09-01', 'note' => 'YK 2026/12'])->assertRedirect();

        $membership = Membership::where('contact_id', $contact->id)->sole();
        $this->assertTrue($membership->isActive());
        $this->assertTrue($contact->hasAffiliation('member'));
        $this->assertSame('YK 2026/12', $membership->events()->sole()->note);
        $this->assertSame('1507', app(ContactFields::class)->value('member_number', $contact));

        // Without a number the membership stays numberless.
        $other = Contact::create(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $this->actingAs($owner)->post("/admin/contacts/{$other->id}/membership", ['number' => '', 'joined_at' => '2026-09-01']);
        $this->assertNull(Membership::where('contact_id', $other->id)->value('number'));
    }

    public function test_status_changes_follow_the_member_affiliation_and_the_history(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $contact = Contact::create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $membership = app(MembershipService::class)->start($contact, '10', today()->subYear());

        $this->actingAs($owner)->patch("/admin/memberships/{$membership->id}/status", ['status' => 'suspended', 'date' => today()->toDateString(), 'note' => 'Aidat'])->assertRedirect();
        $this->assertFalse($contact->fresh()->hasAffiliation('member'));

        $this->actingAs($owner)->patch("/admin/memberships/{$membership->id}/status", ['status' => 'active', 'date' => today()->toDateString()]);
        $this->assertTrue($contact->fresh()->hasAffiliation('member'));

        $this->actingAs($owner)->patch("/admin/memberships/{$membership->id}/status", ['status' => 'left', 'date' => today()->toDateString()]);
        $membership->refresh();
        $this->assertSame(Membership::LEFT, $membership->status);
        $this->assertSame(today()->toDateString(), $membership->left_at->toDateString());
        $this->assertEqualsCanonicalizing(['joined', 'suspended', 'reactivated', 'left'], $membership->events()->pluck('type')->all());

        $this->actingAs($owner)->put("/admin/memberships/{$membership->id}", ['number' => '11', 'derbis_registered' => '1'])->assertRedirect();
        $this->assertSame('11', $membership->fresh()->number);
        $this->assertTrue($membership->fresh()->derbis_registered);
        $this->assertTrue($membership->events()->where('type', 'number_changed')->where('note', '10 → 11')->exists());
    }

    public function test_the_member_sees_membership_history_and_agreements_on_the_profile(): void
    {
        $user = User::factory()->create();
        app(MembershipService::class)->start($user->contact, '42', today());
        $agreement = Agreement::create(['key' => 'kvkk', 'title' => 'Gizlilik Politikası']);
        $version = $agreement->versions()->create(['version' => 1, 'content' => '<p>x</p>']);
        $version->forceFill(['published_at' => now()])->save();
        AgreementAcceptance::create(['agreement_version_id' => $version->id, 'user_id' => $user->id, 'contact_id' => $user->contact_id, 'ip' => '10.1.2.3', 'accepted_at' => now()]);

        $this->actingAs($user)->get('/my-infos')->assertOk()
            ->assertSee('Üyelik tarihçesi')->assertSee('Üyelik başladı')->assertSee('42')
            ->assertSee('Sözleşmelerim')->assertSee('Gizlilik Politikası')->assertSee('10.1.2.3')
            ->assertDontSee('name="lkd_user_id"', false);
    }

    public function test_the_member_list_needs_the_permission(): void
    {
        $contact = Contact::create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        app(MembershipService::class)->start($contact, '7', today());

        $this->actingAs(User::factory()->create(['role' => 1]))->get('/admin/memberships')->assertOk()->assertSee('Ada Lovelace');
        $this->actingAs(User::factory()->create(['role' => 2]))->get('/admin/memberships')->assertForbidden();
    }

    public function test_numbers_of_numberless_members_are_typed_in(): void
    {
        $member = fn (string $name, ?string $joined, ?string $decision = null, string $status = Membership::ACTIVE, ?string $number = null) => Membership::create([
            'contact_id' => Contact::create(['first_name' => $name, 'last_name' => 'Üye'])->id,
            'number' => $number, 'status' => $status, 'joined_at' => $joined, 'decision_date' => $decision,
        ]);
        $numbered = $member('Numaralı', '2010-01-01', number: '41');
        $late = $member('Geç', '2020-05-01');
        $second = $member('İkinci', '2015-03-01', '2015-02-20');
        $first = $member('Birinci', '2015-03-01', '2015-02-10');
        $unknown = $member('Tarihsiz', null);
        $left = $member('Ayrılmış', '2012-01-01', status: Membership::LEFT);
        $member('Aday', '2011-01-01', status: Membership::APPLICANT);

        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/memberships/numbers')->assertOk()
            ->assertSee('Kullanılan en büyük numara: <strong>41</strong>', false)
            ->assertSeeInOrder(['Birinci Üye', 'İkinci Üye', 'Geç Üye', 'Tarihsiz Üye'])
            ->assertDontSee('Numaralı Üye')->assertDontSee('Ayrılmış Üye')->assertDontSee('Aday Üye');
        $this->actingAs($owner)->get('/admin/memberships/numbers?left=1')->assertSeeInOrder(['Ayrılmış Üye', 'Birinci Üye']);

        // Taken or repeated numbers are refused; nothing is saved then.
        $this->actingAs($owner)->post('/admin/memberships/numbers', ['numbers' => [$first->id => '41']])->assertSessionHasErrors("numbers.{$first->id}");
        $this->actingAs($owner)->post('/admin/memberships/numbers', ['numbers' => [$first->id => '100', $second->id => '100']])->assertSessionHasErrors();
        $this->assertNull($first->fresh()->number);

        // Only the numbers typed are given; a numbered member is not changed here.
        $this->actingAs($owner)->post('/admin/memberships/numbers', ['numbers' => [$first->id => ' 100 ', $late->id => 'P-7', $second->id => '', $numbered->id => '999']])
            ->assertSessionHas('success-status', '2 üyeye numara verildi.');
        $this->assertSame(['100', null, 'P-7', null, '41'], [$first->fresh()->number, $second->fresh()->number, $late->fresh()->number, $unknown->fresh()->number, $numbered->fresh()->number]);
        $this->assertSame('number_changed', $late->events()->value('type'));
        $this->assertNull($left->fresh()->number);
    }

    public function test_the_panel_charts_members_by_joining_and_leaving_month(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 15));
        $owner = User::factory()->create(['role' => 1]);
        $member = fn (array $attributes) => Membership::create(['contact_id' => Contact::create(['first_name' => 'A', 'last_name' => 'B'])->id] + $attributes);

        $member(['joined_at' => '2019-05-01']);
        $member(['joined_at' => '2026-07-10', 'status' => Membership::SUSPENDED]);
        $member(['joined_at' => '2026-09-02']);
        $member(['joined_at' => '2020-01-01', 'left_at' => '2026-08-20', 'status' => Membership::LEFT]);
        $member(['joined_at' => null]);
        $member(['applied_at' => '2026-09-01', 'status' => Membership::APPLICANT]);

        $charts = collect($this->actingAs($owner)->get('/admin')->assertOk()->assertSee('Toplam üye')->viewData('charts'))->keyBy('title');

        $this->assertSame([3, 4, 3, 4], array_slice(array_values($charts['Toplam üye']['data']), -4));
        $this->assertSame([0, 1, 0, 1], array_slice(array_values($charts['Aylık yeni üye']['data']), -4));
        $this->assertCount(12, $charts['Toplam üye']['data']);

        // Without the permission the member charts are not shown.
        $manager = User::factory()->create(['role' => 2]);
        $titles = array_column($this->actingAs($manager)->get('/admin')->assertOk()->viewData('charts'), 'title');
        $this->assertNotContains('Toplam üye', $titles);
    }
}
