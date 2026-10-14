<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
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
            'edited' => MailTemplate::where(fn ($query) => $query->whereNotNull('subject')->orWhereNotNull('body'))->pluck('updated_at', 'key'),
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
        ], [], ['subject' => 'Konu', 'body' => 'İçerik']);

        $body = trim($sanitizer->sanitizePage($data['body']));
        $default = $data['subject'] === $template['subject'] && $body === trim($sanitizer->sanitizePage($template['body']));

        MailTemplate::updateOrCreate(['key' => $key], [
            'subject' => $default ? null : $data['subject'],
            'body' => $default ? null : $body,
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

        MailTemplate::where('key', $key)->first()?->update(['subject' => null, 'body' => null, 'updated_by' => Auth::id()]);
        $this->set_log('change', "E-posta şablonu varsayılana döndürüldü ({$key})");

        return redirect()->route('admin.mail-templates.edit', $key)->with('success-status', 'Şablon varsayılan metne döndü.');
    }

    /**
     * Send the saved template to the signed-in user, placeholders showing
     * their descriptions.
     */
    public function test(string $key, MailTemplates $templates): RedirectResponse
    {
        $templates->get($key) ?? abort(404);

        try {
            Mail::to(Auth::user()->email)->sendNow(new TemplateMail($key, $templates->samples($key)));
        } catch (Throwable $e) {
            report($e);

            return back()->with('danger-status', 'Deneme e-postası gönderilemedi: '.$e->getMessage());
        }

        return back()->with('success-status', 'Kayıtlı şablon '.Auth::user()->email.' adresine gönderildi.');
    }
}
