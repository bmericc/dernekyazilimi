<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterAttachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attachments of a draft letter: a file, or a physical item named only.
 */
class AttachmentController extends Controller
{
    public function store(Request $request, Letter $letter): RedirectResponse
    {
        abort_unless($letter->isEditable(), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,jpg,jpeg,png,gif,tif,tiff,txt,csv,zip'],
        ], [], ['name' => 'Ekin adı', 'file' => 'Dosya']);

        $file = $request->file('file');

        $letter->attachments()->create([
            'name' => $data['name'],
            'path' => $file?->store($letter->directory().'/ekler', 'local'),
            'original_name' => $file?->getClientOriginalName(),
            'mime' => $file?->getMimeType(),
            'size' => $file?->getSize(),
            'sort' => (int) $letter->attachments()->max('sort') + 1,
        ]);

        return back()->with('success-status', 'Ek eklendi.');
    }

    public function destroy(Letter $letter, LetterAttachment $attachment): RedirectResponse
    {
        abort_unless($letter->isEditable() && $attachment->letter_id === $letter->id, 403);

        $attachment->delete();

        return back()->with('success-status', 'Ek kaldırıldı.');
    }

    public function show(Letter $letter, LetterAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->letter_id === $letter->id && $attachment->hasFile() && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
