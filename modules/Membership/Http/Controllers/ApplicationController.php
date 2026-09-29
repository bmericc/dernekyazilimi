<?php

namespace Modules\Membership\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\CustomField;
use App\Support\CustomFields;
use App\Support\Agreements;
use App\Support\IdentityCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
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
                'nationality' => 'T.C.',
                'nationality_type' => 'tr',
            ],
        ]);
    }

    public function store(Request $request, ApplicationService $service, MembershipSettings $settings, IdentityCheck $identity, Agreements $agreements, CustomFields $customFields): RedirectResponse
    {
        $user = Auth::user();
        if ($blocker = $service->blocker($user)) {
            return redirect()->route('membership.application')->with('danger-status', $blocker);
        }

        $required = $settings->referencesRequired();
        $foreign = $settings->askForeignFields() ? 'required_if:nationality_type,foreign' : 'nullable';
        $fields = $this->fields($user->syncContact());
        $data = $request->validate($customFields->rules($fields) + [
            'gender' => ['required', Rule::in(array_keys(MembershipApplication::GENDERS))],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+() .-]+$/'],
            'nationality_type' => ['required', Rule::in(['tr', 'foreign'])],
            'identity_number' => ['required_if:nationality_type,tr', 'nullable', 'digits:11', 'tckimlik'],
            'nationality' => ['required', 'string', 'max:60'],
            'mother_name' => ['required', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'foreign_identity_number' => [$foreign, 'nullable', 'string', 'max:30'],
            'residence_permit' => [$foreign, 'nullable', Rule::in(['yes', 'no'])],
            'document_type' => [$foreign, 'nullable', Rule::in(array_keys(MembershipApplication::DOCUMENT_TYPES))],
            'document_type_other' => ['required_if:document_type,other', 'nullable', 'string', 'max:60'],
            'document_number' => [$foreign, 'nullable', 'string', 'max:30'],
            'photo_choice' => [$settings->askPhotoChoice() ? 'required' : 'nullable', Rule::in(array_keys(MembershipApplication::PHOTO_CHOICES))],
            'references' => [$required > 0 ? 'required' : 'nullable', 'array', 'size:'.$required],
            'references.*.number' => ['required', 'string', 'max:20'],
            'references.*.surname' => ['required', 'string', 'max:100'],
            'agreement' => $agreements->rules(Agreement::PRIVACY),
        ], [], $customFields->attributes($fields) + $this->attributes());

        if ($data['nationality_type'] === 'tr' && ! $identity->verify($data['identity_number'], $data['first_name'], $data['last_name'], Carbon::parse($data['birthday'])->year)) {
            throw ValidationException::withMessages(['identity_number' => 'TC kimlik numarası ad, soyad ve doğum yılıyla eşleşmiyor.']);
        }

        $referees = [];
        foreach ($data['references'] ?? [] as $index => $input) {
            $key = "references.{$index}.number";
            $referee = $service->findReferee($input['number'], $input['surname']);
            if (! $referee) {
                throw ValidationException::withMessages([$key => 'Bu üye numarası ve soyadıyla eşleşen aktif bir üye bulunamadı.']);
            }
            if (isset($referees[$referee->id])) {
                throw ValidationException::withMessages([$key => 'Aynı üyeyi iki kez referans gösteremezsiniz.']);
            }
            if ($blocker = $service->refereeBlocker($referee, $user->syncContact())) {
                throw ValidationException::withMessages([$key => $blocker]);
            }
            $referees[$referee->id] = $referee;
        }

        if (! $settings->askForeignFields()) {
            $data = collect($data)->except(['foreign_identity_number', 'residence_permit', 'document_type', 'document_type_other', 'document_number'])->all();
        }

        // Extra questions: kept on the person and, as shown, with the application.
        $customFields->save($user->syncContact(), $fields, $data['fields'] ?? []);
        $values = $customFields->values($user->syncContact());
        $answers = collect($data)->except(['references', 'agreement', 'fields'])->all();
        $answers['fields'] = $fields->mapWithKeys(fn (CustomField $field) => [$field->key => ['label' => $field->label, 'value' => $field->display($values[$field->id] ?? null)]])->all();
        $application = $service->submit($user, $answers, array_values($referees));
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

    /**
     * Extra questions of the form: custom fields of the "membership" group
     * that the person may see.
     *
     * @return Collection<int, CustomField>
     */
    private function fields(\App\Models\Contact $contact): Collection
    {
        return CustomField::active()->for($contact)->where('group', 'membership')
            ->whereIn('member_access', ['visible', 'editable'])->orderBy('sort')->orderBy('id')->get();
    }

    private function attributes(): array
    {
        return [
            'gender' => 'Cinsiyet', 'first_name' => 'Ad', 'last_name' => 'Soyad', 'address' => 'Posta adresi', 'email' => 'E-posta',
            'phone' => 'Telefon', 'nationality_type' => 'Uyruk', 'identity_number' => 'TC kimlik no', 'nationality' => 'Tabiiyet',
            'mother_name' => 'Anne adı', 'birthday' => 'Doğum tarihi', 'foreign_identity_number' => 'Yabancı kimlik no',
            'residence_permit' => 'Oturma izni', 'document_type' => 'Belge türü', 'document_type_other' => 'Belge türü (diğer)',
            'document_number' => 'Belge no', 'photo_choice' => 'Fotoğraf tercihi', 'references' => 'Referanslar',
            'references.*.number' => 'Referansın üye no', 'references.*.surname' => 'Referansın soyadı',
        ];
    }
}
