<?php

namespace Modules\Membership\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Support\CustomFields;
use App\Support\Agreements;
use App\Support\IdentityCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Membership\Http\Controllers\Concerns\HandlesApplicationForm;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipReference;
use Modules\Membership\Support\ApplicationPdf;
use Modules\Membership\Support\ApplicationService;
use Modules\Membership\Support\MembershipSettings;

/**
 * The applicant's side: the form, their application and its PDF.
 */
class ApplicationController extends Controller
{
    use HandlesApplicationForm;

    public function create(ApplicationService $service, MembershipSettings $settings): View|RedirectResponse
    {
        if ($blocker = $service->blocker(Auth::user())) {
            return redirect()->route('membership.application')->with('danger-status', $blocker);
        }

        $user = Auth::user();

        return view('membership::application.create', [
            'user' => $user,
            'settings' => $settings,
            'fields' => $this->fields($user->syncContact()),
            'values' => app(CustomFields::class)->values($user->syncContact()),
            'defaults' => [
                'first_name' => $user->name,
                'last_name' => $user->surname,
                'email' => $user->email,
                'phone' => $user->phone_number,
                'identity_number' => $user->national_id,
                'birthday' => $user->birthday?->toDateString(),
                'gender' => $user->contact?->gender,
                'nationality' => 'T.C.',
                'nationality_type' => 'tr',
            ],
        ]);
    }

    public function store(Request $request, ApplicationService $service, MembershipSettings $settings, IdentityCheck $identity, Agreements $agreements): RedirectResponse
    {
        $user = Auth::user();
        if ($blocker = $service->blocker($user)) {
            return redirect()->route('membership.application')->with('danger-status', $blocker);
        }

        $contact = $user->syncContact();
        $fields = $this->fields($contact);
        $data = $this->validateForm($request, $settings, $identity, $fields, ['agreement' => $agreements->rules(Agreement::PRIVACY)]);
        $referees = $this->referees($data, $contact, $service);

        $application = $service->submit($contact, $user, $this->answers($data, $contact, $fields), $referees);
        $agreements->accept($user, 'membership-application', Agreement::PRIVACY);
        $this->set_log('create', "Üyelik başvurusu yapıldı ({$application->reference_no})");

        return redirect()->route('membership.application')->with('success-status', 'Başvurunuz alındı. Formun PDF hâlini indirip imzalayın.');
    }

    public function show(ApplicationService $service, MembershipSettings $settings): View
    {
        $application = MembershipApplication::with(['references.referee', 'membership'])
            ->where('contact_id', Auth::user()->contact_id)->latest('id')->first();

        return view('membership::application.show', [
            'application' => $application,
            'blocker' => $service->blocker(Auth::user()),
            'settings' => $settings,
        ]);
    }

    public function pdf(ApplicationPdf $pdf): Response
    {
        $application = MembershipApplication::where('contact_id', Auth::user()->contact_id)->latest('id')->firstOrFail();

        return response($pdf->render($application), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($application).'"',
        ]);
    }

    public function replace(Request $request, MembershipReference $reference, ApplicationService $service): RedirectResponse
    {
        $application = $reference->application;
        abort_unless($application->contact_id === Auth::user()->contact_id && $application->isOpen(), 403);
        abort_if($reference->status === MembershipReference::ACCEPTED || $reference->status === MembershipReference::WITHDRAWN, 403);

        $data = $request->validate(['number' => ['required', 'string', 'max:20'], 'surname' => ['required', 'string', 'max:100']], [], ['number' => 'Üye no', 'surname' => 'Soyadı']);

        $referee = $service->findReferee($data['number'], $data['surname']);
        $taken = $application->currentReferences()->whereKeyNot($reference->id)->pluck('referee_contact_id');
        $error = match (true) {
            ! $referee => 'Bu üye numarası ve soyadıyla eşleşen aktif bir üye bulunamadı.',
            $taken->contains($referee->id) => 'Bu üye zaten referansınız.',
            $application->references()->where('referee_contact_id', $referee->id)->where('status', MembershipReference::DECLINED)->exists() => 'Bu üye bu başvuru için referans olmayı kabul etmedi.',
            default => $service->refereeBlocker($referee, $application->contact),
        };
        if ($error) {
            return back()->withErrors(['replace_'.$reference->id => $error]);
        }

        $service->replaceReference($reference, $referee);

        return back()->with('success-status', "{$referee->display_name} referans olarak davet edildi.");
    }

    public function withdraw(ApplicationService $service): RedirectResponse
    {
        $application = $service->openApplication(Auth::user()) ?? abort(404);
        $service->withdraw($application);
        $this->set_log('change', "Üyelik başvurusu geri çekildi ({$application->reference_no})");

        return back()->with('success-status', 'Başvurunuz geri çekildi.');
    }
}
