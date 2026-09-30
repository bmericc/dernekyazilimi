<?php

namespace Modules\Donation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Donation\Models\DonationCause;

class DonationCauseController extends Controller
{
    public function index(): View
    {
        return view('donation::admin.causes', ['causes' => DonationCause::withCount('donations')->orderBy('sort')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cause = DonationCause::create($this->validated($request));

        return back()->with('success-status', "\"{$cause->name}\" eklendi.");
    }

    public function update(Request $request, DonationCause $cause): RedirectResponse
    {
        $cause->update($this->validated($request));

        return back()->with('success-status', "\"{$cause->name}\" güncellendi.");
    }

    public function destroy(DonationCause $cause): RedirectResponse
    {
        if ($cause->donations()->exists()) {
            return back()->with('danger-status', 'Bağış alınmış bir amaç silinemez; pasif yapın.');
        }
        $cause->delete();

        return back()->with('success-status', "\"{$cause->name}\" silindi.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'target_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'sort' => ['nullable', 'integer', 'between:0,1000'],
        ], [], ['name' => 'Ad', 'description' => 'Açıklama', 'target_amount' => 'Hedef tutar']) + ['is_active' => $request->boolean('is_active'), 'sort' => (int) $request->input('sort', 0)];
    }
}
