<?php

namespace Modules\Donation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Donation\Models\Donation;
use Modules\Donation\Models\DonationCause;
use Modules\Donation\Support\DonationService;

class DonationAdminController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', Payment::SUCCEEDED);
        $year = (int) $request->query('year', now()->year);
        $cause = $request->query('cause');

        $query = Donation::with(['payment', 'cause', 'contact'])
            ->whereHas('payment', fn ($query) => $query->when($status, fn ($query) => $query->where('status', $status)))
            ->whereYear('created_at', $year)
            ->when($cause, fn ($query) => $query->where('cause_id', $cause));

        return view('donation::admin.index', [
            'donations' => (clone $query)->latest('id')->paginate(30)->withQueryString(),
            'total' => Payment::where('purpose', 'donation')->where('status', Payment::SUCCEEDED)->whereYear('paid_at', $year)
                ->when($cause, fn ($query) => $query->whereIn('payable_id', Donation::where('cause_id', $cause)->select('id')))->sum('amount'),
            'causes' => DonationCause::orderBy('name')->get(),
            'accounts' => BankAccount::active()->get(),
            'status' => $status,
            'year' => $year,
            'cause' => $cause,
        ]);
    }

    public function store(Request $request, DonationService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000', 'decimal:0,2'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in([Payment::TRANSFER, Payment::CASH])],
            'bank_account_id' => ['required_if:method,transfer', 'nullable', 'exists:bank_accounts,id'],
            'cause_id' => ['nullable', 'exists:donation_causes,id'],
            'hide_name' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'Bağışçı', 'amount' => 'Tutar', 'paid_at' => 'Tarih', 'method' => 'Yöntem', 'bank_account_id' => 'Banka hesabı']);

        // Link to the person when the email belongs to exactly one contact.
        $contacts = filled($data['email'] ?? null) ? Contact::where('email', $data['email'])->limit(2)->get() : collect();
        $payment = $service->record($data, $contacts->count() === 1 ? $contacts->first() : null);
        $this->set_log('create', "Bağış kaydedildi ({$payment->reference}, {$payment->formattedAmount()})");

        return back()->with('success-status', "Bağış kaydedildi ({$payment->reference}).");
    }
}
