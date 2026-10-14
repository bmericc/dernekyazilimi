<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Contracts\Messaging\SmsSender;
use App\Mail\TemplateMail;
use App\Models\MailTemplate;
use App\Modules\MailTemplates;
use App\Support\HtmlSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * The emails the system sends: the key and placeholders are fixed in code,
 * the subject and body are edited here.
 */
class MailTemplateController extends Controller
{
    public function index(MailTemplates $templates): View
    {
        return view('admin::mail-templates.index', [
            'groups' => $templates->grouped(),
            'edited' => MailTemplate::where(fn ($query) => $query->whereNotNull('subject')->orWhereNotNull('body')->orWhereNotNull('sms'))->pluck('updated_at', 'key'),
            'smsOn' => MailTemplate::where('sms_enabled', true)->pluck('key')->all(),
        ]);
    }

    public function edit(string $key, MailTemplates $templates): View
    {
        $template = $templates->get($key) ?? abort(404);

        return view('admin::mail-templates.form', [
            'template' => $template,
            'source' => $templates->source($key),
            'common' => MailTemplates::common(),
        ]);
    }

    public function update(Request $request, string $key, MailTemplates $templates, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $template = $templates->get($key) ?? abort(404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:100000'],
            'sms' => [$template['sms'] !== null && $request->boolean('sms_enabled') ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [], ['subject' => 'Konu', 'body' => 'İçerik', 'sms' => 'SMS metni']);

        $body = trim($sanitizer->sanitizePage($data['body']));
        $default = $data['subject'] === $template['subject'] && $body === trim($sanitizer->sanitizePage($template['body']));
        $sms = $template['sms'] === null ? null : trim(preg_replace('/\s+/', ' ', (string) ($data['sms'] ?? '')));

        MailTemplate::updateOrCreate(['key' => $key], [
            'subject' => $default ? null : $data['subject'],
            'body' => $default ? null : $body,
            'sms' => $sms === null || $sms === '' || $sms === $template['sms'] ? null : $sms,
            'sms_enabled' => $template['sms'] !== null && $request->boolean('sms_enabled'),
            'updated_by' => Auth::id(),
        ]);
        $this->set_log('change', "E-posta şablonu güncellendi ({$key})");

        return redirect()->route('admin.mail-templates.edit', $key)->with('success-status', 'Şablon kaydedildi.');
    }

    /**
     * Back to the default text registered in code.
     */
    public function reset(string $key, MailTemplates $templates): RedirectResponse
    {
        $templates->get($key) ?? abort(404);

        // The SMS stays on or off as it was; only the texts go back.
        MailTemplate::where('key', $key)->first()?->update(['subject' => null, 'body' => null, 'sms' => null, 'updated_by' => Auth::id()]);
        $this->set_log('change', "E-posta şablonu varsayılana döndürüldü ({$key})");

        return redirect()->route('admin.mail-templates.edit', $key)->with('success-status', 'Şablon varsayılan metne döndü.');
    }

    /**
     * Send the saved template (and its SMS, when on) to the signed-in user,
     * placeholders showing their descriptions.
     */
    public function test(string $key, MailTemplates $templates): RedirectResponse
    {
        $templates->get($key) ?? abort(404);

        try {
            Mail::to(Auth::user()->email)->sendNow(new TemplateMail($key, $templates->samples($key)));
            $sms = $templates->renderSms($key, $templates->samples($key));
            if ($sms && ($phone = Auth::user()->phone_number)) {
                app(SmsSender::class)->send($phone, $sms);
            }
        } catch (Throwable $e) {
            report($e);

            return back()->with('danger-status', 'Deneme gönderilemedi: '.$e->getMessage());
        }

        return back()->with('success-status', 'Kayıtlı şablon '.Auth::user()->email.' adresine'.(isset($phone) && $sms ? ', SMS\'i '.$phone.' numarasına' : '').' gönderildi.');
    }
}
