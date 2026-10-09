<?php

namespace App\Support\Payments;

use App\Contracts\Payments\CardGateway;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * iyzico Checkout Form (hosted payment page, 3D Secure), over its REST API
 * with IYZWSv2 authentication.
 */
class IyzicoGateway implements CardGateway
{
    private const INITIALIZE = '/payment/iyzipos/checkoutform/initialize/auth/ecom';

    private const DETAIL = '/payment/iyzipos/checkoutform/auth/ecom/detail';

    public function __construct(private PaymentGateway $gateway)
    {
    }

    public static function label(): string
    {
        return 'iyzico';
    }

    public static function logo(): ?string
    {
        return 'images/payments/iyzico.svg';
    }

    public static function credentialFields(): array
    {
        return [
            'api_key' => ['label' => 'API anahtarı'],
            'secret_key' => ['label' => 'Gizli anahtar', 'secret' => true],
        ];
    }

    public function start(Payment $payment, array $buyer, string $callbackUrl): string
    {
        [$first, $last] = $this->splitName((string) $payment->payer_name);
        $price = $this->price($payment->amount);
        $city = ($buyer['city'] ?? null) ?: 'Türkiye';
        $address = ($buyer['address'] ?? null) ?: $city;

        $response = $this->post(self::INITIALIZE, [
            'locale' => 'tr',
            'conversationId' => $payment->reference,
            'price' => $price,
            'paidPrice' => $price,
            'currency' => $payment->currency === 'TRY' ? 'TRY' : $payment->currency,
            'basketId' => $payment->reference,
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $callbackUrl,
            'enabledInstallments' => [1],
            'buyer' => [
                'id' => (string) ($payment->contact_id ?? $payment->reference),
                'name' => $first,
                'surname' => $last,
                'gsmNumber' => $payment->payer_phone ?: null,
                'email' => $payment->payer_email,
                'identityNumber' => ($buyer['identity_number'] ?? null) ?: '11111111111',
                'registrationAddress' => $address,
                'ip' => $payment->ip ?: '127.0.0.1',
                'city' => $city,
                'country' => 'Turkey',
            ],
            'billingAddress' => ['contactName' => trim($first.' '.$last), 'city' => $city, 'country' => 'Turkey', 'address' => $address],
            'basketItems' => [[
                'id' => $payment->purpose,
                'name' => $buyer['item'] ?? $payment->purpose,
                'category1' => $payment->purpose,
                'itemType' => 'VIRTUAL',
                'price' => $price,
            ]],
        ]);

        if (($response['status'] ?? null) !== 'success' || empty($response['paymentPageUrl'])) {
            // The message alone is often generic; the code tells what iyzico refused.
            $code = implode(' / ', array_filter([$response['errorCode'] ?? null, $response['errorGroup'] ?? null]));

            throw new RuntimeException('iyzico: '.($response['errorMessage'] ?? 'ödeme başlatılamadı').($code !== '' ? " [{$code}]" : ''));
        }

        $payment->forceFill(['gateway_token' => $response['token']])->save();

        return $response['paymentPageUrl'];
    }

    public static function frameUrl(string $paymentUrl): string
    {
        return $paymentUrl.(str_contains($paymentUrl, '?') ? '&' : '?').'iframe=true';
    }

    public static function tokenFrom(Request $request): ?string
    {
        return $request->input('token');
    }

    public function complete(Payment $payment): GatewayResult
    {
        $response = $this->post(self::DETAIL, ['locale' => 'tr', 'conversationId' => $payment->reference, 'token' => $payment->gateway_token]);
        $raw = collect($response)->only(['status', 'paymentStatus', 'paymentId', 'price', 'paidPrice', 'currency', 'conversationId', 'basketId', 'errorCode', 'errorMessage', 'mdStatus', 'cardAssociation', 'cardFamily', 'lastFourDigits', 'fraudStatus'])->all();

        $succeeded = ($response['status'] ?? null) === 'success'
            && ($response['paymentStatus'] ?? null) === 'SUCCESS'
            && ($response['conversationId'] ?? null) === $payment->reference
            && abs((float) ($response['price'] ?? 0) - (float) $payment->amount) < 0.01;

        return new GatewayResult($succeeded, $response['paymentId'] ?? null, $succeeded ? null : ($response['errorMessage'] ?? 'Ödeme tamamlanamadı.'), $raw);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $json = json_encode($this->withoutNulls($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $random = now()->getTimestampMs().Str::random(8);
        $signature = hash_hmac('sha256', $random.$path.$json, (string) $this->gateway->credential('secret_key'));
        $authorization = base64_encode('apiKey:'.$this->gateway->credential('api_key').'&randomKey:'.$random.'&signature:'.$signature);

        return Http::withHeaders(['Authorization' => 'IYZWSv2 '.$authorization, 'x-iyzi-rnd' => $random, 'Accept' => 'application/json'])
            ->withBody($json, 'application/json')
            ->connectTimeout(5)
            ->timeout(20)
            // A connection that never opens has not reached iyzico; trying again is safe.
            ->retry(3, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->post($this->baseUrl().$path)
            ->json() ?? [];
    }

    private function baseUrl(): string
    {
        return $this->gateway->test_mode ? 'https://sandbox-api.iyzipay.com' : 'https://api.iyzipay.com';
    }

    /**
     * iyzico prices: "100.0", "150.5".
     */
    private function price(string|float $amount): string
    {
        $value = rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');

        return str_contains($value, '.') ? $value : $value.'.0';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $last = count($parts) > 1 ? array_pop($parts) : '-';

        return [implode(' ', $parts) ?: '-', $last];
    }

    private function withoutNulls(array $data): array
    {
        return array_map(fn ($value) => is_array($value) ? $this->withoutNulls($value) : $value, array_filter($data, fn ($value) => $value !== null));
    }
}
