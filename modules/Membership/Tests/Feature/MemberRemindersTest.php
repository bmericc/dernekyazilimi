<?php

namespace Modules\Membership\Tests\Feature;

use App\Mail\TemplateMail;
use App\Models\ContactPhoto;
use App\Models\User;
use App\Support\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class MemberRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $number, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(MembershipService::class)->start($user->contact, $number, Carbon::parse('2020-01-01'));

        return $user;
    }

    public function test_members_missing_identity_details_or_a_photo_are_reminded_at_the_set_interval(): void
    {
        $complete = $this->member('1', ['email' => 'tam@example.org', 'national_id' => '10000000146', 'birthday' => '1990-01-01']);
        ContactPhoto::forceCreate(['contact_id' => $complete->contact_id, 'path' => 'x.jpg', 'status' => ContactPhoto::APPROVED]);
        $missing = $this->member('2', ['email' => 'eksik@example.org', 'national_id' => null, 'birthday' => '1990-01-01']);

        $this->artisan('notices:send')->expectsOutput('Gönderilecek bildirim yok.');

        app(Organization::class)->save(['membership_identity_reminder_days' => '30', 'membership_photo_reminder_days' => '60']);
        $this->artisan('notices:send')->expectsOutput('membership.identity-missing: 1 kişi')->expectsOutput('membership.photo-missing: 1 kişi');

        $identity = Mail::queued(TemplateMail::class, fn ($mail) => $mail->key === 'membership.identity-missing')->sole();
        $this->assertTrue($identity->hasTo('eksik@example.org'));
        $this->assertSame('T.C. kimlik no', $identity->values['missing']);
        $this->assertTrue(Mail::queued(TemplateMail::class, fn ($mail) => $mail->key === 'membership.photo-missing')->sole()->hasTo('eksik@example.org'));

        // Again only after the interval.
        Carbon::setTestNow('2026-11-01 10:00');
        $this->artisan('notices:send')->expectsOutput('Gönderilecek bildirim yok.');
        Carbon::setTestNow('2026-11-15 10:00');
        $this->artisan('notices:send')->expectsOutput('membership.identity-missing: 1 kişi');
    }
}
