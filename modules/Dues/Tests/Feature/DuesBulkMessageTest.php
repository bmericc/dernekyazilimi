<?php

namespace Modules\Dues\Tests\Feature;

use App\Contracts\Messaging\SmsSender;
use App\Mail\BulkMail;
use App\Models\BulkMessage;
use App\Models\User;
use App\Support\BulkMessaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Support\DuesService;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipFee;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class DuesBulkMessageTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, ?string $number, string $joined, float $owes): User
    {
        $user = User::factory()->create(['email' => $email, 'phone_number' => '90555'.random_int(1000000, 9999999)]);
        app(MembershipService::class)->start($user->contact, $number, Carbon::parse($joined));
        if ($owes > 0) {
            app(DuesService::class)->charge($user->contact, DuesCharge::OTHER, 2026, $owes);
        }

        return $user;
    }

    private function recipients(array $criteria): array
    {
        $message = BulkMessage::create(['channels' => ['email'], 'criteria' => $criteria, 'informational' => false, 'subject' => 'x', 'body' => 'x']);

        return app(BulkMessaging::class)->audience($message)['recipients']->pluck('email')->sort()->values()->all();
    }

    public function test_members_are_picked_by_dues_balance_joining_year_and_missing_number(): void
    {
        $this->member('cok@example.org', '1', '2020-03-01', 600);
        $this->member('tam@example.org', '2', '2025-05-01', 480);
        $this->member('az@example.org', null, '2025-09-01', 100);
        $this->member('yok@example.org', null, '2024-01-01', 0);

        $this->assertSame(['cok@example.org', 'tam@example.org'], $this->recipients(['dues_min' => '480']));
        $this->assertSame(['az@example.org', 'cok@example.org', 'tam@example.org'], $this->recipients(['dues_min' => '0']));
        $this->assertSame(['az@example.org', 'tam@example.org'], $this->recipients(['membership_joined' => ['from' => '2025', 'to' => '2025']]));
        $this->assertSame(['az@example.org', 'yok@example.org'], $this->recipients(['membership_no_number' => '1']));
        $this->assertSame(['az@example.org'], $this->recipients(['membership_no_number' => '1', 'membership_joined' => ['from' => 2025, 'to' => null], 'membership_status' => [Membership::ACTIVE]]));
    }

    public function test_a_dues_reminder_carries_each_members_balance_and_link(): void
    {
        Mail::fake();
        MembershipFee::create(['year' => 2026, 'entry_fee' => 150, 'annual_fee' => 480]);
        $this->member('borclu@example.org', '7', '2020-01-01', 480);
        $message = BulkMessage::create([
            'channels' => ['email', 'sms'], 'criteria' => ['dues_min' => 480], 'informational' => false,
            'subject' => 'Aidat borcunuz {{dues_balance}}', 'body' => '<p>{{dues_charges}} <a href="{{dues_link}}">Öde</a></p>',
            'sms' => 'Sn {{name}}, {{dues_balance}} aidat borcunuz var. {{dues_link}}',
        ]);

        app(BulkMessaging::class)->start($message, User::factory()->create()->id);

        Mail::assertSent(BulkMail::class, fn (BulkMail $mail) => $mail->hasTo('borclu@example.org') && $mail->mailSubject === 'Aidat borcunuz 480 TL'
            && str_contains($mail->body->toHtml(), '/odeme/') && str_contains($mail->body->toHtml(), '<li>'));
        $sms = app(SmsSender::class)->sent[0]['text'];
        $this->assertStringContainsString('480 TL aidat borcunuz var. http', $sms);
    }
}
