<?php

namespace Modules\Membership\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\IdentityCheck;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Membership\Http\Controllers\Concerns\HandlesApplicationForm;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipReference;
use Modules\Membership\Support\ApplicationPdf;
use Modules\Membership\Support\ApplicationService;
use Modules\Membership\Support\MembershipService;
use Modules\Membership\Support\MembershipSettings;

class ApplicationAdminController extends Controller
{
    use HandlesApplicationForm;

    /**
     * Enter a form that came in on paper or by email.
     */
    public function create(MembershipSettings $settings): View
    {
        return view('membership::admin.applications.create', [
            'settings' => $settings,
            'fields' => $this->fields(new Contact()),
            'values' => [],
            'defaults' => ['nationality' => 'T.C.', 'nationality_type' => 'tr', 'submitted_on' => today()->toDateString(), 'signed_form' => '1'],
        ]);
    }

    public function store(Request $request, ApplicationService $service, MembershipSettings $settings, IdentityCheck $identity): RedirectResponse
    {
        $data = $this->validateForm($request, $settings, $identity, $this->fields(new Contact()), [
            'submitted_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $contact = $this->applicant($data);
        if ($blocker = $service->contactBlocker($contact)) {
            throw ValidationException::withMessages(['email' => $contact->display_name.': '.$blocker]);
        }
        $referees = $this->referees($data, $contact, $service);
        $answers = $this->answers($data, $contact, $this->fields($contact));

        $application = $service->submit($contact, null, $answers, $referees, Carbon::parse($data['submitted_on']));
        if ($request->boolean('signed_form')) {
            $application->forceFill(['signed_form_received_at' => now()])->save();
        }
        $this->set_log('create', "Üyelik başvurusu yönetimden girildi ({$application->reference_no})");

        return redirect()->route('admin.membership-applications.show', $application)->with('success-status', 'Başvuru kaydedildi; adaya bilgi e-postası, referanslara davet gönderildi.');
    }
    public function index(Request $request): View
    {
        $status = $request->query('status', MembershipApplication::READY);

        return view('membership::admin.applications.index', [
            'applications' => MembershipApplication::with(['contact', 'references'])
                ->when(array_key_exists((string) $status, MembershipApplication::STATUSES), fn ($query) => $query->where('status', $status))
                ->tap(TableSort::by($request, [
                    'reference' => 'reference_no',
                    'name' => fn ($query, $direction) => $query->orderBy(Contact::withTrashed()->select('first_name')->whereColumn('contacts.id', 'membership_applications.contact_id'), $direction)->orderBy(Contact::withTrashed()->select('last_name')->whereColumn('contacts.id', 'membership_applications.contact_id'), $direction),
                    'date' => 'submitted_at',
                    'signed' => 'signed_form_received_at',
                    'status' => 'status',
                ], fn ($query) => $query->latest('id')))->paginate(25)->withQueryString(),
            'counts' => MembershipApplication::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'status' => $status,
        ]);
    }

    public function show(MembershipApplication $application, MembershipService $memberships): View
    {
        return view('membership::admin.applications.show', [
            'application' => $application->load(['contact', 'membership', 'references.referee']),
            'highestNumber' => $memberships->highestNumber(),
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
        // Only once the references are in and the entry fee (if any) is paid.
        abort_unless($application->status === MembershipApplication::READY, 403);

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

    /**
     * The person on the form: found by TC identity number or email, else a
     * new contact without an account. Only empty fields of a known person
     * are filled.
     */
    private function applicant(array $data): Contact
    {
        $contact = (! empty($data['identity_number']) ? Contact::where('identity_number', $data['identity_number'])->first() : null)
            ?? Contact::where('email', strtolower($data['email']))->first()
            ?? new Contact();

        foreach ([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'],
            'identity_number' => $data['identity_number'] ?? null,
            'birthday' => $data['birthday'],
            'gender' => $data['gender'],
        ] as $key => $value) {
            if (($contact->{$key} === null || $contact->{$key} === '') && $value !== null) {
                $contact->{$key} = $value;
            }
        }
        $contact->save();

        return $contact;
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
