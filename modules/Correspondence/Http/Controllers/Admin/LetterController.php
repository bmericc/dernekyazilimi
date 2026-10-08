<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\HtmlSanitizer;
use BahriCanli\EYazisma\Guid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterRecipient;
use Modules\Correspondence\Support\CorrespondenceSettings;
use Modules\Correspondence\Support\LetterPackage;
use Modules\Correspondence\Support\LetterPdf;
use Modules\Correspondence\Support\Numbering;

class LetterController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $year = $request->query('year');
        $search = trim((string) $request->query('q'));

        return view('correspondence::admin.index', [
            'letters' => Letter::with('recipients')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($year, fn ($query) => $query->where(fn ($query) => $query->where('number_year', $year)->orWhere(fn ($query) => $query->whereNull('number_year')->whereYear('created_at', $year))))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('subject', 'like', "%{$search}%")->orWhere('document_no', 'like', "%{$search}%")))
                ->orderByRaw('number_year is null desc')->orderByDesc('number_year')->orderByDesc('number')->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'status' => $status,
            'year' => $year,
            'search' => $search,
        ]);
    }

    /**
     * A letter to write here, or with ?source=pdf a finished letter to upload as a PDF.
     */
    public function create(Request $request): View
    {
        $letter = new Letter;
        $letter->source = $request->query('source') === Letter::PDF ? Letter::PDF : Letter::COMPOSED;

        return view('correspondence::admin.form', ['letter' => $letter]);
    }

    public function store(Request $request, HtmlSanitizer $sanitizer): RedirectResponse
    {
        $letter = new Letter;
        $letter->source = $request->input('source') === Letter::PDF ? Letter::PDF : Letter::COMPOSED;
        $data = $this->validated($request, $letter);

        DB::transaction(function () use ($letter, $data, $sanitizer, $request) {
            $letter->fill($this->attributes($data, $sanitizer));
            $letter->forceFill(['document_id' => Guid::uret(), 'created_by' => Auth::id()] + $this->documentDetails($letter, $data))->save();
            $this->storePdf($letter, $request);
            $this->syncRecipients($letter, $data['recipients']);
        });

        return redirect()->route('admin.correspondence.show', $letter)->with('success-status', 'Yazı taslak olarak kaydedildi.');
    }

    public function show(Letter $letter, LetterPackage $packages, CorrespondenceSettings $settings): View
    {
        $package = $packages->open($letter);

        return view('correspondence::admin.show', [
            'letter' => $letter->load(['recipients', 'attachments', 'creator', 'approver']),
            'package' => $package,
            'report' => $package?->dogrula(),
            'generation' => $settings->packageGeneration(),
        ]);
    }

    public function edit(Letter $letter): View
    {
        abort_unless($letter->isEditable(), 403);

        return view('correspondence::admin.form', ['letter' => $letter->load('recipients')]);
    }

    public function update(Request $request, Letter $letter, HtmlSanitizer $sanitizer): RedirectResponse
    {
        abort_unless($letter->isEditable(), 403);

        $data = $this->validated($request, $letter);

        DB::transaction(function () use ($letter, $data, $sanitizer, $request) {
            $letter->fill($this->attributes($data, $sanitizer))->forceFill($this->documentDetails($letter, $data))->save();
            $this->storePdf($letter, $request);
            $this->syncRecipients($letter, $data['recipients']);
        });

        return redirect()->route('admin.correspondence.show', $letter)->with('success-status', 'Yazı güncellendi.');
    }

    /**
     * Only a draft can be deleted; a numbered letter is cancelled instead.
     */
    public function destroy(Letter $letter): RedirectResponse
    {
        abort_unless($letter->isEditable(), 403);

        $letter->attachments->each->delete();
        Storage::disk('local')->deleteDirectory($letter->directory());
        $letter->delete();

        return redirect()->route('admin.correspondence')->with('success-status', 'Taslak silindi.');
    }

    public function submit(Letter $letter): RedirectResponse
    {
        abort_unless($letter->status === Letter::DRAFT, 403);

        $letter->forceFill(['status' => Letter::PENDING])->save();

        return back()->with('success-status', 'Yazı onaya gönderildi.');
    }

    /**
     * Back to its writer for changes.
     */
    public function return(Letter $letter): RedirectResponse
    {
        abort_unless($letter->status === Letter::PENDING, 403);

        $letter->forceFill(['status' => Letter::DRAFT])->save();

        return back()->with('success-status', 'Yazı taslağa geri alındı.');
    }

    public function approve(Letter $letter, Numbering $numbering): RedirectResponse
    {
        abort_unless(in_array($letter->status, [Letter::DRAFT, Letter::PENDING], true), 403);

        // An uploaded letter already carries its number.
        $letter->isPdf() ? $numbering->confirm($letter, Auth::user()) : $numbering->assign($letter, Auth::user());

        return back()->with('success-status', "Yazı onaylandı; sayısı {$letter->document_no}.");
    }

    public function cancel(Request $request, Letter $letter): RedirectResponse
    {
        abort_unless($letter->isNumbered(), 403);

        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']], [], ['cancel_reason' => 'İptal nedeni']);

        $letter->forceFill(['status' => Letter::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $data['cancel_reason']])->save();

        return back()->with('success-status', 'Yazı iptal edildi; sayısı yeniden kullanılmaz.');
    }

    public function pdf(Letter $letter, LetterPdf $pdf): Response
    {
        return response($pdf->render($letter), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($letter).'"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Letter $letter): array
    {
        $pdf = $letter->isPdf();

        // Rows left empty in the form are not recipients or signers.
        $request->merge([
            'recipients' => array_values(array_filter((array) $request->input('recipients'), fn ($row) => filled($row['name'] ?? null))),
            'signers' => array_values(array_filter((array) $request->input('signers'), fn ($row) => filled($row['first_name'] ?? null) || filled($row['last_name'] ?? null))),
        ]);

        return $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => [$pdf ? 'nullable' : 'required', 'string', 'max:200000'],
            // An uploaded letter comes with its file, number and date.
            'pdf' => [$pdf && ! $letter->pdf_path ? 'required' : 'nullable', 'file', 'mimes:pdf', 'max:20480'],
            'document_no' => [$pdf ? 'required' : 'nullable', 'string', 'max:80', Rule::unique('correspondence_letters', 'document_no')->ignore($letter->id)],
            'document_date' => [$pdf ? 'required' : 'nullable', 'date'],
            'references' => ['nullable', 'string', 'max:5000'],
            'file_code' => ['nullable', 'string', 'max:30'],
            'file_name' => ['nullable', 'string', 'max:150'],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*.kind' => ['required', Rule::in(array_keys(LetterRecipient::KINDS))],
            'recipients.*.name' => ['required', 'string', 'max:255'],
            'recipients.*.identifier' => ['nullable', 'string', 'max:30'],
            'recipients.*.address' => ['nullable', 'string', 'max:500'],
            'recipients.*.delivery' => ['required', Rule::in(array_keys(LetterRecipient::DELIVERIES))],
            'signers' => ['required', 'array', 'min:1', 'max:4'],
            'signers.*.first_name' => ['required', 'string', 'max:100'],
            'signers.*.last_name' => ['required', 'string', 'max:100'],
            'signers.*.title' => ['nullable', 'string', 'max:150'],
        ], [
            'recipients.required' => 'En az bir alıcı yazın.',
            'signers.required' => 'En az bir imzacı yazın.',
        ], [
            'subject' => 'Konu', 'body' => 'Metin', 'pdf' => 'PDF dosyası', 'document_no' => 'Sayı', 'document_date' => 'Tarih', 'references' => 'İlgi', 'file_code' => 'Dosya planı kodu', 'file_name' => 'Dosya planı adı',
            'recipients.*.name' => 'Alıcı adı', 'recipients.*.kind' => 'Alıcı türü', 'recipients.*.identifier' => 'Alıcı kimliği',
            'recipients.*.address' => 'Alıcı adresi', 'recipients.*.delivery' => 'Dağıtım türü',
            'signers.*.first_name' => 'İmzacı adı', 'signers.*.last_name' => 'İmzacı soyadı', 'signers.*.title' => 'İmzacı unvanı',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, HtmlSanitizer $sanitizer): array
    {
        return [
            'subject' => $data['subject'],
            'body' => trim($sanitizer->sanitizePage($data['body'] ?? '')) ?: null,
            'references' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['references'] ?? ''))))) ?: null,
            'signers' => array_map(fn (array $signer) => [
                'first_name' => trim($signer['first_name']),
                'last_name' => trim($signer['last_name']),
                'title' => filled($signer['title'] ?? null) ? trim($signer['title']) : null,
            ], $data['signers']),
            'file_code' => $data['file_code'] ?? null,
            'file_name' => $data['file_name'] ?? null,
        ];
    }

    /**
     * Number and date of an uploaded letter, as written on it; a letter written here gets them on approval.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function documentDetails(Letter $letter, array $data): array
    {
        return $letter->isPdf() ? ['document_no' => trim($data['document_no']), 'document_date' => $data['document_date']] : [];
    }

    private function storePdf(Letter $letter, Request $request): void
    {
        if (! $letter->isPdf() || ! $request->hasFile('pdf')) {
            return;
        }

        $letter->pdf_path && Storage::disk('local')->delete($letter->pdf_path);
        $file = $request->file('pdf');

        $letter->forceFill([
            'pdf_path' => $file->store($letter->directory(), 'local'),
            'pdf_name' => $file->getClientOriginalName(),
        ])->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function syncRecipients(Letter $letter, array $recipients): void
    {
        $letter->recipients()->delete();

        foreach ($recipients as $sort => $recipient) {
            $letter->recipients()->create([
                'kind' => $recipient['kind'],
                'name' => trim($recipient['name']),
                'identifier' => filled($recipient['identifier'] ?? null) ? trim($recipient['identifier']) : null,
                'address' => filled($recipient['address'] ?? null) ? trim($recipient['address']) : null,
                'delivery' => $recipient['delivery'],
                'sort' => $sort,
            ]);
        }
    }
}
