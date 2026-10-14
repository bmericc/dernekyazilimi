<?php

namespace Modules\Dues\Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\User;
use App\Support\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Support\DuesService;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipFee;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class DuesRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00');
        Mail::fake();
        MembershipFee::create(['year' => 2026, 'entry_fee' => 150, 'annual_fee' => 400]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sent(string $key): \Illuminate\Support\Collection
    {
        return Mail::queued(TemplateMail::class, fn (TemplateMail $mail) => $mail->key === $key);
    }

    public function test_members_who_owe_get_a_monthly_reminder_on_the_set_day(): void
    {
        $debtor = User::factory()->create(['email' => 'borclu@example.org']);
        app(MembershipService::class)->start($debtor->contact, '1', Carbon::parse('2020-01-01'));
        app(DuesService::class)->charge($debtor->contact, DuesCharge::ANNUAL, 2026, 400);
        $small = User::factory()->create(['email' => 'az@example.org']);
        app(MembershipService::class)->start($small->contact, '2', Carbon::parse('2020-01-01'));
        app(DuesService::class)->charge($small->contact, DuesCharge::OTHER, 2026, 5);

        app(Organization::class)->save(['dues_auto_reminder_day' => '6', 'dues_auto_reminder_min' => '10']);
        $this->artisan('notices:send');
        $this->assertCount(0, $this->sent('dues.reminder'));

        Carbon::setTestNow('2026-10-06 10:00');
        $this->artisan('notices:send')->expectsOutput('dues.reminder: 1 kişi');
        $mail = $this->sent('dues.reminder')->sole();
        $this->assertTrue($mail->hasTo('borclu@example.org'));
        $this->assertSame('400 TL', $mail->values['balance']);
        $this->assertSame('06.10.2026', $mail->values['date']);

        // Once a month.
        $this->artisan('notices:send');
        $this->assertCount(1, $this->sent('dues.reminder'));
        Carbon::setTestNow('2026-11-06 10:00');
        $this->artisan('notices:send');
        $this->assertCount(2, $this->sent('dues.reminder'));
    }

    public function test_an_applicant_who_has_not_paid_the_entry_fee_gets_the_last_reminder_once(): void
    {
        app(Organization::class)->save(['membership_entry_fee' => '1', 'dues_entry_fee_reminder_days' => '7', 'dues_entry_fee_deadline_days' => '10']);
        $user = User::factory()->create(['email' => 'aday@example.org']);
        $membership = Membership::create(['contact_id' => $user->contact_id, 'status' => Membership::APPLICANT]);
        $application = MembershipApplication::forceCreate(['membership_id' => $membership->id, 'contact_id' => $user->contact_id, 'user_id' => $user->id, 'reference_no' => '2026-0001', 'status' => MembershipApplication::REFERENCES_PENDING, 'data' => [], 'submitted_at' => now()]);
        $this->assertTrue(app(DuesService::class)->requestEntryFee($application));
        $application->update(['status' => MembershipApplication::FEE_PENDING]);

        Carbon::setTestNow('2026-10-10 10:00');
        $this->artisan('notices:send');
        $this->assertCount(0, $this->sent('dues.entry-fee-reminder'));

        Carbon::setTestNow('2026-10-12 10:00');
        $this->artisan('notices:send')->expectsOutput('dues.entry-fee-reminder: 1 kişi');
        $mail = $this->sent('dues.entry-fee-reminder')->sole();
        $this->assertSame('22.10.2026', $mail->values['deadline']);
        $this->assertSame('150 TL', $mail->values['amount']);

        Carbon::setTestNow('2026-10-20 10:00');
        $this->artisan('notices:send');
        $this->assertCount(1, $this->sent('dues.entry-fee-reminder'));
    }
}

