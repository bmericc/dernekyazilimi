<?php

namespace Modules\Correspondence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProcessLogs;
use App\Support\Organization;
use BahriCanli\EYazisma\Enums\PaketAsamasi;
use BahriCanli\EYazisma\Paket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Correspondence\Models\SigningSession;
use Modules\Correspondence\Support\CorrespondenceSettings;
use Modules\Correspondence\Support\LetterPackage;
use Modules\Correspondence\Support\PackageException;

/**
 * What the signing application on the signer's computer talks to. The link
 * is the only credential: single-use, short-lived and bound to one step of
 * one letter.
 */
class SigningController extends Controller
{
    /**
     * The digest to sign, with what the application needs to sign it.
     */
    public function show(string $token, LetterPackage $packages, CorrespondenceSettings $settings, Organization $organization): JsonResponse
    {
        [$session, $package] = $this->session($token, $packages);
        $seal = $session->step === SigningSession::SEAL;

        return response()->json([
            'organization' => $organization->name(),
            'document_no' => $session->letter->document_no,
            'subject' => $session->letter->subject,
            'step' => $session->step,
            'step_label' => SigningSession::STEPS[$session->step],
            'filename' => $seal ? 'NihaiOzet.xml' : 'PaketOzeti.xml',
            'content' => base64_encode($seal ? $package->nihaiOzet() : $package->paketOzeti()),
            // Long-term profile the e-Yazışma guide asks for: CAdES-X Long for the signature, CAdES-A for the seal.
            'profile' => $seal ? 'A' : 'XL',
            'timestamp' => $settings->timestampService(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The signature made over the digest; the link is spent once it is accepted.
     */
    public function store(Request $request, string $token, LetterPackage $packages): JsonResponse
    {
        [$session] = $this->session($token, $packages);

        $data = $request->validate(['signature' => ['required', 'string', 'max:8000000']]);
        $signature = base64_decode($data['signature'], true);

        if ($signature === false || $signature === '') {
            return response()->json(['message' => 'İmza base64 olarak gönderilmelidir.'], 422);
        }

        try {
            $package = $session->step === SigningSession::SEAL
                ? $packages->seal($session->letter, $signature)
                : $packages->sign($session->letter, $signature);
        } catch (PackageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $session->forceFill(['used_at' => now()])->save();

        (new ProcessLogs)->forceFill([
            'process_by' => $session->created_by,
            'process_type' => 'change',
            'process' => 'İmza uygulamasından '.mb_strtolower(SigningSession::STEPS[$session->step])." eklendi ({$session->letter->document_no})",
            'request_ip' => $this->getClientIp(),
        ])->save();

        return response()->json([
            'message' => SigningSession::STEPS[$session->step].' pakete eklendi.',
            'complete' => $package->asama() === PaketAsamasi::Tamamlandi,
        ]);
    }

    /**
     * @return array{0: SigningSession, 1: Paket}
     */
    private function session(string $token, LetterPackage $packages): array
    {
        $session = SigningSession::findUsable($token);
        $package = $session?->letter->isNumbered() ? $packages->open($session->letter) : null;

        // The link is for the step the package was waiting for when it was made.
        $expected = match ($package?->asama()) {
            PaketAsamasi::ImzaBekliyor => SigningSession::SIGNATURE,
            PaketAsamasi::MuhurBekliyor => SigningSession::SEAL,
            default => null,
        };

        abort_if($session === null || $expected !== $session->step, response()->json(['message' => 'İmza bağlantısı geçersiz ya da süresi dolmuş.'], 404));

        return [$session, $package];
    }
}
