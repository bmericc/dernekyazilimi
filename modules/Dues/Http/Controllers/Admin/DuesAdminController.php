<?php

namespace Modules\Dues\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Models\DuesExemption;
use Modules\Dues\Support\DuesService;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipFee;
use Throwable;

/**
 * Members' dues: balances, the yearly bulk charge, and per-member charges,
 * collections, exemptions and reminders (from the contact page).
 */
class DuesAdminController extends Controller
{
    public function index(Request $request, DuesService $service): View
    {
        $filter = $request->query('filter', 'debtors');
        $q = trim((string) $request->query('q'));

        $contacts = $service->withBalance(Contact::query()->select('contacts.*'))
            ->whereIn('contacts.id', Membership::select('contact_id'))
            ->when($filter === 'debtors', fn ($query) => $query->whereRaw($service->balanceSql().' > 0'))
            ->when($filter === 'credit', fn ($query) => $query->whereRaw($service->balanceSql().' < 0'))
            ->when($q !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")
                ->orWhereIn('contacts.id', Membership::where('number', $q)->select('contact_id'))))
            ->orderByRaw($service->balanceSql().' desc')->orderBy('last_name')
            ->paginate(50)->withQueryString();

        return view('dues::admin.index', [
            'contacts' => $contacts,
            'numbers' => Membership::whereIn('contact_id', $contacts->pluck('id'))->pluck('number', 'contact_id'),
            'owed' => $service->totalOwed(),
            'collected' => (float) Payment::where('purpose', 'dues')->where('status', Payment::SUCCEEDED)->whereYear('paid_at', now()->year)->sum('amount'),
            'filter' => $filter,
            'q' => $q,
        ]);
    }

    public function preview(Request $request, DuesService $service): View
    {
        $year = (int) $request->query('year', now()->year);
        abort_unless($year >= 1900 && $year <= 2100, 404);
        $rows = $service->preview($year);

        return view('dues::admin.charge', [
            'year' => $year,
            'fee' => MembershipFee::forYear($year),
            'charged' => $rows->whereNull('skip'),
            'skipped' => $rows->whereNotNull('skip'),
        ]);
    }

    public function chargeYear(Request $request, DuesService $service): RedirectResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:1900,2100']])['year'];
        $fee = MembershipFee::forYear($year);
        if (! $fee || (float) $fee->annual_fee <= 0) {
            return back()->with('danger-status', "{$year} yılı için yıllık aidat tanımlı değil.");
        }

        $count = $service->chargeYear($year);
        $this->set_log('create', "{$year} yılı aidatı borçlandırıldı ({$count} üye)");

        return redirect()->route('admin.dues.charge', ['year' => $year])->with('success-status', "{$count} üyeye {$year} yılı aidatı borç yazıldı.");
    }

    public function remindAll(DuesService $service): RedirectResponse
    {
        $sent = 0;
        Contact::query()->whereIn('id', Membership::whereIn('status', [Membership::ACTIVE, Membership::SUSPENDED])->select('contact_id'))
            ->whereRaw($service->balanceSql().' > 0')
            ->each(function (Contact $contact) use ($service, &$sent) {
                try {
                    $sent += (int) $service->sendStatement($contact, reminder: true);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        $this->set_log('other', "Aidat hatırlatması gönderildi ({$sent} üye)");

        return back()->with('success-status', "{$sent} üyeye aidat hatırlatması gönderildi.");
    }

    public function remind(Contact $contact, DuesService $service): RedirectResponse
    {
        if (! $service->sendStatement($contact, reminder: true)) {
            return back()->with('danger-status', 'Kişinin geçerli bir e-posta adresi yok.');
        }
        $this->set_log('other', "Aidat hatırlatması gönderildi ({$contact->display_name})");

        return back()->with('success-status', 'Aidat hatırlatması gönderildi.');
    }

    public function storeCharge(Request $request, Contact $contact, DuesService $service): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(DuesCharge::KINDS))],
            'year' => ['required', 'integer', 'between:1900,2100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'description' => ['required_if:kind,other', 'nullable', 'string', 'max:255'],
        ], [], ['kind' => 'Tür', 'year' => 'Yıl', 'amount' => 'Tutar', 'description' => 'Açıklama']);

        $charge = $service->charge($contact, $data['kind'], (int) $data['year'], $data['amount'], $data['description'] ?? null);
        if (! $charge) {
            return back()->withInput()->with('danger-status', 'Bu kişiye o yılın '.mb_strtolower(DuesCharge::KINDS[$data['kind']]).' borcu zaten yazılmış.');
        }

        return back()->with('success-status', 'Borç eklendi: '.$charge->label().'.');
    }

    public function cancelCharge(Request $request, DuesCharge $charge, DuesService $service): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;
        $service->cancel($charge, $note);

        return back()->with('success-status', 'Borç iptal edildi: '.$charge->label().'.');
    }

    public function restoreCharge(DuesCharge $charge, DuesService $service): RedirectResponse
    {
        $service->restore($charge);

        return back()->with('success-status', 'Borç geri alındı: '.$charge->label().'.');
    }

    public function storePayment(Request $request, Contact $contact, DuesService $service): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in([Payment::TRANSFER, Payment::CASH])],
            'bank_account_id' => ['required_if:method,transfer', 'nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['amount' => 'Tutar', 'paid_at' => 'Tarih', 'method' => 'Yöntem', 'bank_account_id' => 'Banka hesabı']);

        $payment = $service->record($contact, $data['amount'], $data['method'], Carbon::parse($data['paid_at']),
            isset($data['bank_account_id']) ? BankAccount::find($data['bank_account_id']) : null, $data['note'] ?? null);
        $this->set_log('create', "Aidat ödemesi kaydedildi ({$payment->reference}, {$payment->formattedAmount()})");

        return back()->with('success-status', "Ödeme kaydedildi ({$payment->reference}).");
    }

    public function storeExemption(Request $request, Contact $contact): RedirectResponse
    {
        $data = $request->validate([
            'from_year' => ['required', 'integer', 'between:1900,2100'],
            'until_year' => ['nullable', 'integer', 'between:1900,2100', 'gte:from_year'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['from_year' => 'Başlangıç yılı', 'until_year' => 'Bitiş yılı', 'reason' => 'Neden']);

        DuesExemption::create($data + ['contact_id' => $contact->id, 'created_by' => $request->user()->id]);

        return back()->with('success-status', 'Aidat muafiyeti eklendi.');
    }

    public function destroyExemption(DuesExemption $exemption): RedirectResponse
    {
        $exemption->delete();

        return back()->with('success-status', 'Aidat muafiyeti kaldırıldı.');
    }
}
