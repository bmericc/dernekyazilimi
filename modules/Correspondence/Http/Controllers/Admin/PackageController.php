<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use BahriCanli\EYazisma\Paket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Support\CorrespondenceSettings;
use Modules\Correspondence\Support\LetterPackage;
use Modules\Correspondence\Support\PackageException;

/**
 * The e-Yazışma package of a letter: built here, signed and sealed elsewhere.
 * The digests are downloaded, signed with the signer's own tools and the
 * signatures uploaded back.
 */
class PackageController extends Controller
{
    public function store(Request $request, Letter $letter, LetterPackage $packages, CorrespondenceSettings $settings): RedirectResponse
    {
        abort_if($letter->package_path, 403);

        $data = $request->validate(['generation' => ['nullable', Rule::in([LetterPackage::OLD, LetterPackage::CURRENT])]]);

        return $this->attempt(fn () => $packages->create($letter, $data['generation'] ?? $settings->packageGeneration()), 'Paket oluşturuldu; şimdi elektronik imzayla imzalayın.', $letter);
    }

    /**
     * A package without a seal can be discarded and built again; a sealed one stays.
     */
    public function destroy(Letter $letter, LetterPackage $packages): RedirectResponse
    {
        abort_if($packages->open($letter)?->muhur() !== null, 403);

        $packages->delete($letter);
        $this->set_log('delete', "e-Yazışma paketi silindi ({$letter->document_no})");

        return back()->with('success-status', 'Paket silindi.');
    }

    public function show(Letter $letter, LetterPackage $packages): Response
    {
        $package = $this->package($letter, $packages);

        return $this->download($package->icerik(), $package->dosyaAdi(), 'application/eyazisma');
    }

    /** What the electronic signature is made over. */
    public function digest(Letter $letter, LetterPackage $packages): Response
    {
        return $this->download($this->package($letter, $packages)->paketOzeti(), 'PaketOzeti.xml', 'application/xml');
    }

    /** What the electronic seal is made over. */
    public function finalDigest(Letter $letter, LetterPackage $packages): Response
    {
        $digest = $this->package($letter, $packages)->nihaiOzet();
        abort_if($digest === null, 404);

        return $this->download($digest, 'NihaiOzet.xml', 'application/xml');
    }

    public function sign(Request $request, Letter $letter, LetterPackage $packages): RedirectResponse
    {
        $signature = $this->upload($request, 'İmza dosyası');

        return $this->attempt(fn () => $packages->sign($letter, $signature), 'İmza eklendi.', $letter);
    }

    public function seal(Request $request, Letter $letter, LetterPackage $packages): RedirectResponse
    {
        $seal = $this->upload($request, 'Mühür dosyası');

        return $this->attempt(fn () => $packages->seal($letter, $seal), 'Mühür eklendi.', $letter);
    }

    private function package(Letter $letter, LetterPackage $packages): Paket
    {
        return $packages->open($letter) ?? abort(404);
    }

    private function upload(Request $request, string $label): string
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => $label]);

        return $request->file('file')->getContent();
    }

    private function attempt(callable $action, string $message, Letter $letter): RedirectResponse
    {
        try {
            $action();
        } catch (PackageException $e) {
            return back()->with('danger-status', $e->getMessage());
        }

        $this->set_log('change', "e-Yazışma paketi: {$message} ({$letter->document_no})");

        return back()->with('success-status', $message);
    }

    private function download(string $content, string $filename, string $type): Response
    {
        return response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
