<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * All collections; pending transfers are confirmed here once they show up
 * in the bank account.
 */
class PaymentAdminController extends Controller
{
    public function index(Request $request, Payments $payments): View
    {
        $filters = $request->only(['status', 'method', 'purpose', 'q']);

        $query = Payment::with('contact')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->where('method', $method))
            ->when($filters['purpose'] ?? null, fn ($query, $purpose) => $query->where('purpose', $purpose))
            ->when(trim($filters['q'] ?? ''), fn ($query, $q) => $query->where(fn ($query) => $query
                ->where('reference', 'like', '%'.strtoupper($q).'%')->orWhere('payer_name', 'like', "%$q%")->orWhere('payer_email', 'like', "%$q%")));

        return view('admin::payments.index', [
            'payments' => (clone $query)->latest('id')->paginate(30)->withQueryString(),
            'total' => (clone $query)->where('status', Payment::SUCCEEDED)->sum('amount'),
            'pendingTransfers' => Payment::where('status', Payment::PENDING)->where('method', Payment::TRANSFER)->count(),
            'filters' => $filters,
            'purposes' => $payments->purposes(),
        ]);
    }

    public function show(Payment $payment, Payments $payments): View
    {
        return view('admin::payments.show', [
            'payment' => $payment->load(['contact', 'bankAccount', 'gateway', 'recorder']),
            'purpose' => $payments->purposeLabel($payment->purpose),
            'payableLink' => $payments->payableLink($payment),
            'accounts' => BankAccount::active()->get(),
        ]);
    }

    public function confirm(Request $request, Payment $payment, Payments $payments): RedirectResponse
    {
        abort_unless($payment->isPending() && $payment->method !== Payment::CARD, 403);

        $data = $request->validate([
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'bank_account_id' => [Rule::requiredIf($payment->method === Payment::TRANSFER), 'nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['paid_at' => 'Tahsil tarihi', 'bank_account_id' => 'Banka hesabı']);

        $payments->confirm($payment, Carbon::parse($data['paid_at']), isset($data['bank_account_id']) ? BankAccount::find($data['bank_account_id']) : null, $data['note'] ?? null);
        $this->set_log('change', "Ödeme onaylandı ({$payment->reference})");

        return back()->with('success-status', 'Ödeme tahsil edildi olarak işaretlendi.');
    }

    public function cancel(Request $request, Payment $payment, Payments $payments): RedirectResponse
    {
        abort_unless($payment->isPending(), 403);

        $payments->cancel($payment, $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null);
        $this->set_log('change', "Ödeme iptal edildi ({$payment->reference})");

        return back()->with('success-status', 'Ödeme iptal edildi.');
    }

    public function refund(Request $request, Payment $payment, Payments $payments): RedirectResponse
    {
        abort_unless($payment->isPaid(), 403);

        $payments->refund($payment, $request->validate(['note' => ['required', 'string', 'max:1000']], [], ['note' => 'Açıklama'])['note']);
        $this->set_log('change', "Ödeme iade edildi olarak işaretlendi ({$payment->reference})");

        return back()->with('success-status', 'Ödeme iade edildi olarak işaretlendi.');
    }
}
