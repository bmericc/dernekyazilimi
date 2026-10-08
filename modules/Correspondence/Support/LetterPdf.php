<?php

namespace Modules\Correspondence\Support;

use App\Support\Organization;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Correspondence\Models\Letter;

/**
 * The letter as a PDF in the layout of an official letter. A letter without
 * a number is marked as a draft.
 */
class LetterPdf
{
    public function __construct(private Organization $organization)
    {
    }

    public function render(Letter $letter): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Serif');
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('correspondence::pdf.letter', [
            'letter' => $letter->loadMissing(['recipients', 'attachments']),
            'logo' => $this->logo(),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(Letter $letter): string
    {
        return 'yazi-'.(Str::slug((string) $letter->document_no) ?: 'taslak-'.$letter->id).'.pdf';
    }

    /**
     * The organization logo as a data URI; dompdf needs GD for raster images.
     */
    private function logo(): ?string
    {
        $path = $this->organization->get('logo_path');
        if (! extension_loaded('gd') || ! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:'.Storage::disk('local')->mimeType($path).';base64,'.base64_encode(Storage::disk('local')->get($path));
    }
}
