<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BulkMessage;
use App\Models\BulkMessageRecipient;
use App\Models\User;
use App\Modules\Audiences;
use App\Modules\MailTemplates;
use App\Support\BulkMessaging;
use App\Support\HtmlSanitizer;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Bulk email and SMS to the contacts matching criteria: draft → review the
 * recipients → test to oneself → start; the queue worker sends in batches.
 */
class BulkMessageController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin::bulk-messages.index', [
            'messages' => BulkMessage::with('creator')->tap(TableSort::by($request, [
                'title' => fn ($query, $direction) => $query->orderByRaw("coalesce(nullif(subject, ''), sms) ".$direction),
                'total' => 'total',
                'status' => 'status',
                'creator' => fn ($query, $direction) => $query->orderBy(User::select('name')->whereColumn('users.id', 'bulk_messages.created_by'), $direction),
                'date' => fn ($query, $direction) => $query->orderByRaw('coalesce(started_at, created_at) '.$direction),
            ], fn ($query) => $query->latest('id')))->paginate(25)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        // "Copy" fills the form from an earlier message.
        $source = $request->integer('copy') ? BulkMessage::find($request->integer('copy')) : null;

        return $this->form(new BulkMessage([
            'channels' => $source->channels ?? ['email'],
            'criteria' => $source->criteria ?? [],
            'informational' => $source->informational ?? true,
            'subject' => $source?->subject,
            'body' => $source?->body,
            'sms' => $source?->sms,
        ]));
    }

    public function store(Request $request, Audiences $audiences, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $message = BulkMessage::create($this->validated($request, $audiences, $sanitizer) + ['created_by' => Auth::id()]);

        return redirect()->route('admin.bulk-messages.show', $message)->with('success-status', 'Taslak kaydedildi. Alıcıları gözden geçirip gönderimi başlatın.');
    }

    public function show(BulkMessage $bulkMessage, BulkMessaging $bulk, Audiences $audiences): View
    {
        $audience = $bulkMessage->isDraft() ? $bulk->audience($bulkMessage) : null;

        return view('admin::bulk-messages.show', [
            'bulk' => $bulkMessage,
            'criteria' => $audiences->describe($bulkMessage->criteria ?? []),
            'audience' => $audience,
            'progress' => $bulkMessage->isDraft() ? null : $bulk->progress($bulkMessage),
            'recipients' => $bulkMessage->isDraft() ? null : $bulkMessage->recipients()->with('contact')
                ->orderByRaw('case when error is null then 1 else 0 end')->orderBy('id')->paginate(50),
        ]);
    }

    public function edit(BulkMessage $bulkMessage): View
    {
        abort_unless($bulkMessage->isDraft(), 404);

        return $this->form($bulkMessage);
    }

    public function update(Request $request, BulkMessage $bulkMessage, Audiences $audiences, HtmlSanitizer $sanitizer): RedirectResponse
    {
        abort_unless($bulkMessage->isDraft(), 404);
        $bulkMessage->update($this->validated($request, $audiences, $sanitizer));

        return redirect()->route('admin.bulk-messages.show', $bulkMessage)->with('success-status', 'Taslak kaydedildi.');
    }

    public function destroy(BulkMessage $bulkMessage): RedirectResponse
    {
        abort_unless($bulkMessage->isDraft(), 404);
        $bulkMessage->delete();

        return redirect()->route('admin.bulk-messages')->with('success-status', 'Taslak silindi.');
    }

    /**
     * The message to the signed-in manager, placeholders filled from their own contact.
     */
    public function test(BulkMessage $bulkMessage, BulkMessaging $bulk): RedirectResponse
    {
        $user = Auth::user();
        $recipient = new BulkMessageRecipient([
            'email' => $bulkMessage->uses('email') ? $user->email : null,
            'phone' => $bulkMessage->uses('sms') ? ($user->contact?->phone ?: $user->phone_number) : null,
        ]);
        $recipient->setRelation('contact', $user->contact);
        $recipient->email_status = $recipient->email ? BulkMessageRecipient::PENDING : null;
        $recipient->sms_status = $recipient->phone ? BulkMessageRecipient::PENDING : null;

        try {
            $bulk->deliver($bulkMessage, $recipient);
        } catch (Throwable $e) {
            return back()->with('danger-status', 'Deneme gönderilemedi: '.$e->getMessage());
        }

        return back()->with('success-status', 'Deneme '.collect([$recipient->email, $recipient->phone])->filter()->implode(' ve ').' adresine gönderildi.');
    }

    public function start(BulkMessage $bulkMessage, BulkMessaging $bulk, Audiences $audiences): RedirectResponse
    {
        abort_unless($bulkMessage->isDraft(), 404);

        $count = $bulk->start($bulkMessage, Auth::id());
        $this->set_log('other', "Toplu gönderim başlatıldı: {$bulkMessage->title()} (#{$bulkMessage->id}, {$count} alıcı; ".(implode('; ', $audiences->describe($bulkMessage->criteria ?? [])) ?: 'bütün kişiler').')');

        return redirect()->route('admin.bulk-messages.show', $bulkMessage)->with('success-status', $count
            ? "Gönderim başladı: {$count} alıcıya arka planda, ".config('messaging.bulk_per_batch').' kişilik gruplar hâlinde gönderiliyor.'
            : 'Ölçütlere uyan, gönderilebilecek alıcı yok.');
    }

    public function cancel(BulkMessage $bulkMessage): RedirectResponse
    {
        abort_unless($bulkMessage->status === BulkMessage::SENDING, 404);
        $bulkMessage->update(['status' => BulkMessage::CANCELLED]);
        $this->set_log('other', "Toplu gönderim durduruldu: {$bulkMessage->title()} (#{$bulkMessage->id})");

        return back()->with('success-status', 'Gönderim durduruldu; gönderilmemiş alıcılara gitmeyecek.');
    }

    /**
     * Continue a stopped send, or one whose batches stopped (e.g. the worker was down).
     */
    public function resume(BulkMessage $bulkMessage): RedirectResponse
    {
        abort_unless(in_array($bulkMessage->status, [BulkMessage::SENDING, BulkMessage::CANCELLED], true), 404);
        $bulkMessage->update(['status' => BulkMessage::SENDING]);
        \App\Jobs\SendBulkMessage::dispatch($bulkMessage->id);
        $this->set_log('other', "Toplu gönderim sürdürüldü: {$bulkMessage->title()} (#{$bulkMessage->id})");

        return back()->with('success-status', 'Gönderim kaldığı yerden sürüyor.');
    }

    private function form(BulkMessage $message): View
    {
        return view('admin::bulk-messages.form', [
            'bulk' => $message,
            'criteria' => app(Audiences::class)->criteria(),
            'placeholders' => app(Audiences::class)->placeholders(),
            'common' => MailTemplates::common(),
        ]);
    }

    private function validated(Request $request, Audiences $audiences, HtmlSanitizer $sanitizer): array
    {
        $channels = (array) $request->input('channels', []);
        $data = $request->validate([
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(array_keys(BulkMessage::CHANNELS))],
            'informational' => ['required', 'boolean'],
            'subject' => [in_array('email', $channels, true) ? 'required' : 'nullable', 'string', 'max:255'],
            'body' => [in_array('email', $channels, true) ? 'required' : 'nullable', 'string', 'max:200000'],
            'sms' => [in_array('sms', $channels, true) ? 'required' : 'nullable', 'string', 'max:1000'],
            'criteria' => ['nullable', 'array'],
        ], [], ['channels' => 'Kanal', 'subject' => 'Konu', 'body' => 'E-posta içeriği', 'sms' => 'SMS metni', 'informational' => 'Gönderim türü']);

        return [
            'channels' => array_values(array_unique($data['channels'])),
            'informational' => (bool) $data['informational'],
            'criteria' => $audiences->normalize($data['criteria'] ?? []),
            'subject' => $data['subject'] ?? null,
            'body' => isset($data['body']) ? trim($sanitizer->sanitizePage($data['body'])) : null,
            'sms' => isset($data['sms']) ? trim(preg_replace('/\s+/', ' ', $data['sms'])) : null,
        ];
    }
}
