<?php

namespace Modules\Membership\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Membership\Models\MembershipFee;
use Modules\Membership\Support\MembershipSettings;

/**
 * Entry fee and yearly dues per year, past years included.
 */
class MembershipFeeController extends Controller
{
    public function index(MembershipSettings $settings): View
    {
        return view('membership::admin.fees', ['fees' => MembershipFee::orderByDesc('year')->get(), 'entryFee' => $settings->chargesEntryFee()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $fee = MembershipFee::create($this->validated($request));
        $this->set_log('create', "Aidat tanımlandı ({$fee->year})");

        return back()->with('success-status', "{$fee->year} yılı aidatı kaydedildi.");
    }

    public function update(Request $request, MembershipFee $fee): RedirectResponse
    {
        $fee->update($this->validated($request, $fee));
        $this->set_log('change', "Aidat güncellendi ({$fee->year})");

        return back()->with('success-status', "{$fee->year} yılı aidatı güncellendi.");
    }

    public function destroy(MembershipFee $fee): RedirectResponse
    {
        $fee->delete();
        $this->set_log('delete', "Aidat silindi ({$fee->year})");

        return back()->with('success-status', "{$fee->year} yılı aidatı silindi.");
    }

    private function validated(Request $request, ?MembershipFee $fee = null): array
    {
        return $request->validate([
            'year' => ['required', 'integer', 'between:1900,2100', Rule::unique('membership_fees', 'year')->ignore($fee?->id)],
            'entry_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'annual_fee' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['year' => 'Yıl', 'entry_fee' => 'Giriş aidatı', 'annual_fee' => 'Yıllık aidat', 'note' => 'Not']);
    }
}
