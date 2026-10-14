<?php

namespace Modules\Dues\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliationType;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Dues\Support\DuesSettings;

class DuesSettingsController extends Controller
{
    public function edit(DuesSettings $settings): View
    {
        return view('dues::admin.settings', [
            'settings' => $settings,
            'types' => AffiliationType::where('key', '!=', AffiliationType::MEMBER)->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, DuesSettings $settings, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $data = $request->validate([
            'exempt_types' => ['nullable', 'array'],
            'exempt_types.*' => ['integer', 'exists:affiliation_types,id'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'auto_reminder_day' => ['nullable', 'integer', 'between:1,28'],
            'auto_reminder_min' => ['nullable', 'numeric', 'min:0.01'],
            'entry_fee_reminder_days' => ['nullable', 'integer', 'between:1,365'],
            'entry_fee_deadline_days' => ['nullable', 'integer', 'between:1,365'],
        ], [], ['auto_reminder_day' => 'Hatırlatma günü', 'auto_reminder_min' => 'En az borç', 'entry_fee_reminder_days' => 'Son uyarı günü', 'entry_fee_deadline_days' => 'Ödeme süresi']);

        $settings->save([
            'dues_public_page' => $request->boolean('public_page') ? '1' : '0',
            'dues_exempt_affiliation_types' => implode(',', $data['exempt_types'] ?? []) ?: null,
            'dues_intro' => trim($sanitizer->sanitizePage($data['intro'] ?? '')) ?: null,
            'dues_auto_reminder_day' => isset($data['auto_reminder_day']) ? (string) $data['auto_reminder_day'] : null,
            'dues_auto_reminder_min' => isset($data['auto_reminder_min']) ? (string) $data['auto_reminder_min'] : null,
            'dues_entry_fee_reminder_days' => isset($data['entry_fee_reminder_days']) ? (string) $data['entry_fee_reminder_days'] : null,
            'dues_entry_fee_deadline_days' => isset($data['entry_fee_deadline_days']) ? (string) $data['entry_fee_deadline_days'] : null,
        ]);
        $this->set_log('change', 'Aidat ayarları güncellendi');

        return back()->with('success-status', 'Aidat ayarları kaydedildi.');
    }
}
