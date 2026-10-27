<?php

namespace Modules\Correspondence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProcessLogs;
use App\Support\Organization;
use BahriCanli\EYazisma\Enums\Surum;
use BahriCanli\EYazisma\Paket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\SigningSession;
use Modules\Correspondence\Support\CorrespondenceSettings;
use Modules\Correspondence\Support\LetterPackage;
use Modules\Correspondence\Support\PackageException;
use Modules\Correspondence\Support\PdfSigningDocument;
use Modules\Correspondence\Support\SignedLetterPdf;

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
        if ($session->step === SigningSession::PDF) {
            $pdf = Storage::disk('local')->get($session->letter->directory().'/signing.pdf');

            return response()->json([
                'organization' => $organization->name(),
                'document_no' => $session->letter->document_no,
                'subject' => $session->letter->subject,
                'step' => $session->step,
                'step_label' => SigningSession::STEPS[$session->step],
                'filename' => 'YaziPdfImzaVerisi.bin',
                'content' => base64_encode(PdfSigningDocument::bytes($pdf)),
                'profile' => $settings->timestampService() ? 'T' : 'BES',
                'timestamp' => $settings->timestampService(),
                'expires_at' => $session->expires_at->toIso8601String(),
            ])->header('Cache-Control', 'no-store');
        }
        $seal = $session->step === SigningSession::SEAL;

        return response()->json([
            'organization' => $organization->name(),
            'document_no' => $session->letter->document_no,
            'subject' => $session->letter->subject,
            'step' => $session->step,
            'step_label' => SigningSession::STEPS[$session->step],
            'filename' => $seal ? 'NihaiOzet.xml' : 'PaketOzeti.xml',
            'content' => base64_encode($seal ? $package->nihaiOzet() : $package->paketOzeti()),
            'profile' => $this->profile($package, $seal, $settings),
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

        $data = $request->validate(['signature' => ['required', 'string', 'max:60000000']]);
        $signature = base64_decode($data['signature'], true);

        if ($signature === false || $signature === '') {
            return response()->json(['message' => 'İmza base64 olarak gönderilmelidir.'], 422);
        }

        if ($session->step === SigningSession::PDF) {
            return DB::transaction(function () use ($session, $signature) {
                $locked = SigningSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
                $letter = Letter::whereKey($session->letter_id)->lockForUpdate()->firstOrFail();
                abort_unless(! $locked->used_at && $locked->expires_at->isFuture() && $letter->isNumbered() && ! $letter->hasSignedPdf() && ! $letter->package_path, 409);
                $disk = Storage::disk('local');
                $prepared = $disk->get($letter->directory().'/signing.pdf');
                try {
                    $signed = PdfSigningDocument::complete($prepared, $signature);
                    app(SignedLetterPdf::class)->validate($signed, $prepared, true);
                } catch (PackageException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                $disk->put($letter->signedPdfPath(), $signed);
                $locked->forceFill(['used_at' => now()])->save();
                (new ProcessLogs)->forceFill([
                    'process_by' => $session->created_by,
                    'process_type' => 'change',
                    'process' => "İmza uygulamasından PDF imzalandı ({$letter->document_no})",
                    'request_ip' => $this->getClientIp(),
                ])->save();

                return response()->json(['message' => 'PDF imzalandı. EYP oluşturabilirsiniz.', 'complete' => false]);
            });
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
            'complete' => LetterPackage::isComplete($package),
        ]);
    }

    /**
     * Signature level asked of the application. The 2.x guide wants long-term
     * levels: CAdES-X Long for the signature and CAdES-A for the seal. The
     * layout before 2.0 is signed plainly, time-stamped when a service is set.
     */
    private function profile(Paket $package, bool $seal, CorrespondenceSettings $settings): string
    {
        if ($seal) {
            return 'A';
        }

        if ($package->surum() === Surum::V2) {
            return 'XL';
        }

        return $settings->timestampService() ? 'T' : 'BES';
    }

    /**
     * @return array{0: SigningSession, 1: ?Paket}
     */
    private function session(string $token, LetterPackage $packages): array
    {
        $session = SigningSession::findUsable($token);
        if ($session?->step === SigningSession::PDF) {
            abort_unless($session->letter->isNumbered() && ! $session->letter->hasSignedPdf() && ! $session->letter->package_path, response()->json(['message' => 'PDF imza bağlantısı artık kullanılamaz.'], 404));

            return [$session, null];
        }
        $package = $session?->letter->isNumbered() ? $packages->open($session->letter) : null;

        // The link is for the step the package was waiting for when it was made.
        $expected = $package ? LetterPackage::pendingStep($package) : null;

        abort_if($session === null || $expected !== $session->step, response()->json(['message' => 'İmza bağlantısı geçersiz ya da süresi dolmuş.'], 404));

        return [$session, $package];
    }
}
