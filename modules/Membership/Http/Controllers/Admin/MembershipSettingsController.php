<?php

namespace Modules\Membership\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Membership\Support\MembershipSettings;

class MembershipSettingsController extends Controller
{
    public function edit(MembershipSettings $settings): View
    {
        return view('membership::admin.settings', ['settings' => $settings]);
    }

    public function update(Request $request, MembershipSettings $settings, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $data = $request->validate([
            'references_required' => ['required', 'integer', 'between:0,5'],
            'reference_limit_total' => ['nullable', 'integer', 'between:1,1000'],
            'reference_limit_yearly' => ['nullable', 'integer', 'between:1,100'],
            'reference_days' => ['required', 'integer', 'between:1,90'],
            'letter' => ['nullable', 'string', 'max:20000'],
            'instructions' => ['nullable', 'string', 'max:20000'],
        ], [], ['references_required' => 'Gereken referans sayısı', 'reference_limit_total' => 'Toplam referans sınırı', 'reference_limit_yearly' => 'Yıllık referans sınırı', 'reference_days' => 'Davet süresi', 'letter' => 'Dilekçe metni', 'instructions' => 'Yönergeler']);

        $settings->save([
            'membership_applications_open' => $request->boolean('applications_open') ? '1' : '0',
            'membership_references_required' => (string) $data['references_required'],
            'membership_reference_limit_total' => isset($data['reference_limit_total']) ? (string) $data['reference_limit_total'] : null,
            'membership_reference_limit_yearly' => isset($data['reference_limit_yearly']) ? (string) $data['reference_limit_yearly'] : null,
            'membership_reference_days' => (string) $data['reference_days'],
            'membership_photo_choice' => $request->boolean('photo_choice') ? '1' : '0',
            'membership_application_letter' => trim($sanitizer->sanitizePage($data['letter'] ?? '')) ?: null,
            'membership_application_instructions' => trim($sanitizer->sanitizePage($data['instructions'] ?? '')) ?: null,
        ]);
        $this->set_log('change', 'Üyelik başvurusu ayarları güncellendi');

        return back()->with('success-status', 'Üyelik başvurusu ayarları kaydedildi.');
    }
}
