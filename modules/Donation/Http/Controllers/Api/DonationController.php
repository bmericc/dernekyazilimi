<?php

namespace Modules\Donation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Payments\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Donation\Support\DonationService;
use Modules\Donation\Support\DonationSettings;
use Throwable;

/**
 * A donation started on the association's web site: the site's own form
 * sends the donor's answers, then shows "frame_url" in a frame: the payment
 * page of the card gateway, or the bank accounts for a transfer.
 */
class DonationController extends Controller
{
    public function store(Request $request, DonationSettings $settings, DonationService $service, Payments $payments): JsonResponse
    {
        $methods = $service->methods();
        if (! $settings->open() || ! $methods) {
            return response()->json(['message' => 'Şu anda çevrim içi bağış alınmıyor.'], 422);
        }

        $data = $request->validate($service->rules($methods), [], DonationService::ATTRIBUTES);
        $payment = $service->submit($data, null);
        $result = route('donations.show', ['uuid' => $payment->uuid, 'in-iframe' => 1]);

        if ($payment->method === Payment::CARD) {
            try {
                $gateway = PaymentGateway::findOrFail((int) substr($data['method'], strlen('gateway:')));
                $frame = $gateway->driverClass()::frameUrl($payments->startCard($payment, $gateway, $result, ['item' => 'Bağış']));
            } catch (Throwable $e) {
                $payments->startFailed($payment, $e);

                return response()->json(['message' => 'Kart ödemesi şu anda başlatılamadı. Lütfen biraz sonra tekrar deneyin ya da havale ile bağış yapın.'], 502);
            }
        }

        return response()->json([
            'uuid' => $payment->uuid,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'frame_url' => $frame ?? $result,
            'result_url' => route('donations.show', $payment->uuid),
        ], 201);
    }
}
