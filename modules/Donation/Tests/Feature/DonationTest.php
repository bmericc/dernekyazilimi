<?php

namespace Modules\Donation\Tests\Feature;

use App\Events\ContactAnonymized;
use App\Models\Agreement;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Donation\Mail\DonationThanks;
use Modules\Donation\Models\Donation;
use Modules\Donation\Models\DonationCause;
use Tests\TestCase;

class DonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function account(): BankAccount
    {
        return BankAccount::create(['bank_name' => 'Örnek Bankası', 'account_holder' => 'Dernek', 'iban' => 'TR330006100519786457841326']);
    }

    private function gateway(): PaymentGateway
    {
        return PaymentGateway::create(['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'test_mode' => true]);
    }

    private function form(array $overrides = []): array
    {
        return array_replace(['amount' => '250', 'name' => 'Ada Lovelace', 'email' => 'ada@example.org', 'method' => 'transfer'], $overrides);
    }

    public function test_without_payment_methods_the_page_says_donations_are_closed(): void
    {
        $this->get('/donate')->assertOk()->assertSee('çevrim içi bağış alınmıyor');
        $this->post('/donate', $this->form())->assertNotFound();
    }

    public function test_a_guest_donates_by_transfer_and_management_confirms_it(): void
    {
        $account = $this->account();
        $cause = DonationCause::create(['name' => 'Linux Yaz Kampı']);
        $agreement = Agreement::create(['key' => Agreement::PRIVACY, 'title' => 'Gizlilik Politikası']);
        $agreement->versions()->create(['version' => 1, 'content' => '<p>Metin</p>'])->forceFill(['published_at' => now()])->save();

        // The privacy policy in force opens in the modal the checkbox brings.
        $this->get('/donate')->assertOk()->assertSee('Havale / EFT')->assertDontSee('Kredi / banka kartı')->assertSee('Linux Yaz Kampı')
            ->assertSee('Gizlilik Politikası')->assertSee('function openModal(', false)->assertSee('id="modal-iframe"', false);
        $this->post('/donate', $this->form(['amount' => '5']))->assertSessionHasErrors('amount');

        $response = $this->post('/donate', $this->form(['agreement' => 'true', 'cause_id' => $cause->id, 'hide_name' => '1', 'message' => 'Başarılar']));
        $payment = Payment::sole();
        $response->assertRedirect(route('donations.show', $payment->uuid));

        $this->assertSame(Payment::TRANSFER, $payment->method);
        $this->assertTrue($payment->isPending());
        $donation = $payment->payable;
        $this->assertInstanceOf(Donation::class, $donation);
        $this->assertTrue($donation->hide_name);
        $this->assertSame('Lovelace', $donation->contact->last_name);
        $this->assertNull($donation->contact->user);

        $this->get(route('donations.show', $payment->uuid))->assertOk()->assertSee($payment->reference)->assertSee('TR33 0006 1005 1978 6457 8413 26');

        // The same guest donating again is the same contact.
        $this->post('/donate', $this->form(['agreement' => 'true']));
        $this->assertSame(1, Contact::where('email', 'ada@example.org')->count());

        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->patch("/admin/payments/{$payment->id}/confirm", ['paid_at' => today()->toDateString(), 'bank_account_id' => $account->id]);
        Mail::assertQueued(DonationThanks::class, fn ($mail) => $mail->hasTo('ada@example.org'));

        $this->actingAs($owner)->get('/admin/donations')->assertOk()->assertSee('Ada Lovelace')->assertSee('adı anılmasın')->assertSee('250,00 TL');
        $this->assertSame(250.0, $cause->collected());
    }

    public function test_a_member_donates_by_card(): void
    {
        $this->account();
        $gateway = $this->gateway();
        $user = User::factory()->create(['name' => 'Linus', 'surname' => 'Torvalds', 'national_id' => '10000000146']);

        Http::fake([
            '*/checkoutform/initialize/*' => Http::response(['status' => 'success', 'token' => 'tok', 'paymentPageUrl' => 'https://sandbox.iyzico/pay']),
            '*/checkoutform/auth/ecom/detail' => fn ($request) => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '1', 'price' => '100.0', 'conversationId' => $request['conversationId']]),
        ]);

        $this->actingAs($user)->get('/donate')->assertOk()->assertSee('Linus Torvalds')->assertSee('Kredi / banka kartı');
        $this->actingAs($user)->post('/donate', $this->form(['amount' => '100', 'method' => 'gateway:'.$gateway->id, 'email' => $user->email]))->assertRedirect('https://sandbox.iyzico/pay');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'initialize') && $request['buyer']['identityNumber'] === '10000000146');

        $payment = Payment::sole();
        $this->assertSame($user->contact_id, $payment->contact_id);
        $this->post(route('payments.callback', $gateway), ['token' => 'tok'])->assertRedirect(route('donations.show', $payment->uuid));

        $this->assertTrue($payment->fresh()->isPaid());
        Mail::assertQueued(DonationThanks::class);
        $this->actingAs($user)->get(route('donations.show', $payment->uuid))->assertSee('bağışınız alındı');
        $this->actingAs($user)->get('/my-donations')->assertOk()->assertSee('100 TL')->assertSee('Tahsil edildi');
    }

    public function test_a_card_payment_that_cannot_start_falls_back(): void
    {
        $gateway = $this->gateway();
        Http::fake(['*' => Http::response(['status' => 'failure', 'errorMessage' => 'Geçersiz imza'])]);

        $this->post('/donate', $this->form(['method' => 'gateway:'.$gateway->id]))->assertRedirect()->assertSessionHas('danger-status');
        $this->assertSame(Payment::CANCELLED, Payment::sole()->status);
    }

    public function test_management_records_donations_causes_and_settings(): void
    {
        $account = $this->account();
        $owner = User::factory()->create(['role' => 1]);
        $member = User::factory()->create(['email' => 'uye@example.org']);

        $this->actingAs($owner)->post('/admin/donations', ['name' => 'Üye', 'email' => 'uye@example.org', 'amount' => '1000', 'paid_at' => today()->toDateString(), 'method' => 'transfer'])->assertSessionHasErrors('bank_account_id');
        $this->actingAs($owner)->post('/admin/donations', ['name' => 'Üye', 'email' => 'uye@example.org', 'amount' => '1000', 'paid_at' => today()->toDateString(), 'method' => 'transfer', 'bank_account_id' => $account->id])->assertSessionHasNoErrors();

        $payment = Payment::sole();
        $this->assertTrue($payment->isPaid());
        $this->assertSame($member->contact_id, $payment->contact_id);
        $this->assertSame('manual', $payment->payable->source);

        $this->actingAs($owner)->post('/admin/donation-causes', ['name' => 'Genel fon', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get('/admin/donation-causes')->assertOk()->assertSee('Genel fon');

        $this->actingAs($owner)->put('/admin/donations/settings', ['open' => '1', 'amounts' => '50, 100,x', 'minimum' => 20])->assertSessionHasErrors('amounts');
        $this->actingAs($owner)->put('/admin/donations/settings', ['open' => '0', 'amounts' => '50, 100', 'minimum' => 20])->assertSessionHasNoErrors();
        $this->get('/donate')->assertSee('çevrim içi bağış alınmıyor');

        event(new ContactAnonymized($member->contact, $member->id));
        $this->assertNull($payment->payable->fresh()->donor_email);
    }

    public function test_the_web_site_starts_a_donation_and_shows_the_rest_in_a_frame(): void
    {
        app(\App\Support\Organization::class)->save(['frame_ancestors' => 'https://www.ornek.org.tr']);
        $headers = ['X-Api-Key' => app(\App\Support\SiteApi::class)->generateKey(), 'X-Site-Url' => 'https://www.ornek.org.tr', 'X-Client-Ip' => '203.0.113.7'];

        $this->withHeaders($headers)->postJson('/api/site/donations', $this->form())->assertUnprocessable()->assertJsonPath('message', 'Şu anda çevrim içi bağış alınmıyor.');

        $this->account();
        $gateway = $this->gateway();
        $cause = DonationCause::create(['name' => 'Linux Yaz Kampı']);
        Http::fake([
            '*/checkoutform/initialize/*' => Http::response(['status' => 'success', 'token' => 'tok', 'paymentPageUrl' => 'https://sandbox.iyzico/pay?token=tok']),
            '*/checkoutform/auth/ecom/detail' => fn ($request) => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '1', 'price' => '100.0', 'conversationId' => $request['conversationId']]),
        ]);

        $this->withHeaders($headers)->getJson('/api/site/config')->assertOk()
            ->assertJsonPath('donation.open', true)
            ->assertJsonPath('donation.minimum', 10)
            ->assertJsonPath('donation.causes.0', ['id' => $cause->id, 'name' => 'Linux Yaz Kampı'])
            ->assertJsonPath('donation.methods.0.key', 'gateway:'.$gateway->id)
            ->assertJsonPath('donation.methods.1.key', 'transfer');

        $this->withHeaders($headers)->postJson('/api/site/donations', $this->form(['amount' => '1']))->assertUnprocessable()->assertJsonValidationErrors('amount');

        // Transfer: the frame shows the bank accounts and the reference code.
        $transfer = $this->withHeaders($headers)->postJson('/api/site/donations', $this->form(['cause_id' => $cause->id]))->assertCreated()->assertJsonPath('method', 'transfer');
        $payment = Payment::sole();
        $this->assertSame('203.0.113.7', $payment->ip);
        $this->assertSame($cause->id, $payment->payable->cause_id);
        $this->assertSame(route('donations.show', ['uuid' => $payment->uuid, 'in-iframe' => 1]), $transfer->json('frame_url'));

        // Card: the frame shows the gateway's payment page, which returns to the framed result.
        $card = $this->withHeaders($headers)->postJson('/api/site/donations', $this->form(['amount' => '100', 'method' => 'gateway:'.$gateway->id]))->assertCreated()
            ->assertJsonPath('frame_url', 'https://sandbox.iyzico/pay?token=tok&iframe=true');
        $paid = Payment::where('uuid', $card->json('uuid'))->sole();

        $this->flushHeaders();
        $this->post(route('payments.callback', $gateway), ['token' => 'tok'])->assertRedirect(route('donations.show', ['uuid' => $paid->uuid, 'in-iframe' => 1]));
        $this->assertTrue($paid->fresh()->isPaid());
        $this->get(route('donations.show', ['uuid' => $paid->uuid, 'in-iframe' => 1]))->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://www.ornek.org.tr")
            ->assertSee('bağışınız alındı')->assertDontSee('navbar-brand');
        $this->get(route('donations.show', ['uuid' => $payment->uuid, 'in-iframe' => 1]))->assertOk()->assertSee($payment->reference)->assertSee('Örnek Bankası');
    }
}
