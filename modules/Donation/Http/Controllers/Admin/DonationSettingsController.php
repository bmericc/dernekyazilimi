<?php

namespace Modules\Donation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Donation\Support\DonationSettings;

class DonationSettingsController extends Controller
{
    public function edit(DonationSettings $settings): View
    {
        return view('donation::admin.settings', ['settings' => $settings]);
    }

    public function update(Request $request, DonationSettings $settings, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $data = $request->validate([
            'amounts' => ['nullable', 'string', 'max:100', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'minimum' => ['required', 'integer', 'between:1,100000'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'thanks' => ['nullable', 'string', 'max:20000'],
        ], ['amounts.regex' => 'Tutarları virgülle ayırarak yazın (ör. 100, 250, 500).'], ['amounts' => 'Önerilen tutarlar', 'minimum' => 'En az tutar']);

        $settings->save([
            'donation_open' => $request->boolean('open') ? '1' : '0',
            'donation_amounts' => preg_replace('/\s+/', '', $data['amounts'] ?? '') ?: null,
            'donation_fixed_only' => $request->boolean('fixed_only') ? '1' : '0',
            'donation_minimum' => (string) $data['minimum'],
            'donation_intro' => trim($sanitizer->sanitizePage($data['intro'] ?? '')) ?: null,
            'donation_thanks' => trim($sanitizer->sanitizePage($data['thanks'] ?? '')) ?: null,
        ]);
        $this->set_log('change', 'Bağış ayarları güncellendi');

        return back()->with('success-status', 'Bağış ayarları kaydedildi.');
    }
}
