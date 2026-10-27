<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\SigningSession;
use Modules\Correspondence\Support\LetterPackage;
use Modules\Correspondence\Support\LetterPdf;
use Modules\Correspondence\Support\PdfSigningDocument;

/**
 * Makes the link the signer pastes into the signing application for the
 * step the package is waiting for.
 */
class SigningLinkController extends Controller
{
    public function pdf(Request $request, Letter $letter, LetterPdf $pdf): RedirectResponse
    {
        abort_unless($letter->isNumbered() && ! $letter->hasSignedPdf() && ! $letter->package_path, 403);
        $method = $request->validate(['signing_method' => ['required', 'in:external,application']])['signing_method'];
        if ($method === 'external') {
            return back()->with('pdf-signing-external', true);
        }
        $path = $letter->directory().'/signing.pdf';
        if (! Storage::disk('local')->exists($path)) {
            Storage::disk('local')->put($path, PdfSigningDocument::prepare($pdf->render($letter)));
        }
        [, $token] = SigningSession::issue($letter, SigningSession::PDF, Auth::user());

        return back()->with('pdf-signing-link', route('correspondence.signing', $token));
    }

    public function store(Letter $letter, LetterPackage $packages): RedirectResponse
    {
        $package = $packages->open($letter);
        $step = $package ? LetterPackage::pendingStep($package) : null;
        abort_if($step === null, 403);

        [, $token] = SigningSession::issue($letter, $step, Auth::user());
        $this->set_log('create', SigningSession::STEPS[$step]." için imza bağlantısı oluşturuldu ({$letter->document_no})");

        return back()->with('signing-link', route('correspondence.signing', $token));
    }
}
