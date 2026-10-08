<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Correspondence\Support\CorrespondenceSettings;
use Modules\Correspondence\Support\Numbering;

class SettingsController extends Controller
{
    public function edit(CorrespondenceSettings $settings): View
    {
        return view('correspondence::admin.settings', [
            'settings' => $settings,
            'example' => Numbering::format($settings->numberFormat(), now()->year, 41, '010.06'),
        ]);
    }

    public function update(Request $request, CorrespondenceSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'number_format' => ['required', 'string', 'max:60', 'regex:/\{sira(:\d)?\}/'],
            'start_number' => ['required', 'integer', 'between:1,1000000'],
            'identifier' => ['nullable', 'string', 'max:30'],
        ], ['number_format.regex' => 'Sayı biçiminde {sira} bulunmalıdır.'], ['number_format' => 'Sayı biçimi', 'start_number' => 'Başlangıç numarası', 'identifier' => 'MERSİS numarası']);

        $settings->save([
            'correspondence_number_format' => $data['number_format'],
            'correspondence_start_number' => (string) $data['start_number'],
            'correspondence_identifier' => $data['identifier'] ?? null,
        ]);
        $this->set_log('change', 'Yazışma ayarları güncellendi');

        return back()->with('success-status', 'Yazışma ayarları kaydedildi.');
    }
}
