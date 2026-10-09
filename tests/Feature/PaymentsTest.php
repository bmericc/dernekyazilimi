<?php

namespace Tests\Feature;

use App\Events\PaymentSucceeded;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Rules\Iban;
use App\Support\Payments\Payments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    public const IBAN = 'TR330006100519786457841326';

    public function test_iban_rule_checks_the_checksum(): void
    {
        $valid = fn ($value) => Validator::make(['iban' => Iban::normalize($value)], ['iban' => new Iban()])->passes();

        $this->assertTrue($valid('TR33 0006 1005 1978 6457 8413 26'));
        $this->assertFalse($valid('TR34 0006 1005 1978 6457 8413 26'));
        $this->assertFalse($valid('TR33 0006 1005'));
        $this->assertTrue($valid('DE89370400440532013000'));
    }

    public function test_bank_accounts_are_managed_and_shown_per_purpose(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/bank-accounts')->assertOk();
        $this->actingAs($owner)->post('/admin/bank-accounts', ['bank_name' => 'Banka', 'account_holder' => 'Dernek', 'iban' => 'TR34 0006', 'currency' => 'TRY'])->assertSessionHasErrors('iban');
        $this->actingAs($owner)->post('/admin/bank-accounts', ['bank_name' => 'Banka', 'account_holder' => 'Dernek', 'iban' => 'tr33 0006 1005 1978 6457 8413 26', 'currency' => 'TRY', 'is_active' => '1', 'purposes' => ['donation']])->assertSessionHasNoErrors();
        BankAccount::create(['bank_name' => 'Aidat bankası', 'account_holder' => 'Dernek', 'iban' => self::IBAN, 'purposes' => ['other']]);

        $account = BankAccount::where('bank_name', 'Banka')->sole();
        $this->assertSame(self::IBAN, $account->iban);
        $this->assertSame(['Banka'], BankAccount::for('donation')->pluck('bank_name')->all());

        $manager = User::factory()->create(['role' => 2]);
        $this->actingAs($manager)->get('/admin/bank-accounts')->assertForbidden();
    }

    public function test_gateway_credentials_are_encrypted_and_kept_when_left_blank(): void
    {
        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->post('/admin/payment-gateways', ['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'key-1', 'secret_key' => 'secret-1'], 'is_active' => '1', 'test_mode' => '1'])->assertSessionHasNoErrors();
        $gateway = PaymentGateway::sole();

        $this->assertStringNotContainsString('secret-1', DB::table('payment_gateways')->value('credentials'));
        $this->actingAs($owner)->get('/admin/payment-gateways')->assertOk()->assertDontSee('secret-1')->assertSee(route('payments.callback', $gateway));

        $this->actingAs($owner)->put("/admin/payment-gateways/{$gateway->id}", ['name' => 'iyzico bağış', 'credentials' => ['api_key' => 'key-2', 'secret_key' => ''], 'is_active' => '1'])->assertSessionHasNoErrors();
        $gateway->refresh();
        $this->assertSame('key-2', $gateway->credential('api_key'));
        $this->assertSame('secret-1', $gateway->credential('secret_key'));
        $this->assertFalse($gateway->test_mode);
    }

    public function test_a_card_payment_is_settled_only_by_what_the_gateway_reports(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $gateway = PaymentGateway::create(['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'test_mode' => true]);
        $payments = app(Payments::class);
        $payment = $payments->create('donation', null, 150, Payment::CARD, ['name' => 'Ada Lovelace', 'email' => 'ada@example.org']);

        Http::fake([
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/initialize/*' => Http::response(['status' => 'success', 'token' => 'tok-1', 'paymentPageUrl' => 'https://sandbox.iyzico/pay/tok-1']),
            'sandbox-api.iyzipay.com/payment/iyzipos/checkoutform/auth/ecom/detail' => fn ($request) => Http::response([
                'status' => 'success', 'paymentStatus' => 'SUCCESS', 'paymentId' => '999', 'price' => 150, 'conversationId' => $request['conversationId'],
            ]),
        ]);

        $url = $payments->startCard($payment, $gateway, 'https://portal.test/donate/x');
        $this->assertSame('https://sandbox.iyzico/pay/tok-1', $url);
        Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0] ?? '', 'IYZWSv2 ') && $request['price'] === '150.0' && $request['buyer']['identityNumber'] === '11111111111');

        // Unknown tokens are refused; the callback needs no CSRF token.
        $this->post(route('payments.callback', $gateway), ['token' => 'other'])->assertNotFound();
        $this->post(route('payments.callback', $gateway), ['token' => 'tok-1', 'status' => 'failure'])->assertRedirect('https://portal.test/donate/x');

        $payment->refresh();
        $this->assertTrue($payment->isPaid());
        $this->assertSame('999', $payment->gateway_payment_id);
        Event::assertDispatched(PaymentSucceeded::class, 1);

        // A repeated callback does not settle it again.
        $this->post(route('payments.callback', $gateway), ['token' => 'tok-1']);
        Event::assertDispatched(PaymentSucceeded::class, 1);
    }

    public function test_a_connection_that_fails_to_open_is_tried_again(): void
    {
        $gateway = PaymentGateway::create(['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'test_mode' => true]);
        $payments = app(Payments::class);
        $payment = $payments->create('donation', null, 150, Payment::CARD, ['name' => 'Ada Lovelace', 'email' => 'ada@example.org']);

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Connection timed out');
            }

            return Http::response(['status' => 'success', 'token' => 'tok-9', 'paymentPageUrl' => 'https://sandbox.iyzico/pay/tok-9']);
        });

        $this->assertSame('https://sandbox.iyzico/pay/tok-9', $payments->startCard($payment, $gateway, 'https://portal.test/donate/x'));
        $this->assertSame(3, $attempts);

        // After three failed attempts the error reaches the caller as before.
        $attempts = -10;
        $this->expectException(\Illuminate\Http\Client\ConnectionException::class);
        $payments->startCard($payment, $gateway, 'https://portal.test/donate/x');
    }

    public function test_a_refused_start_reports_the_gateways_error_code(): void
    {
        $gateway = PaymentGateway::create(['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'test_mode' => true]);
        $payments = app(Payments::class);
        $payment = $payments->create('donation', null, 150, Payment::CARD, ['name' => 'Ada Lovelace', 'email' => 'ada@example.org']);

        Http::fake(['*' => Http::response(['status' => 'failure', 'errorCode' => '1001', 'errorMessage' => 'api bilgileri bulunamadı', 'errorGroup' => 'NOT_FOUND'])]);

        $this->expectExceptionMessage('iyzico: api bilgileri bulunamadı [1001 / NOT_FOUND]');
        $payments->startCard($payment, $gateway, 'https://portal.test/donate/x');
    }

    public function test_a_gateway_answer_with_another_amount_fails_the_payment(): void
    {
        $gateway = PaymentGateway::create(['driver' => 'iyzico', 'name' => 'iyzico', 'credentials' => ['api_key' => 'k', 'secret_key' => 's'], 'test_mode' => true]);
        $payment = app(Payments::class)->create('donation', null, 150, Payment::CARD, ['name' => 'Ada', 'email' => 'ada@example.org']);
        $payment->forceFill(['payment_gateway_id' => $gateway->id, 'gateway_token' => 'tok-2'])->save();

        Http::fake(['*' => fn ($request) => Http::response(['status' => 'success', 'paymentStatus' => 'SUCCESS', 'price' => 1, 'conversationId' => $request['conversationId']])]);

        $this->post(route('payments.callback', $gateway), ['token' => 'tok-2']);
        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
    }

    public function test_pending_transfers_are_confirmed_by_management(): void
    {
        Event::fake([PaymentSucceeded::class]);
        $account = BankAccount::create(['bank_name' => 'Banka', 'account_holder' => 'Dernek', 'iban' => self::IBAN]);
        $payment = app(Payments::class)->create('donation', null, 500, Payment::TRANSFER, ['name' => 'Ada', 'email' => 'ada@example.org']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $payment->reference);

        $owner = User::factory()->create(['role' => 1]);
        $this->actingAs($owner)->get('/admin/payments?status=pending')->assertOk()->assertSee($payment->reference)->assertSee('onay bekliyor');
        $this->actingAs($owner)->get("/admin/payments/{$payment->id}")->assertOk()->assertSee('Tahsil edildi');
        $this->actingAs($owner)->patch("/admin/payments/{$payment->id}/confirm", ['paid_at' => today()->toDateString()])->assertSessionHasErrors('bank_account_id');
        $this->actingAs($owner)->patch("/admin/payments/{$payment->id}/confirm", ['paid_at' => today()->toDateString(), 'bank_account_id' => $account->id])->assertRedirect();

        $payment->refresh();
        $this->assertTrue($payment->isPaid());
        $this->assertSame($owner->id, $payment->recorded_by);
        Event::assertDispatched(PaymentSucceeded::class);

        $this->actingAs($owner)->patch("/admin/payments/{$payment->id}/refund", ['note' => 'Yanlış hesap'])->assertRedirect();
        $this->assertSame(Payment::REFUNDED, $payment->fresh()->status);
    }
}
