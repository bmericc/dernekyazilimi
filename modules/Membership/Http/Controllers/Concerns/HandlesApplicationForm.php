<?php

namespace Modules\Membership\Http\Controllers\Concerns;

use App\Models\Contact;
use App\Models\CustomField;
use App\Support\CustomFields;
use App\Support\IdentityCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Support\ApplicationService;
use Modules\Membership\Support\MembershipSettings;

/**
 * The application form, shared by the applicant's own page and the page
 * where management enters a form that came in on paper or by email.
 */
trait HandlesApplicationForm
{
    /**
     * Validated answers, the identity checked against the registry.
     *
     * @param  array<string, mixed>  $extraRules
     * @return array<string, mixed>
     */
    protected function validateForm(Request $request, MembershipSettings $settings, IdentityCheck $identity, Collection $fields, array $extraRules = []): array
    {
        $required = $settings->referencesRequired();
        $foreign = $settings->askForeignFields() ? 'required_if:nationality_type,foreign' : 'nullable';
        $customFields = app(CustomFields::class);

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
        ] + $extraRules, [], $customFields->attributes($fields) + $this->formAttributes());

        if ($data['nationality_type'] === 'tr' && ! $identity->verify($data['identity_number'], $data['first_name'], $data['last_name'], Carbon::parse($data['birthday'])->year)) {
            throw ValidationException::withMessages(['identity_number' => 'TC kimlik numarası ad, soyad ve doğum yılıyla eşleşmiyor.']);
        }

        if (! $settings->askForeignFields()) {
            $data = collect($data)->except(['foreign_identity_number', 'residence_permit', 'document_type', 'document_type_other', 'document_number'])->all();
        }

        return $data;
    }

    /**
     * The members named as references, checked one by one.
     *
     * @return Contact[]
     */
    protected function referees(array $data, Contact $applicant, ApplicationService $service): array
    {
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
            if ($blocker = $service->refereeBlocker($referee, $applicant)) {
                throw ValidationException::withMessages([$key => $blocker]);
            }
            $referees[$referee->id] = $referee;
        }

        return array_values($referees);
    }

    /**
     * Save the extra questions on the person; the answers, as shown, go
     * with the application.
     *
     * @return array<string, mixed> answers kept on the application
     */
    protected function answers(array $data, Contact $contact, Collection $fields): array
    {
        $customFields = app(CustomFields::class);
        $customFields->save($contact, $fields, $data['fields'] ?? []);
        $values = $customFields->values($contact);

        $answers = collect($data)->except(['references', 'agreement', 'fields', 'submitted_on', 'signed_form'])->all();
        $answers['fields'] = $fields->mapWithKeys(fn (CustomField $field) => [$field->key => ['label' => $field->label, 'value' => $field->display($values[$field->id] ?? null)]])->all();

        return $answers;
    }

    /**
     * Extra questions of the form: custom fields of the "membership" group
     * that the person may see.
     *
     * @return Collection<int, CustomField>
     */
    protected function fields(Contact $contact): Collection
    {
        return CustomField::active()->for($contact)->where('group', 'membership')
            ->whereIn('member_access', ['visible', 'editable'])->orderBy('sort')->orderBy('id')->get();
    }

    private function formAttributes(): array
    {
        return [
            'gender' => 'Cinsiyet', 'first_name' => 'Ad', 'last_name' => 'Soyad', 'address' => 'Posta adresi', 'email' => 'E-posta',
            'phone' => 'Telefon', 'nationality_type' => 'Uyruk', 'identity_number' => 'TC kimlik no', 'nationality' => 'Tabiiyet',
            'mother_name' => 'Anne adı', 'birthday' => 'Doğum tarihi', 'foreign_identity_number' => 'Yabancı kimlik no',
            'residence_permit' => 'Oturma izni', 'document_type' => 'Belge türü', 'document_type_other' => 'Belge türü (diğer)',
            'document_number' => 'Belge no', 'photo_choice' => 'Fotoğraf tercihi', 'references' => 'Referanslar',
            'references.*.number' => 'Referansın üye no', 'references.*.surname' => 'Referansın soyadı',
            'submitted_on' => 'Başvuru tarihi',
        ];
    }
}
