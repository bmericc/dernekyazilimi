<?php

namespace Modules\Donation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Agreements;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Donation\Models\Donation;
use Modules\Donation\Models\DonationCause;
use Modules\Donation\Support\DonationService;
use Modules\Donation\Support\DonationSettings;
use Throwable;

/**
 * The public donation page (no account needed) and the donor's donations.
 */
class DonationController extends Controller
{
    public function create(DonationSettings $settings, DonationService $service): View
    {
        $user = Auth::user();

        return view('donation::create', [
            'settings' => $settings,
            'methods' => $settings->open() ? $service->methods() : [],
            'causes' => DonationCause::active()->get(),
            'defaults' => ['name' => $user ? trim($user->name.' '.$user->surname) : null, 'email' => $user?->email, 'phone' => $user?->phone_number],
        ]);
    }

    public function store(Request $request, DonationSettings $settings, DonationService $service, Payments $payments, Agreements $agreements): RedirectResponse
    {
        $methods = $service->methods();
        abort_unless($settings->open() && $methods, 404);

        $data = $request->validate($service->rules($methods), [], DonationService::ATTRIBUTES);

        $payment = $service->submit($data, Auth::user());
        if (Auth::check()) {
            $agreements->accept(Auth::user(), 'donation', Agreement::PRIVACY);
        }

        $result = route('donations.show', $payment->uuid);
        if ($payment->method === Payment::TRANSFER) {
            return redirect($result);
        }

        try {
            $gateway = PaymentGateway::findOrFail((int) substr($data['method'], strlen('gateway:')));
            $user = Auth::user();

            return redirect()->away($payments->startCard($payment, $gateway, $result, [
                'identity_number' => $user?->national_id,
                'item' => 'Bağış',
            ]));
        } catch (Throwable $e) {
            report($e);
            $payments->cancel($payment, 'Kart ödemesi başlatılamadı.');

            return back()->withInput()->with('danger-status', 'Kart ödemesi şu anda başlatılamadı. Lütfen biraz sonra tekrar deneyin ya da havale ile bağış yapın.');
        }
    }

    /**
     * The result page, reached by the payment's unguessable id.
     */
    public function show(string $uuid, DonationSettings $settings): View
    {
        $payment = Payment::where('uuid', $uuid)->where('purpose', 'donation')->firstOrFail();

        return view('donation::show', [
            'payment' => $payment,
            'settings' => $settings,
            'accounts' => $payment->method === Payment::TRANSFER ? BankAccount::for('donation') : collect(),
        ]);
    }

    public function mine(): View
    {
        return view('donation::mine', [
            'donations' => Donation::with(['payment', 'cause'])->where('contact_id', Auth::user()->contact_id)->latest('id')->get(),
        ]);
    }
}
