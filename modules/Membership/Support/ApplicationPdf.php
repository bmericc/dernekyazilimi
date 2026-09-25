<?php

namespace Modules\Membership\Support;

use App\Support\Organization;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Modules\Membership\Models\MembershipApplication;

/**
 * The membership application form as a one-page PDF, filled with the
 * answers as submitted, for the applicant to sign (and send via e-Devlet).
 */
class ApplicationPdf
{
    public function __construct(private Organization $organization, private MembershipSettings $settings)
    {
    }

    public function render(MembershipApplication $application): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('membership::pdf.application', $this->viewData($application))->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(MembershipApplication $application): array
    {
        return [
            'application' => $application->loadMissing(['membership', 'contact', 'currentReferences.referee']),
            'settings' => $this->settings,
            'logo' => $this->logo(),
        ];
    }

    public function filename(MembershipApplication $application): string
    {
        return 'uyelik-basvurusu-'.$application->reference_no.'.pdf';
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
