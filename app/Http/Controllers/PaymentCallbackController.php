<?php

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where the gateway's payment page returns the payer (POST, without our
 * session or CSRF token). The outcome is asked from the gateway, not taken
 * from the request; then the payer goes back to the page that started it.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, Payments $payments): RedirectResponse
    {
        $class = $gateway->driverClass();
        $token = $class ? $class::tokenFrom($request) : null;
        $payment = $token ? $payments->completeCard($gateway, $token) : null;

        abort_unless($payment, 404);

        return redirect()->to($payment->return_url ?: url('/'));
    }
}
