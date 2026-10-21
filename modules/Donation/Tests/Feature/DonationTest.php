<?php

namespace Modules\Donation\Tests\Feature;

use App\Events\ContactAnonymized;
use App\Models\Agreement;
use App\Models\AgreementAcceptance;
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

    public function test_the_donate_link_is_in_the_menu_for_guests_too(): void
    {
        $this->get('/donate')->assertOk()
            ->assertSee('href="'.route('donations.create').'"', false)
            ->assertDontSee('href="'.route('representations.index').'"', false);
    }

    public function test_without_payment_methods_the_page_says_donations_are_closed(): void
    {
        $this->get('/donate')->assertOk()->assertSee('çevrim içi bağış alınmıyor');
        $this->post('/donate', $this->form())->assertNotFound();
    }

    public function test_a_donation_needs_the_payment_terms_in_force_accepted(): void
    {
        $this->account();
        $agreement = Agreement::create(['key' => Agreement::PAYMENT_TERMS, 'title' => 'Ödeme, İptal ve İade Koşulları']);
        $agreement->versions()->create(['version' => 1, 'content' => '<p>Metin</p>'])->forceFill(['published_at' => now()])->save();

        $this->get('/donate')->assertOk()->assertSee('Ödeme, İptal ve İade Koşulları')->assertSee('name="payment_terms"', false);
        $this->post('/donate', $this->form())->assertSessionHasErrors('payment_terms');
        $this->assertSame(0, Payment::count());

        $user = User::factory()->create();
        $this->actingAs($user)->post('/donate', $this->form(['payment_terms' => 'true']))->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());
        $this->assertSame('donation', AgreementAcceptance::where('user_id', $user->id)->sole()->context);
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

    public function test_only_the_listed_amounts_can_be_donated_when_free_amounts_are_off(): void
    {
        $this->account();
        app(\App\Support\Organization::class)->save(['donation_amounts' => '100,250', 'donation_fixed_only' => '1']);

        $this->get('/donate')->assertOk()->assertSee('type="radio" name="amount" value="250"', false)->assertDontSee('id="amount"', false);
        $this->post('/donate', $this->form(['amount' => '300']))->assertSessionHasErrors('amount');
        $this->post('/donate', $this->form(['amount' => '250']))->assertSessionHasNoErrors();
        $this->assertSame('250.00', Payment::sole()->amount);

        app(\App\Support\Organization::class)->save(['donation_fixed_only' => '0']);
        $this->post('/donate', $this->form(['amount' => '300']))->assertSessionHasNoErrors();
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

    public function test_with_turnstile_keys_a_donation_needs_the_human_check(): void
    {
        $this->account();
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => fn ($request) => Http::response(['success' => $request['response'] === 'good'])]);

        $this->get('/donate')->assertOk()->assertSee('data-sitekey="site-key"', false)->assertSee('turnstile/v0/api.js', false);
        $this->post('/donate', $this->form())->assertSessionHasErrors('turnstile');
        $this->post('/donate', $this->form(['cf-turnstile-response' => 'bad']))->assertSessionHasErrors('turnstile');
        $this->assertSame(0, Payment::count());

        $this->post('/donate', $this->form(['cf-turnstile-response' => 'good']))->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());

        // The web site shows the widget with the key from the config and sends its answer as "turnstile".
        app(\App\Support\Organization::class)->save(['frame_ancestors' => 'https://www.ornek.org.tr']);
        $headers = ['X-Api-Key' => app(\App\Support\SiteApi::class)->generateKey(), 'X-Site-Url' => 'https://www.ornek.org.tr', 'X-Client-Ip' => '203.0.113.7'];
        $this->withHeaders($headers)->getJson('/api/site/config')->assertJsonPath('donation.turnstile_site_key', 'site-key');
        $this->withHeaders($headers)->postJson('/api/site/donations', $this->form())->assertUnprocessable()->assertJsonValidationErrors('turnstile');
        $this->withHeaders($headers)->postJson('/api/site/donations', $this->form(['turnstile' => 'good']))->assertCreated();
        Http::assertSent(fn ($request) => str_contains($request->url(), 'siteverify') && $request['remoteip'] === '203.0.113.7');
    }

    public function test_card_payments_stop_at_the_hourly_ceiling_of_the_whole_site(): void
    {
        $this->account();
        $gateway = $this->gateway();
        app(\App\Support\Organization::class)->save(['donation_card_hourly_limit' => '2']);
        Http::fake(['*/checkoutform/initialize/*' => Http::response(['status' => 'success', 'token' => 'tok', 'paymentPageUrl' => 'https://sandbox.iyzico/pay'])]);

        $this->post('/donate', $this->form(['method' => 'gateway:'.$gateway->id]))->assertRedirect('https://sandbox.iyzico/pay');
        $this->post('/donate', $this->form(['method' => 'gateway:'.$gateway->id]))->assertRedirect('https://sandbox.iyzico/pay');
        $this->post('/donate', $this->form(['method' => 'gateway:'.$gateway->id]))->assertSessionHasErrors('method');
        $this->assertSame(2, Payment::count());

        // Transfer is not limited.
        $this->post('/donate', $this->form())->assertSessionHasNoErrors();
        $this->assertSame(3, Payment::count());
    }

    public function test_too_many_refused_cards_stop_card_donations_and_tell_management(): void
    {
        $this->account();
        $gateway = $this->gateway();
        app(\App\Support\Organization::class)->save(['donation_card_failure_limit' => '2', 'notification_email' => 'yk@example.org']);
        Http::fake([
            '*/checkoutform/initialize/*' => Http::sequence()->push(['status' => 'success', 'token' => 'tok1', 'paymentPageUrl' => 'https://sandbox.iyzico/pay'])
                ->push(['status' => 'success', 'token' => 'tok2', 'paymentPageUrl' => 'https://sandbox.iyzico/pay']),
            '*/checkoutform/auth/ecom/detail' => Http::response(['status' => 'success', 'paymentStatus' => 'FAILURE', 'errorMessage' => 'Kart reddedildi']),
        ]);

        foreach (['tok1', 'tok2'] as $token) {
            $this->post('/donate', $this->form(['method' => 'gateway:'.$gateway->id]));
            $this->post(route('payments.callback', $gateway), ['token' => $token]);
        }

        $this->assertSame(2, Payment::where('status', Payment::FAILED)->count());
        $this->assertTrue(app(\Modules\Donation\Support\DonationSettings::class)->cardPaused());
        Mail::assertQueued(\App\Mail\TemplateMail::class, fn ($mail) => $mail->key === 'donation.card-paused' && $mail->hasTo('yk@example.org'));

        // Only transfer is offered, on the portal and to the web site.
        $this->get('/donate')->assertOk()->assertSee('Havale / EFT')->assertDontSee('Kredi / banka kartı');
        $this->post('/donate', $this->form(['method' => 'gateway:'.$gateway->id]))->assertSessionHasErrors('method');

        // Management opens card donations again.
        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/donations/settings')->assertOk()->assertSee('name="card_paused"', false);
        $this->actingAs($owner)->put('/admin/donations/settings', ['open' => '1', 'minimum' => 10, 'card_hourly_limit' => 30, 'card_failure_limit' => 10])->assertSessionHasNoErrors();
        $this->assertFalse(app(\Modules\Donation\Support\DonationSettings::class)->cardPaused());
        $this->get('/donate')->assertSee('Kredi / banka kartı');
    }
}
