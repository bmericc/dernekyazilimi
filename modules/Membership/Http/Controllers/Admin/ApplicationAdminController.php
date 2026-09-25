<?php

namespace Modules\Membership\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipReference;
use Modules\Membership\Support\ApplicationPdf;
use Modules\Membership\Support\ApplicationService;
use Modules\Membership\Support\MembershipService;

class ApplicationAdminController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status', MembershipApplication::READY);

        return view('membership::admin.applications.index', [
            'applications' => MembershipApplication::with(['contact', 'references'])
                ->when(array_key_exists((string) $status, MembershipApplication::STATUSES), fn ($query) => $query->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'counts' => MembershipApplication::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'status' => $status,
        ]);
    }

    public function show(MembershipApplication $application, MembershipService $memberships): View
    {
        return view('membership::admin.applications.show', [
            'application' => $application->load(['contact', 'membership', 'references.referee']),
            'nextNumber' => $memberships->nextNumber(),
        ]);
    }

    public function pdf(MembershipApplication $application, ApplicationPdf $pdf): Response
    {
        return response($pdf->render($application), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($application).'"',
        ]);
    }

    public function signedForm(MembershipApplication $application): RedirectResponse
    {
        $application->forceFill(['signed_form_received_at' => $application->signed_form_received_at ? null : now()])->save();

        return back()->with('success-status', $application->signed_form_received_at ? 'İmzalı form alındı olarak işaretlendi.' : 'İşaret kaldırıldı.');
    }

    public function resend(MembershipReference $reference, ApplicationService $service): RedirectResponse
    {
        abort_unless($reference->application->isOpen() && in_array($reference->status, [MembershipReference::PENDING, MembershipReference::EXPIRED], true), 403);

        $service->reinvite($reference);

        return back()->with('success-status', "{$reference->referee->display_name} adresine yeni davet gönderildi.");
    }

    public function approve(Request $request, MembershipApplication $application, ApplicationService $service): RedirectResponse
    {
        abort_unless($application->isOpen(), 403);

        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:20', Rule::unique('memberships', 'number')->ignore($application->membership_id)],
            'joined_at' => ['required', 'date'],
            'decision_date' => ['nullable', 'date'],
            'decision_number' => ['nullable', 'string', 'max:50'],
        ], [], ['number' => 'Üye no', 'joined_at' => 'Katılma tarihi', 'decision_date' => 'Karar tarihi', 'decision_number' => 'Karar numarası']);

        $service->approve($application, $data['number'] ?? null, Carbon::parse($data['joined_at']), isset($data['decision_date']) ? Carbon::parse($data['decision_date']) : null, $data['decision_number'] ?? null);
        $this->set_log('change', "Üyelik başvurusu kabul edildi ({$application->reference_no})");

        return back()->with('success-status', 'Başvuru kabul edildi; kişi üye yapıldı.');
    }

    public function reject(Request $request, MembershipApplication $application, ApplicationService $service): RedirectResponse
    {
        abort_unless($application->isOpen(), 403);

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']], [], ['note' => 'Gerekçe']);

        $service->reject($application, $data['note']);
        $this->set_log('change', "Üyelik başvurusu reddedildi ({$application->reference_no})");

        return back()->with('success-status', 'Başvuru reddedildi.');
    }
}
