<?php

namespace Modules\FonzipImport\Support;

use App\Models\Cities;
use App\Models\ConsentEvent;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Payment;
use App\Models\Tag;
use App\Support\Audit;
use App\Support\Consents;
use App\Support\CustomFields;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Donation\Models\Donation;
use Modules\Donation\Models\DonationCause;
use Modules\Dues\Models\DuesCharge;
use Modules\FonzipImport\Models\FonzipLink;
use Modules\MailForwarding\Models\EmailRedirects;
use Modules\Membership\Models\Membership;
use Modules\Membership\Support\DerbisImport;
use Modules\Membership\Support\MembershipService;
use Throwable;

/**
 * Import of a Fonzip snapshot: people, membership numbers, custom fields,
 * tags, communication consents, dues debts and payments, donations.
 *
 * People are matched to contacts by an earlier import's link, then member
 * number, T.C. kimlik no and e-mail. Only empty fields are filled; account
 * holders keep their e-mail. Membership status is left to DERBİS: Fonzip
 * has none, so a member without a membership record is opened as active.
 * Historical payments are written as collected without the payment events,
 * so no receipts or thank-you mails go out. Every imported record is linked
 * to its Fonzip id; running the import again adds only what is new.
 */
class FonzipImport
{
    /** Person columns asked from the Fonzip user list. */
    public const USER_FIELDS = [
        'id', 'corporate_type', 'first_name', 'last_name', 'email', 'phone', 'tckno', 'birthday',
        'nationality_text', 'country_text', 'city_text', 'district_text', 'address',
        'membership_no', 'apply_date', 'join_date', 'tags_as_text', 'total_financial',
        'allow_comm_via_email', 'allow_comm_via_sms', 'allow_comm_via_phone',
        'allow_comm_via_email_date', 'allow_comm_via_sms_date', 'allow_comm_via_phone_date',
    ];

    /** Contact fields filled from Fonzip, with their labels. */
    public const CONTACT_FIELDS = [
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'organization_name' => 'Kurum adı',
        'identity_number' => 'Kimlik no',
        'email' => 'E-posta',
        'phone' => 'Telefon',
        'birthday' => 'Doğum tarihi',
        'city_id' => 'İl',
    ];

    /** Account columns that feed the contact (User::syncContact()). */
    private const USER_COLUMNS = [
        'first_name' => 'name',
        'last_name' => 'surname',
        'identity_number' => 'national_id',
        'phone' => 'phone_number',
        'birthday' => 'birthday',
        'city_id' => 'city_id',
    ];

    /** Fonzip consent columns by portal channel. */
    private const CONSENTS = ['email' => 'email', 'sms' => 'sms', 'phone' => 'phone'];

    /** Fonzip payment methods (0 card, 1 cash, 2 transfer, others are card-like). */
    private const METHODS = [1 => Payment::CASH, 2 => Payment::TRANSFER];

    /** The second e-mail has no contact column; it becomes a custom field. */
    private const SECOND_EMAIL = ['key' => 'ikinci_eposta', 'name' => 'İkinci e-posta', 'type' => 'email'];

    private const NOTE = 'Fonzip\'ten aktarıldı';

    /** @var array<string, int>|null  lower-case city name => id */
    private ?array $cities = null;

    public function __construct(private MembershipService $memberships, private CustomFields $customFields, private Consents $consents) {}

    /**
     * What the import would do. Nothing is written.
     */
    public function plan(array $snapshot): array
    {
        $claimed = [];
        $numbers = [];
        $contacts = [];
        foreach ($snapshot['users'] as $user) {
            $contacts[] = $item = $this->planContact($user, $snapshot, $claimed, $numbers);
            if ($item['contact_id']) {
                $claimed[$item['contact_id']] = $item['fonzip_id'];
            }
            if ($item['membership_no'] !== null) {
                $numbers[$item['membership_no']] ??= $item['fonzip_id'];
            }
        }

        $people = collect($contacts)->keyBy('fonzip_id');
        $importable = fn ($userId) => ($person = $people->get((string) $userId)) && $person['action'] !== 'skip';

        return [
            'contacts' => $contacts,
            'counts' => collect($contacts)->countBy('action')->all(),
            'memberships' => collect($contacts)->countBy('membership_action')->except([''])->all(),
            'forwardings' => collect($contacts)->countBy('forwarding')->except([''])->all(),
            'fields' => $this->planFields($snapshot),
            'tags' => collect($snapshot['users'])->pluck('tag_names')->flatten()->filter()->unique()->values()
                ->mapWithKeys(fn ($name) => [$name => Tag::where('name', $name)->exists()])->all(),
            'causes' => collect($snapshot['donations'])->pluck('sub_donation_type__name')->filter()->unique()->values()
                ->mapWithKeys(fn ($name) => [$name => DonationCause::where('name', $name)->exists()])->all(),
            'finance' => [
                'charges' => $this->planRecords($snapshot['debts'], FonzipLink::CHARGE, 'user_id', 'amount', $importable, fn ($debt) => (int) ($debt['status'] ?? 0) === 6),
                'payments' => $this->planRecords($snapshot['payments'], FonzipLink::PAYMENT, 'user_id', 'transaction__amount', $importable),
                'refunds' => $this->planRecords($snapshot['refunds'], FonzipLink::PAYMENT, 'user_id', 'transaction__amount', $importable),
                'donations' => $this->planRecords($snapshot['donations'], FonzipLink::DONATION, null, 'transaction__amount', fn () => true),
            ],
        ];
    }

    /**
     * Apply one part of the snapshot; the job calls it until it returns null.
     *
     * @param  array{phase: string, offset: int}  $position
     * @return array{phase: string, offset: int}|null  where to continue
     */
    public function applyStep(array $snapshot, array $position, array &$summary, ?int $userId = null): ?array
    {
        if ($userId && ! Auth::id()) {
            Auth::onceUsingId($userId);
        }

        $phases = ['setup', 'contacts', 'charges', 'payments', 'refunds', 'donations'];
        $batch = ['contacts' => 50, 'charges' => 500, 'payments' => 500, 'refunds' => 500, 'donations' => 500];
        ['phase' => $phase, 'offset' => $offset] = $position;

        if ($phase === 'setup') {
            $this->setup($snapshot, $summary);
        } else {
            $records = $phase === 'contacts' ? array_values($snapshot['users']) : $snapshot[$phase === 'charges' ? 'debts' : $phase];
            foreach (array_slice($records, $offset, $batch[$phase]) as $record) {
                $this->{'apply'.ucfirst(Str::singular($phase))}($record, $snapshot, $summary);
            }
            $offset += $batch[$phase];
            if ($offset < count($records)) {
                return ['phase' => $phase, 'offset' => $offset];
            }
        }

        $next = $phases[array_search($phase, $phases, true) + 1] ?? null;

        return $next ? ['phase' => $next, 'offset' => 0] : null;
    }

    public static function emptySummary(): array
    {
        return array_fill_keys([
            'contacts_new', 'contacts_updated', 'contacts_unchanged', 'contacts_skipped',
            'memberships_new', 'numbers_set', 'fields_new', 'values', 'tags_attached', 'consents', 'forwardings',
            'charges', 'charges_matched', 'charges_skipped', 'payments', 'payments_skipped',
            'refunds', 'donations',
        ], 0);
    }

    // ---- People ---------------------------------------------------------

    /**
     * @param  array<int, string>  $claimed  contact id => Fonzip id already matched in this plan
     * @param  array<string, string>  $numbers  member number => Fonzip id already seen in this plan
     */
    private function planContact(array $user, array $snapshot, array $claimed = [], array $numbers = []): array
    {
        $item = $this->normalize($user, $snapshot);

        if ($item['membership_no'] !== null && isset($numbers[$item['membership_no']])) {
            $item['problems'][] = "Üye no {$item['membership_no']} Fonzip'te başka bir kişide de var.";
        }

        $contact = $item['problems'] ? null : $this->findContact($item);
        if ($contact && isset($claimed[$contact->id])) {
            $item['problems'][] = "Portaldaki \"{$contact->display_name}\" kişisi başka bir Fonzip kaydıyla da eşleşti.";
            $contact = null;
        }

        $membership = $contact ? Membership::where('contact_id', $contact->id)->first() : null;
        $item['contact_id'] = $contact?->id;
        $item['contact_name'] = $contact?->display_name;
        $item['has_account'] = (bool) $contact?->user;

        if (! $item['problems']) {
            $item['changes'] = $contact ? $this->contactChanges($contact, $item) : [];
            [$item['membership_action'], $item['membership_changes']] = $this->membershipPlan($contact, $membership, $item);
            $item['custom_count'] = count($this->customChanges($contact, $item));
            $item['consent_count'] = count($this->consentChanges($contact, $item));
            $item['tag_count'] = count($this->tagChanges($contact, $item));
            $item['forwarding'] = $this->forwardingPlan($contact, $item);
        }

        $extra = $item['membership_action'] || $item['membership_changes'] || $item['custom_count'] || $item['consent_count'] || $item['tag_count'] || $item['forwarding'] === 'new';
        $item['action'] = match (true) {
            (bool) $item['problems'] => 'skip',
            ! $contact => 'new',
            $item['changes'] || $extra => 'update',
            default => 'unchanged',
        };

        return $item;
    }

    private function normalize(array $user, array $snapshot): array
    {
        $detail = $user['detail'] ?? [];
        $organization = (bool) ($user['corporate_type'] ?? false);
        $item = [
            'fonzip_id' => (string) $user['id'],
            'organization' => $organization,
            'first_name' => $this->text($user['first_name'] ?? null),
            'last_name' => $this->text($user['last_name'] ?? null),
            'org_name' => $organization ? $this->text($detail['name'] ?? trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''))) : null,
            'membership_no' => filled($user['membership_no'] ?? null) ? (string) $user['membership_no'] : null,
            'problems' => [], 'warnings' => [],
            'contact_id' => null, 'contact_name' => null, 'has_account' => false, 'match' => null,
            'changes' => [], 'membership_action' => null, 'membership_changes' => [],
            'custom_count' => 0, 'consent_count' => 0, 'tag_count' => 0, 'forwarding' => null,
            'tag_names' => $user['tag_names'] ?? [],
            'details_missing' => ($user['detail'] ?? null) === null,
        ];

        $identity = preg_replace('/\D/', '', (string) ($user['tckno'] ?? ''));
        $item['identity'] = $identity === '' ? null : $identity;
        if ($item['identity'] && ! $organization && ! DerbisImport::validIdentityNumber($item['identity'])) {
            $item['warnings'][] = 'T.C. kimlik no geçersiz, aktarılmayacak.';
            $item['identity'] = null;
        }

        $email = $user['email'] ?? null ? mb_strtolower(trim($user['email'])) : null;
        $item['email'] = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        if ($email && ! $item['email']) {
            $item['warnings'][] = 'E-posta geçersiz, aktarılmayacak: '.$email;
        }

        $phone = preg_replace('/\D/', '', (string) ($user['phone'] ?? ''));
        $phone = preg_replace('/^(90|0)(?=\d{10}$)/', '', $phone);
        $item['phone'] = strlen($phone) >= 10 && strlen($phone) <= 15 ? $phone : null;

        $item['birthday'] = $organization ? null : $this->date($user['birthday'] ?? null);
        $item['city_id'] = $this->city($user['city_text'] ?? null);
        $item['joined_at'] = $this->date($user['join_date'] ?? null);
        $item['applied_at'] = $this->date($user['apply_date'] ?? null);

        $values = $detail['user_defined_values'] ?? [];
        $item['custom'] = [];
        foreach ($snapshot['fields'] as $field) {
            $value = $this->customValue($values[$field['value']] ?? null, $this->fieldType($field));
            if ($value !== null) {
                $item['custom'][$this->fieldKey($field['value'])] = $value;
            }
        }
        if ($second = $this->text($detail['email_second'] ?? null)) {
            $item['custom'][self::SECOND_EMAIL['key']] = mb_strtolower($second);
        }

        // Association-specific Fonzip fields, named in the module config.
        $year = (int) $this->configured($values, 'member_since');
        $item['member_since'] = $year >= 1900 && $year <= (int) date('Y') ? $year.'-01-01' : null;
        $item['derbis'] = $this->customValue($this->configured($values, 'derbis'), 'checkbox') === '1';
        $alias = mb_strtolower(trim((string) $this->configured($values, 'alias')));
        $item['alias'] = preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/', $alias) ? $alias : null;
        if ($alias !== '' && ! $item['alias']) {
            $item['warnings'][] = 'Takma ad biçimi uygun değil, yönlendirme açılmayacak: '.$alias;
        }

        $item['consents'] = [];
        foreach (self::CONSENTS as $channel => $column) {
            $granted = $user["allow_comm_via_$column"] ?? null;
            if ($granted !== null && $granted !== '') {
                $item['consents'][$channel] = [(bool) $granted, $user["allow_comm_via_{$column}_date"] ?? null];
            }
        }

        if ($item['organization'] ? ! $item['org_name'] : ! $item['first_name']) {
            $item['problems'][] = 'Adı yok.';
        }
        if ($item['details_missing']) {
            $item['warnings'][] = 'Kişi ayrıntıları çekilemedi; özel alanlar aktarılmayacak.';
        }

        return $item;
    }

    private function findContact(array &$item): ?Contact
    {
        if ($id = FonzipLink::where('kind', FonzipLink::CONTACT)->where('fonzip_id', $item['fonzip_id'])->value('linkable_id')) {
            $item['match'] = 'önceki aktarım';

            return Contact::find($id);
        }

        if ($item['membership_no'] && ($membership = Membership::where('number', $item['membership_no'])->first())) {
            $item['match'] = 'üye no';
            $contact = $membership->contact;
            if ($contact && $item['identity'] && $contact->identity_number && $contact->identity_number !== $item['identity']) {
                $item['warnings'][] = 'Üye no eşleşti ama kimlik numarası farklı; kontrol edin.';
            }

            return $contact;
        }

        if ($item['identity']) {
            $matches = Contact::where('identity_number', $item['identity'])->get();
            if ($matches->count() > 1) {
                $item['problems'][] = 'Bu kimlik no ile birden fazla kişi var; önce kişileri birleştirin.';

                return null;
            }
            if ($matches->isNotEmpty()) {
                $item['match'] = 'kimlik no';

                return $matches->first();
            }
        }

        if ($item['email']) {
            $matches = Contact::where('email', $item['email'])->get();
            if ($matches->count() > 1) {
                $item['warnings'][] = 'Bu e-postayla birden fazla kişi var; yeni kişi açılacak.';

                return null;
            }
            $contact = $matches->first();
            if ($contact && $contact->identity_number && $item['identity'] && $contact->identity_number !== $item['identity']) {
                $item['problems'][] = "E-posta \"{$contact->display_name}\" kişisinde kayıtlı ama kimlik numarası farklı.";

                return null;
            }
            if ($contact) {
                $item['match'] = 'e-posta';
            }

            return $contact;
        }

        return null;
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>  field => [old, new]
     */
    private function contactChanges(Contact $contact, array $item): array
    {
        $changes = [];
        foreach ($this->contactValues($item) as $field => $new) {
            $old = $field === 'birthday' ? $contact->birthday?->toDateString() : $contact->{$field};
            if ($new === null || (string) $old === (string) $new || ($field === 'email' && $contact->user)) {
                continue;
            }
            if ($old === null || $old === '') {
                $changes[$field] = [null, (string) $new];
            }
        }

        return $changes;
    }

    private function contactValues(array $item): array
    {
        $person = ! $item['organization'];

        return [
            'first_name' => $person ? $item['first_name'] : null,
            'last_name' => $person ? $item['last_name'] : null,
            'organization_name' => $person ? null : $item['org_name'],
            'identity_number' => $item['identity'],
            'email' => $item['email'],
            'phone' => $item['phone'],
            'birthday' => $item['birthday'],
            'city_id' => $item['city_id'],
        ];
    }

    /**
     * @return array{0: ?string, 1: array<string, array{0: ?string, 1: string}>}  [new|number|null, changes]
     */
    private function membershipPlan(?Contact $contact, ?Membership $membership, array &$item): array
    {
        $number = $item['membership_no'];
        $owner = $number ? Membership::where('number', $number)->first() : null;
        $numberFree = ! $owner || ($membership && $owner->id === $membership->id);

        if (! $membership) {
            if (! $number) {
                return [null, []];
            }
            if (! $numberFree) {
                $item['warnings'][] = "Üye no {$number} portalda başka bir kişide; üyelik açılmayacak.";

                return [null, []];
            }

            return ['new', ['number' => [null, $number]]];
        }

        $changes = [];
        $action = null;
        if ($number && ! $membership->number) {
            if ($numberFree) {
                $changes['number'] = [null, $number];
                $action = 'number';
            } else {
                $item['warnings'][] = "Üye no {$number} portalda başka bir kişide; numara yazılmayacak.";
            }
        } elseif ($number && (string) $membership->number !== $number) {
            $item['warnings'][] = "Portalda üye no {$membership->number}, Fonzip'te {$number}; portaldaki korunur.";
        }

        $joined = $item['joined_at'] ?? $item['member_since'];
        if ($joined && ! $membership->joined_at) {
            $changes['joined_at'] = [null, $joined];
        }
        if ($item['applied_at'] && ! $membership->applied_at) {
            $changes['applied_at'] = [null, $item['applied_at']];
        }
        if ($item['derbis'] && ! $membership->derbis_registered) {
            $changes['derbis_registered'] = ['Hayır', 'Evet'];
        }

        return [$action, $changes];
    }

    /**
     * Custom values to write: only fields the contact has no value in.
     *
     * @return array<string, string>  key => value
     */
    private function customChanges(?Contact $contact, array $item): array
    {
        if (! $contact) {
            return $item['custom'];
        }

        $filled = CustomFieldValue::where('contact_id', $contact->id)
            ->join('custom_fields', 'custom_fields.id', '=', 'custom_field_values.custom_field_id')
            ->pluck('custom_fields.key')->all();

        return array_diff_key($item['custom'], array_flip($filled));
    }

    /**
     * Consents for channels the contact was never asked about.
     */
    private function consentChanges(?Contact $contact, array $item): array
    {
        if (! $contact) {
            return $item['consents'];
        }

        $current = $this->consents->current($contact);

        return array_filter($item['consents'], fn ($consent, $channel) => ($current[$channel] ?? null) === null, ARRAY_FILTER_USE_BOTH);
    }

    private function tagChanges(?Contact $contact, array $item): array
    {
        if (! $contact || ! $item['tag_names']) {
            return $item['tag_names'];
        }

        return array_values(array_diff($item['tag_names'], $contact->tags()->pluck('name')->all()));
    }

    private function applyContact(array $user, array $snapshot, array &$summary): void
    {
        $item = $this->planContact($user, $snapshot);
        if ($item['action'] === 'skip') {
            $summary['contacts_skipped']++;

            return;
        }
        $summary['contacts_'.['new' => 'new', 'update' => 'updated', 'unchanged' => 'unchanged'][$item['action']]]++;

        DB::transaction(function () use ($item, &$summary) {
            $contact = $item['contact_id'] ? Contact::find($item['contact_id']) : null;
            $contact = $this->saveContact($contact, $item);
            FonzipLink::link(FonzipLink::CONTACT, $item['fonzip_id'], $contact);

            $fields = CustomField::whereIn('key', array_keys($item['custom']))->get();
            $values = $this->customChanges($contact, $item);
            $this->customFields->save($contact, $fields->whereIn('key', array_keys($values)), $values);
            $summary['values'] += $fields->whereIn('key', array_keys($values))->count();

            foreach ($this->consentChanges($contact, $item) as $channel => [$granted, $date]) {
                (new ConsentEvent)->forceFill([
                    'contact_id' => $contact->id, 'channel' => $channel, 'granted' => $granted,
                    'source' => 'import', 'user_id' => Auth::id(),
                    'created_at' => $this->dateTime($date) ?? now(),
                ])->save();
                $summary['consents']++;
            }

            $tags = $this->tagChanges($contact, $item);
            if ($tags) {
                $contact->tags()->syncWithoutDetaching(Tag::whereIn('name', $tags)->pluck('id'));
                $summary['tags_attached'] += count($tags);
            }

            $this->saveMembership($contact, $item, $summary);

            if ($this->forwardingPlan($contact, $item) === 'new') {
                $this->saveForwarding($contact, $item);
                $summary['forwardings']++;
            }
        });
    }

    private function saveContact(?Contact $contact, array $item): Contact
    {
        if (! $contact) {
            return Contact::create(array_filter($this->contactValues($item), fn ($value) => $value !== null) + [
                'type' => $item['organization'] ? Contact::TYPE_ORGANIZATION : Contact::TYPE_PERSON,
            ]);
        }

        $changes = array_map(fn ($change) => $change[1], $item['changes']);
        if ($changes === []) {
            return $contact;
        }

        // The account is the source of these fields while it exists.
        if ($user = $contact->user) {
            foreach (self::USER_COLUMNS as $field => $column) {
                if (array_key_exists($field, $changes)) {
                    $user->{$column} = $changes[$field];
                    unset($changes[$field]);
                }
            }
            $user->save();
            $contact->refresh();
        }

        if ($changes) {
            $contact->update($changes);
        }

        return $contact;
    }

    private function saveMembership(Contact $contact, array $item, array &$summary): void
    {
        $membership = Membership::where('contact_id', $contact->id)->first();
        [$action, $changes] = $this->membershipPlan($contact, $membership, $item);

        if ($action === 'new') {
            $joined = $item['joined_at'] ?? $item['member_since'];
            $membership = $this->memberships->start($contact, $item['membership_no'], $joined ? Carbon::parse($joined) : today(), self::NOTE);
            $membership->update([
                'joined_at' => $joined,
                'applied_at' => $item['applied_at'],
                'derbis_registered' => $item['derbis'],
            ]);
            $summary['memberships_new']++;

            return;
        }

        if ($membership && $changes) {
            $membership->update(array_map(fn ($change) => $change[1] === 'Evet' ? true : $change[1], $changes));
            if ($action === 'number') {
                $this->memberships->event($membership, 'number_changed', today(), self::NOTE.': üye no '.$item['membership_no']);
                $summary['numbers_set']++;
            }
        }
    }

    // ---- Mail forwarding ------------------------------------------------

    /**
     * 'new' when the takma ad becomes the person's forwarding on the
     * configured domain; otherwise null or why not. Only account holders
     * can hold a forwarding. The alias is recorded as it already works on
     * the mail server (Fonzip members have it), PostfixAdmin is not called.
     */
    private function forwardingPlan(?Contact $contact, array &$item): ?string
    {
        $domain = mb_strtolower((string) config('fonzip-import.forwarding_domain'));
        if (! $item['alias'] || $domain === '') {
            return null;
        }
        $user = $contact?->user;
        if (! $user) {
            return 'no_account';
        }

        $address = $item['alias'].'@'.$domain;
        $existing = EmailRedirects::where('email_alias', $address)->first();
        if ($existing) {
            if ((int) $existing->user_id !== (int) $user->id) {
                $item['warnings'][] = "$address portalda başka bir hesabın yönlendirmesi.";
            }

            return 'exists';
        }
        if (EmailRedirects::where('user_id', $user->id)->where('domain', $domain)->exists()) {
            $item['warnings'][] = "Hesabın @$domain yönlendirmesi zaten var; takma ad yazılmayacak.";

            return 'exists';
        }
        if (! $this->forwardingTarget($user->email, $address)) {
            $item['warnings'][] = "$address yönlendireceği kişisel adres yok (hesap e-postası aynı adres).";

            return 'no_target';
        }

        return 'new';
    }

    private function forwardingTarget(?string $email, string $address): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && $email !== $address ? $email : null;
    }

    private function saveForwarding(Contact $contact, array $item): void
    {
        $user = $contact->user;
        $address = $item['alias'].'@'.mb_strtolower(config('fonzip-import.forwarding_domain'));
        EmailRedirects::create([
            'user_id' => $user->id,
            'email_alias' => $address,
            'email_forwarding' => $this->forwardingTarget($user->email, $address),
            'status' => 1,
        ]);
    }

    private function configured(array $values, string $name): mixed
    {
        $key = config("fonzip-import.fields.$name");

        return $key ? ($values[$key] ?? null) : null;
    }

    // ---- Lookups (custom fields, tags, causes) -----------------------------

    private function planFields(array $snapshot): array
    {
        $fields = $snapshot['fields'];
        if (collect($snapshot['users'])->contains(fn ($user) => filled($user['detail']['email_second'] ?? null))) {
            $fields[] = ['value' => self::SECOND_EMAIL['key'], 'name' => self::SECOND_EMAIL['name'], 'for' => self::SECOND_EMAIL['type']];
        }

        return collect($fields)->map(fn ($field) => [
            'key' => $this->fieldKey($field['value']),
            'label' => $field['name'],
            'type' => $this->fieldType($field),
            'exists' => CustomField::where('key', $this->fieldKey($field['value']))->exists(),
        ])->values()->all();
    }

    private function setup(array $snapshot, array &$summary): void
    {
        foreach ($this->planFields($snapshot) as $index => $field) {
            if ($field['exists']) {
                continue;
            }
            CustomField::create([
                'key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'],
                'group' => 'membership', 'member_access' => 'hidden', 'applies_to' => 'both', 'sort' => 200 + $index,
            ]);
            $summary['fields_new']++;
        }

        foreach (collect($snapshot['users'])->pluck('tag_names')->flatten()->filter()->unique() as $name) {
            Tag::firstOrCreate(['name' => $name]);
        }
        foreach (collect($snapshot['donations'])->pluck('sub_donation_type__name')->filter()->unique() as $name) {
            DonationCause::firstOrCreate(['name' => $name]);
        }
    }

    // ---- Money ----------------------------------------------------------

    /**
     * @return array{total: int, amount: float, linked: int, orphan: int, cancelled: int}
     */
    private function planRecords(array $records, string $kind, ?string $userKey, string $amountKey, callable $importable, ?callable $cancelled = null): array
    {
        $linked = FonzipLink::map($kind);
        $plan = ['total' => count($records), 'amount' => 0.0, 'new' => 0, 'linked' => 0, 'orphan' => 0, 'cancelled' => 0];
        foreach ($records as $record) {
            if (isset($linked[(string) $record['id']])) {
                $plan['linked']++;
            } elseif ($userKey && ! $importable($record[$userKey] ?? null)) {
                $plan['orphan']++;
            } else {
                $plan['new']++;
                if ($cancelled && $cancelled($record)) {
                    $plan['cancelled']++;
                } else {
                    $plan['amount'] += (float) ($record[$amountKey] ?? 0);
                }
            }
        }
        $plan['amount'] = round($plan['amount'], 2);

        return $plan;
    }

    private function applyCharge(array $debt, array $snapshot, array &$summary): void
    {
        if (FonzipLink::where('kind', FonzipLink::CHARGE)->where('fonzip_id', (string) $debt['id'])->exists()) {
            return;
        }
        $contact = $this->linkedContact($debt['user_id'] ?? null);
        if (! $contact) {
            $summary['charges_skipped']++;

            return;
        }

        $details = $this->text($debt['details'] ?? null);
        $year = $this->year($debt['period'] ?? null) ?? $this->year($debt['operation_date'] ?? null) ?? $this->year($debt['create_date'] ?? null) ?? (int) date('Y');
        $kind = match (true) {
            $details !== null && str_contains(mb_strtolower($details), 'giriş') => DuesCharge::ENTRY,
            filled($debt['period'] ?? null) => DuesCharge::ANNUAL,
            default => DuesCharge::OTHER,
        };
        $key = DuesCharge::periodKey($kind, $year);

        $this->quietly(function () use ($debt, $contact, $details, $year, $kind, $key, &$summary) {
            $existing = $key ? DuesCharge::where('contact_id', $contact->id)->where('period_key', $key)->first() : null;
            if ($existing && ! FonzipLink::where('kind', FonzipLink::CHARGE)->where('linkable_id', $existing->id)->exists()) {
                // Charged in the portal already (e.g. this year's dues): the same debt.
                FonzipLink::link(FonzipLink::CHARGE, $debt['id'], $existing);
                $summary['charges_matched']++;

                return;
            }
            if ($existing) {
                // Fonzip has two debts for the same period: keep the second as "other".
                $kind = DuesCharge::OTHER;
                $key = null;
            }

            $removed = (int) ($debt['status'] ?? 0) === 6;
            $charge = DuesCharge::create([
                'contact_id' => $contact->id, 'kind' => $kind, 'year' => $year,
                'amount' => $debt['amount'] ?? 0,
                'description' => $kind === DuesCharge::OTHER ? ($details ?? 'Fonzip borcu') : $details,
                'period_key' => $key,
                'cancelled_at' => $removed ? now() : null,
                'cancel_note' => $removed ? ($this->text($debt['remove_note'] ?? null) ?? 'Fonzip\'te silinmiş') : null,
                'created_by' => Auth::id(),
            ]);
            if ($created = $this->dateTime($debt['create_date'] ?? null)) {
                $charge->forceFill(['created_at' => $created])->saveQuietly();
            }
            FonzipLink::link(FonzipLink::CHARGE, $debt['id'], $charge);
            $summary['charges']++;
        });
    }

    private function applyPayment(array $row, array $snapshot, array &$summary): void
    {
        $this->savePayment($row, Payment::SUCCEEDED, $summary, 'payments');
    }

    private function applyRefund(array $row, array $snapshot, array &$summary): void
    {
        $this->savePayment($row, Payment::REFUNDED, $summary, 'refunds');
    }

    private function savePayment(array $row, string $status, array &$summary, string $counter): void
    {
        $link = FonzipLink::where('kind', FonzipLink::PAYMENT)->where('fonzip_id', (string) $row['id'])->first();
        if ($link) {
            if ($status === Payment::REFUNDED) {
                Payment::whereKey($link->linkable_id)->update(['status' => Payment::REFUNDED]);
            }

            return;
        }
        $contact = $this->linkedContact($row['user_id'] ?? null);
        if (! $contact) {
            $summary['payments_skipped']++;

            return;
        }

        $this->quietly(function () use ($row, $status, $contact, &$summary, $counter) {
            $payment = $this->payment('dues', Membership::where('contact_id', $contact->id)->first(), $row, $status, $contact, [
                'name' => $this->text($row['user__name'] ?? null) ?? trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? '')),
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
            ]);
            FonzipLink::link(FonzipLink::PAYMENT, $row['id'], $payment);
            $summary[$counter]++;
        });
    }

    private function applyDonation(array $row, array $snapshot, array &$summary): void
    {
        if (FonzipLink::where('kind', FonzipLink::DONATION)->where('fonzip_id', (string) $row['id'])->exists()) {
            return;
        }
        $contact = $this->linkedContact($row['user_id'] ?? null);
        $cause = filled($row['sub_donation_type__name'] ?? null) ? DonationCause::firstOrCreate(['name' => $row['sub_donation_type__name']]) : null;
        $donor = [
            'name' => $this->text($row['name'] ?? null) ?? $contact?->display_name,
            'email' => $row['email'] ?? $row['user__email'] ?? null,
            'phone' => $row['phone'] ?? null,
        ];

        $this->quietly(function () use ($row, $contact, $cause, $donor, &$summary) {
            $donation = Donation::create([
                'cause_id' => $cause?->id, 'contact_id' => $contact?->id,
                'amount' => $row['transaction__amount'] ?? 0,
                'donor_name' => $donor['name'], 'donor_email' => $donor['email'], 'donor_phone' => $donor['phone'] ? mb_substr($donor['phone'], 0, 30) : null,
                'message' => $this->text($row['details'] ?? null) ? mb_substr($row['details'], 0, 1000) : null,
                'source' => 'fonzip', 'created_by' => Auth::id(),
            ]);
            if ($date = $this->dateTime($row['transaction__complete_date'] ?? null)) {
                $donation->forceFill(['created_at' => $date])->saveQuietly();
            }
            $this->payment('donation', $donation, $row, Payment::SUCCEEDED, $contact, $donor);
            FonzipLink::link(FonzipLink::DONATION, $row['id'], $donation);
            $summary['donations']++;
        });
    }

    /**
     * A collected payment written directly: Payments::confirm() would fire
     * PaymentSucceeded and mail receipts for years-old collections.
     */
    private function payment(string $purpose, ?object $payable, array $row, string $status, ?Contact $contact, array $payer): Payment
    {
        $paidAt = $this->dateTime($row['transaction__complete_date'] ?? null) ?? now();
        $payment = new Payment;
        $payment->forceFill([
            'uuid' => (string) Str::uuid(),
            'reference' => $this->reference(),
            'purpose' => $purpose,
            'contact_id' => $contact?->id,
            'payer_name' => $payer['name'] ?? $contact?->display_name,
            'payer_email' => $payer['email'] ?? $contact?->email,
            'payer_phone' => $payer['phone'] ?? $contact?->phone,
            'amount' => $row['transaction__amount'] ?? 0,
            'currency' => $row['transaction__currency__iso_code'] ?? config('payments.currency', 'TRY'),
            'method' => self::METHODS[(int) ($row['transaction__payment_method'] ?? 0)] ?? Payment::CARD,
            'status' => $status,
            'paid_at' => $paidAt,
            'recorded_by' => Auth::id(),
            'note' => trim('Fonzip '.($row['transaction__fonzip_id'] ?? '#'.$row['id']).' '.($this->text($row['details'] ?? null) ?? '')),
            'created_at' => $paidAt,
        ]);
        if ($payable) {
            $payment->payable()->associate($payable);
        }
        $payment->save();

        return $payment;
    }

    /**
     * Bulk history is not audited row by row (the run is logged once). The
     * record and its Fonzip link are written together: a second run, even a
     * concurrent one, fails on the link's unique key and adds nothing.
     */
    private function quietly(callable $callback): void
    {
        Audit::withoutRecording(fn () => DB::transaction($callback));
    }

    private function linkedContact(mixed $fonzipUserId): ?Contact
    {
        if ($fonzipUserId === null) {
            return null;
        }
        $id = FonzipLink::where('kind', FonzipLink::CONTACT)->where('fonzip_id', (string) $fonzipUserId)->value('linkable_id');

        return $id ? Contact::find($id) : null;
    }

    private function reference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPRSTUVYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = substr($code, 0, 4).'-'.substr($code, 4);
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }

    // ---- Values ---------------------------------------------------------

    /**
     * "uyelik-yili" → "uyelik_yili" (portal keys use underscores).
     */
    private function fieldKey(string $key): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::ascii(mb_strtolower($key))), '_');
    }

    private function fieldType(array $field): string
    {
        return match ($field['for'] ?? 'text') {
            'select_w_bool', 'bool', 'boolean', 'checkbox' => 'checkbox',
            'numeric', 'number', 'integer' => 'number',
            'date' => 'date',
            'email' => 'email',
            'textarea' => 'textarea',
            default => 'text',
        };
    }

    private function customValue(mixed $value, string $type): ?string
    {
        if (is_array($value)) {
            $value = $value['text'] ?? null;
        }
        if ($value === null || $value === '') {
            return null;
        }
        if ($type === 'checkbox') {
            $yes = is_bool($value) ? $value : in_array(mb_strtolower(trim((string) $value)), ['evet', 'e', '1', 'true', 'var'], true);

            return $yes ? '1' : null;
        }
        if ($type === 'date') {
            return $this->date((string) $value);
        }

        return $this->text(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    private function city(?string $name): ?int
    {
        if (! filled($name)) {
            return null;
        }
        $this->cities ??= Cities::pluck('id', 'city_name')->mapWithKeys(fn ($id, $city) => [$this->lower($city) => $id])->all();

        return $this->cities[$this->lower($name)] ?? null;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function date(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        return $this->dateTime($value)?->toDateString();
    }

    private function dateTime(?string $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function year(?string $value): ?int
    {
        return filled($value) && preg_match('/^(\d{4})/', $value, $match) ? (int) $match[1] : null;
    }

    private function lower(string $text): string
    {
        return mb_strtolower(strtr(trim($text), ['I' => 'ı', 'İ' => 'i']));
    }
}
