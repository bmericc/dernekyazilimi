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
        ]);

        $settings->save([
            'dues_public_page' => $request->boolean('public_page') ? '1' : '0',
            'dues_exempt_affiliation_types' => implode(',', $data['exempt_types'] ?? []) ?: null,
            'dues_intro' => trim($sanitizer->sanitizePage($data['intro'] ?? '')) ?: null,
        ]);
        $this->set_log('change', 'Aidat ayarları güncellendi');

        return back()->with('success-status', 'Aidat ayarları kaydedildi.');
    }
}
