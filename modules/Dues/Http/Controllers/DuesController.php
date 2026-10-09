<?php

namespace Modules\Dues\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Agreements;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Dues\Support\DuesService;
use Modules\Dues\Support\DuesSettings;
use Throwable;

/**
 * The member's dues: signed in (/my-dues) or through the personal link of
 * the public balance lookup (/odeme), which shows nothing on the page and
 * sends the balance to the member's email.
 */
class DuesController extends Controller
{
    public function mine(DuesService $service, DuesSettings $settings): View
    {
        $contact = Auth::user()->syncContact();

        return $this->page($contact, $service, $settings, route('dues.pay'));
    }

    public function pay(Request $request, DuesService $service, Payments $payments): RedirectResponse
    {
        return $this->startPayment($request, Auth::user()->syncContact(), $service, $payments, route('dues.mine'));
    }

    public function lookupForm(DuesSettings $settings): View
    {
        abort_unless($settings->publicPage(), 404);

        return view('dues::lookup', ['settings' => $settings]);
    }

    public function lookup(Request $request, DuesService $service, DuesSettings $settings): RedirectResponse
    {
        abort_unless($settings->publicPage(), 404);
        $data = $request->validate(['identifier' => ['required', 'string', 'max:150']], [], ['identifier' => 'T.C. kimlik no, e-posta ya da cep telefonu']);

        if ($contact = $service->lookup($data['identifier'])) {
            try {
                $service->sendStatement($contact);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // The same answer whether or not someone matched: the page must not
        // tell who is a member.
        return back()->with('success-status', 'Bilgiler sistemdeki bir üyeyle eşleşiyorsa aidat bakiyesi ve ödeme bağlantısı kayıtlı e-posta adresine gönderildi.');
    }

    public function publicAccount(Request $request, Contact $contact, string $hash, DuesService $service, DuesSettings $settings): View
    {
        abort_unless(hash_equals($service->hash($contact), $hash), 403);

        return $this->page($contact, $service, $settings, $request->fullUrl());
    }

    public function publicPay(Request $request, Contact $contact, string $hash, DuesService $service, Payments $payments): RedirectResponse
    {
        abort_unless(hash_equals($service->hash($contact), $hash), 403);

        return $this->startPayment($request, $contact, $service, $payments, $request->fullUrl());
    }

    /**
     * Result page of a payment, reached by its unguessable id.
     */
    public function show(string $uuid): View
    {
        $payment = Payment::where('uuid', $uuid)->where('purpose', 'dues')->firstOrFail();

        return view('dues::show', [
            'payment' => $payment,
            'accounts' => $payment->method === Payment::TRANSFER ? BankAccount::for('dues') : collect(),
        ]);
    }

    private function page(Contact $contact, DuesService $service, DuesSettings $settings, string $payUrl): View
    {
        return view('dues::account', [
            'contact' => $contact,
            'account' => $service->account($contact),
            'methods' => $service->methods(),
            'settings' => $settings,
            'payUrl' => $payUrl,
        ]);
    }

    private function startPayment(Request $request, Contact $contact, DuesService $service, Payments $payments, string $back): RedirectResponse
    {
        $agreements = app(Agreements::class);
        $methods = $service->methods();
        $balance = $service->account($contact)->balance();
        abort_unless($methods && $balance > 0, 404);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$balance, 'decimal:0,2'],
            'method' => ['required', Rule::in(array_keys($methods))],
            'payment_terms' => $agreements->rules(Agreement::PAYMENT_TERMS),
        ], ['amount.max' => 'Borcunuzdan (:max TL) fazla ödeme yapılamaz.'], ['amount' => 'Tutar', 'method' => 'Ödeme yöntemi', 'payment_terms' => 'Ödeme koşulları']);
        if (Auth::check()) {
            $agreements->accept(Auth::user(), 'dues', Agreement::PAYMENT_TERMS);
        }

        $payment = $service->pay($contact, $data['amount'], $data['method']);
        $result = route('dues.show', $payment->uuid);
        if ($payment->method === Payment::TRANSFER) {
            return redirect($result);
        }

        try {
            $gateway = PaymentGateway::findOrFail((int) substr($data['method'], strlen('gateway:')));

            return redirect()->away($payments->startCard($payment, $gateway, $result, [
                'identity_number' => $contact->identity_number,
                'item' => 'Üyelik aidatı',
            ]));
        } catch (Throwable $e) {
            $payments->startFailed($payment, $e);

            return redirect($back)->withInput()->with('danger-status', 'Kart ödemesi şu anda başlatılamadı. Lütfen biraz sonra tekrar deneyin ya da havale ile ödeyin.');
        }
    }
}
