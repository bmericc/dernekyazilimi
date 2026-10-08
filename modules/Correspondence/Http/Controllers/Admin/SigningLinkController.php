<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\SigningSession;
use Modules\Correspondence\Support\LetterPackage;

/**
 * Makes the link the signer pastes into the signing application for the
 * step the package is waiting for.
 */
class SigningLinkController extends Controller
{
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
