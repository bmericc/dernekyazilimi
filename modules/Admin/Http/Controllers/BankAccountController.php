<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Rules\Iban;
use App\Support\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bank accounts shown to payers choosing bank transfer.
 */
class BankAccountController extends Controller
{
    public function index(Payments $payments): View
    {
        return view('admin::payments.bank-accounts', [
            'accounts' => BankAccount::orderBy('sort')->orderBy('id')->get(),
            'purposes' => $payments->purposes(),
        ]);
    }

    public function store(Request $request, Payments $payments): RedirectResponse
    {
        $account = BankAccount::create($this->validated($request, $payments));

        return back()->with('success-status', "{$account->bank_name} hesabı eklendi.");
    }

    public function update(Request $request, BankAccount $account, Payments $payments): RedirectResponse
    {
        $account->update($this->validated($request, $payments));

        return back()->with('success-status', "{$account->bank_name} hesabı güncellendi.");
    }

    public function destroy(BankAccount $account): RedirectResponse
    {
        $account->delete();

        return back()->with('success-status', "{$account->bank_name} hesabı silindi.");
    }

    private function validated(Request $request, Payments $payments): array
    {
        $request->merge(['iban' => Iban::normalize($request->input('iban'))]);

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'account_holder' => ['required', 'string', 'max:150'],
            'iban' => ['required', new Iban()],
            'currency' => ['required', Rule::in(['TRY', 'USD', 'EUR'])],
            'purposes' => ['nullable', 'array'],
            'purposes.*' => [Rule::in(array_keys($payments->purposes()))],
            'sort' => ['nullable', 'integer', 'between:0,1000'],
        ], [], ['bank_name' => 'Banka', 'branch' => 'Şube', 'account_holder' => 'Hesap sahibi', 'iban' => 'IBAN', 'currency' => 'Para birimi']);

        return $data + ['is_active' => $request->boolean('is_active'), 'purposes' => $data['purposes'] ?? null, 'sort' => $data['sort'] ?? 0];
    }
}
