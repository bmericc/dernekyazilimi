<?php

namespace Modules\Dues\Tests\Feature;

use App\Events\ContactAnonymized;
use App\Models\AffiliationType;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Dues\Mail\DuesPaymentReceived;
use Modules\Dues\Mail\DuesStatement;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Models\DuesExemption;
use Modules\Dues\Support\DuesService;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipFee;
use Modules\Membership\Support\ApplicationService;
use Modules\Membership\Support\MembershipService;
use Tests\TestCase;

class DuesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create(['role' => 1]);
        MembershipFee::create(['year' => 2025, 'entry_fee' => 100, 'annual_fee' => 300]);
        MembershipFee::create(['year' => 2026, 'entry_fee' => 150, 'annual_fee' => 400]);
    }

    private function member(string $number, string $joined = '2020-01-01', array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(MembershipService::class)->start($user->contact, $number, Carbon::parse($joined));

        return $user;
    }

    private function account(): BankAccount
    {
        return BankAccount::create(['bank_name' => 'Örnek Bankası', 'account_holder' => 'Dernek', 'iban' => 'TR330006100519786457841326', 'purposes' => ['dues']]);
    }

    public function test_the_yearly_dues_are_charged_in_bulk_skipping_exempt_members(): void
    {
        $paying = $this->member('1');
        $suspended = $this->member('2');
        app(MembershipService::class)->changeStatus(Membership::where('number', '2')->first(), Membership::SUSPENDED, today());
        $exempt = $this->member('3');
        DuesExemption::create(['contact_id' => $exempt->contact_id, 'from_year' => 2026, 'reason' => 'Öğrenci']);
        $honorary = $this->member('4');
        $type = AffiliationType::create(['key' => 'honorary', 'name' => 'Onursal üye']);
        $honorary->contact->affiliate('honorary');
        $late = $this->member('5', '2027-02-01');
        $left = $this->member('6');
        app(MembershipService::class)->changeStatus(Membership::where('number', '6')->first(), Membership::LEFT, today());

        $this->actingAs($this->owner)->put('/admin/dues/settings', ['exempt_types' => [$type->id]])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get('/admin/dues/charge?year=2026')->assertOk()
            ->assertSee('1</strong> üyeye', false)->assertSee('Üyeliği askıda')->assertSee('Muaf: Öğrenci')->assertSee('Muaf sıfat: Onursal üye')
            ->assertDontSee($late->contact->display_name)->assertDontSee($left->contact->display_name);

        $this->actingAs($this->owner)->post('/admin/dues/charge', ['year' => 2026])->assertSessionHas('success-status', '1 üyeye 2026 yılı aidatı borç yazıldı.');
        $this->actingAs($this->owner)->post('/admin/dues/charge', ['year' => 2026])->assertSessionHas('success-status', '0 üyeye 2026 yılı aidatı borç yazıldı.');

        $charge = DuesCharge::sole();
        $this->assertSame($paying->contact_id, $charge->contact_id);
        $this->assertSame('400.00', $charge->amount);

        // A year without its own fee uses the latest earlier one; none at all refuses.
        $this->actingAs($this->owner)->get('/admin/dues/charge?year=2027')->assertSee('2026 yılının tutarı kullanılıyor');
        $this->actingAs($this->owner)->post('/admin/dues/charge', ['year' => 2019])->assertSessionHas('danger-status');
    }

    public function test_an_accepted_applicant_owes_the_entry_fee_and_the_joining_year(): void
    {
        app(Organization::class)->save(['membership_entry_fee' => '1']);
        $user = User::factory()->create();
        $membership = Membership::create(['contact_id' => $user->contact_id, 'status' => Membership::APPLICANT]);
        $application = MembershipApplication::forceCreate(['membership_id' => $membership->id, 'contact_id' => $user->contact_id, 'user_id' => $user->id, 'reference_no' => '2026-0001', 'status' => MembershipApplication::READY, 'data' => [], 'submitted_at' => now()]);

        $this->actingAs($this->owner);
        app(ApplicationService::class)->approve($application, '10', Carbon::parse('2026-09-20'), null, null);

        $this->assertEqualsCanonicalizing(['entry-2026', 'annual-2026'], DuesCharge::pluck('period_key')->all());
        $this->assertSame(550.0, app(DuesService::class)->account($user->contact)->balance());
    }

    public function test_payments_settle_the_oldest_charges_first(): void
    {
        $user = $this->member('1');
        $service = app(DuesService::class);
        $service->charge($user->contact, DuesCharge::ANNUAL, 2026, 400);
        $service->charge($user->contact, DuesCharge::ANNUAL, 2025, 300);
        $service->charge($user->contact, DuesCharge::OTHER, 2026, 50, 'Kart basımı');
        $this->assertNull($service->charge($user->contact, DuesCharge::ANNUAL, 2025, 300));

        $this->actingAs($this->owner)->post("/admin/dues/contacts/{$user->contact_id}/payments", ['amount' => '500', 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertSessionHasNoErrors();

        $account = $service->account($user->contact);
        $this->assertSame(250.0, $account->balance());
        $this->assertSame(['2025 yıllık aidat' => 300.0, '2026 yıllık aidat' => 200.0, 'Kart basımı' => 0.0],
            $account->charges->mapWithKeys(fn ($charge) => [$charge->label() => $charge->paid])->all());
        Mail::assertQueued(DuesPaymentReceived::class, fn ($mail) => $mail->balance === '250 TL');

        // Cancelling a charge takes it out of the balance; paying more leaves credit.
        $other = DuesCharge::where('kind', DuesCharge::OTHER)->sole();
        $this->actingAs($this->owner)->patch("/admin/dues/charges/{$other->id}/cancel", ['note' => 'Yanlış'])->assertSessionHasNoErrors();
        $this->assertSame(200.0, $service->account($user->contact)->balance());
        $this->actingAs($this->owner)->post("/admin/dues/contacts/{$user->contact_id}/payments", ['amount' => '300', 'paid_at' => today()->toDateString(), 'method' => 'cash']);
        $this->assertSame(-100.0, $service->account($user->contact)->balance());

        $this->actingAs($this->owner)->get('/admin/dues?filter=credit')->assertOk()->assertSee($user->contact->display_name);
        $this->actingAs($this->owner)->get("/admin/contacts/{$user->contact_id}")->assertOk()->assertSee('Alacaklı: 100 TL')->assertSee('Kart basımı');
    }

    public function test_a_member_pays_by_transfer_and_management_confirms_it(): void
    {
        $bank = $this->account();
        $user = $this->member('1');
        app(DuesService::class)->charge($user->contact, DuesCharge::ANNUAL, 2026, 400);

        $this->actingAs($user)->get('/my-dues')->assertOk()->assertSee('400 TL')->assertSee('Havale / EFT')->assertDontSee('Kredi / banka kartı');
        $this->actingAs($user)->post('/my-dues/pay', ['amount' => '401', 'method' => 'transfer'])->assertSessionHasErrors('amount');
        $this->actingAs($user)->post('/my-dues/pay', ['amount' => '400', 'method' => 'transfer'])->assertRedirect();

        $payment = Payment::sole();
        $this->assertSame('dues', $payment->purpose);
        $this->assertInstanceOf(Membership::class, $payment->payable);
        $this->actingAs($user)->get(route('dues.show', $payment->uuid))->assertSee($payment->reference)->assertSee('TR33 0006 1005 1978 6457 8413 26');
        $this->actingAs($user)->get('/my-dues')->assertSee('havale bildirdiniz');

        $this->actingAs($this->owner)->patch("/admin/payments/{$payment->id}/confirm", ['paid_at' => today()->toDateString(), 'bank_account_id' => $bank->id]);
        $this->assertSame(0.0, app(DuesService::class)->account($user->contact)->balance());
        Mail::assertQueued(DuesPaymentReceived::class, fn ($mail) => $mail->hasTo($user->email));
        $this->actingAs($user)->get('/my-dues')->assertSee('Ödendi')->assertDontSee('Ödeme yap');
    }

    public function test_a_member_pays_by_card(): void
    {
        $gateway = PaymentGateway::create(['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'test_mode' => true, 'purposes' => ['dues']]);
        $user = $this->member('1', attributes: ['national_id' => '10000000146']);
        app(DuesService::class)->charge($user->contact, DuesCharge::ANNUAL, 2026, 400);

        Http::fake([
            '*/checkoutform/initialize/*' => Http::response(['status' => 'success', 'token' => 'tok', 'paymentPageUrl' => 'https://sandbox.iyzico/pay']),
            '*/checkoutform/auth/ecom/detail' => fn ($request) => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '1', 'price' => '150.0', 'conversationId' => $request['conversationId']]),
        ]);

        $this->actingAs($user)->post('/my-dues/pay', ['amount' => '150', 'method' => 'gateway:'.$gateway->id])->assertRedirect('https://sandbox.iyzico/pay');
        $payment = Payment::sole();
        $this->post(route('payments.callback', $gateway), ['token' => 'tok'])->assertRedirect(route('dues.show', $payment->uuid));

        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertSame(250.0, app(DuesService::class)->account($user->contact)->balance());
    }

    public function test_the_public_lookup_emails_a_personal_link_and_reveals_nothing(): void
    {
        $this->account();
        $user = $this->member('1', attributes: ['email' => 'uye@example.org', 'national_id' => '10000000146', 'phone_number' => '+905551112233']);
        app(DuesService::class)->charge($user->contact, DuesCharge::ANNUAL, 2026, 400);
        User::factory()->create(['email' => 'uyedegil@example.org']);

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->get('/odeme')->assertNotFound();
        app(Organization::class)->save(['dues_public_page' => '1']);
        $this->get('/odeme')->assertOk();

        $answer = 'Bilgiler sistemdeki bir üyeyle eşleşiyorsa';
        foreach (['uyedegil@example.org', 'yok@example.org', '12345'] as $value) {
            $this->post('/odeme', ['identifier' => $value])->assertSessionHas('success-status', fn ($status) => str_contains($status, $answer));
        }
        Mail::assertNothingQueued();

        foreach (['10000000146', 'UYE@example.org', '0555 111 22 33'] as $value) {
            $this->post('/odeme', ['identifier' => $value])->assertSessionHas('success-status', fn ($status) => str_contains($status, $answer));
        }
        $link = null;
        Mail::assertQueued(DuesStatement::class, 3);
        Mail::assertQueued(DuesStatement::class, function (DuesStatement $mail) use (&$link) {
            $link = $mail->link;

            return $mail->hasTo('uye@example.org') && $mail->balance === '400 TL' && $mail->open[0]['label'] === '2026 yıllık aidat';
        });

        auth()->logout();
        $this->get($link)->assertOk()->assertSee('400 TL')->assertSee('Havale / EFT');
        $this->post($link, ['amount' => '400', 'method' => 'transfer'])->assertRedirect();
        $this->assertSame($user->contact_id, Payment::sole()->contact_id);

        $this->get(str_replace('signature=', 'signature=x', $link))->assertForbidden();
        $user->update(['email' => 'yeni@example.org']);
        $this->get($link)->assertForbidden();
    }

    public function test_management_sends_reminders_and_manages_exemptions(): void
    {
        $debtor = $this->member('1');
        $this->member('2');
        app(DuesService::class)->charge($debtor->contact, DuesCharge::ANNUAL, 2026, 400);

        $this->actingAs($this->owner)->get('/admin/dues')->assertOk()->assertSee($debtor->contact->display_name)->assertSee('400 TL');
        $this->actingAs($this->owner)->post('/admin/dues/reminders')->assertSessionHas('success-status', '1 üyeye aidat hatırlatması gönderildi.');
        Mail::assertQueued(DuesStatement::class, fn ($mail) => $mail->reminder && $mail->hasTo($debtor->email));

        $this->actingAs($this->owner)->post("/admin/dues/contacts/{$debtor->contact_id}/exemptions", ['from_year' => 2027, 'until_year' => 2026, 'reason' => 'x'])->assertSessionHasErrors('until_year');
        $this->actingAs($this->owner)->post("/admin/dues/contacts/{$debtor->contact_id}/exemptions", ['from_year' => 2027, 'reason' => 'Öğrenci'])->assertSessionHasNoErrors();
        $this->assertSame('Muaf: Öğrenci', app(DuesService::class)->exemption($debtor->contact, 2030));
        $this->assertNull(app(DuesService::class)->exemption($debtor->contact, 2026));

        $this->actingAs($this->owner)->post("/admin/dues/contacts/{$debtor->contact_id}/charges", ['kind' => 'annual', 'year' => 2026, 'amount' => '400'])->assertSessionHas('danger-status');
        $this->actingAs($this->owner)->post("/admin/dues/contacts/{$debtor->contact_id}/charges", ['kind' => 'other', 'year' => 2026, 'amount' => '50'])->assertSessionHasErrors('description');

        event(new ContactAnonymized($debtor->contact, $debtor->id));
        $this->assertSame('—', DuesExemption::sole()->reason);
        $this->assertSame(1, DuesCharge::count());

        // Without the permission the pages stay closed.
        $this->actingAs($debtor)->get('/admin/dues')->assertForbidden();
    }
}
