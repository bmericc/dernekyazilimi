<?php

namespace Modules\Membership\Support;

use App\Models\AffiliationType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Support\CustomFields;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Membership\Models\Membership;
use Throwable;

/**
 * Import of the member list exported from DERBİS ("Kurum Üyelik Listesi").
 *
 * Rows are matched to contacts by T.C. kimlik no (tax/association number for
 * legal members), then by e-mail. plan() tells what would change without
 * writing anything; apply() carries the plan out. DERBİS is the official
 * register, so its "Aktif"/"Pasif" decides the membership status.
 */
class DerbisImport
{
    /** DERBİS column headers, by the key used here. */
    public const COLUMNS = [
        'kind' => 'Üye Niteliği',
        'name' => 'Ad Soyad / Temsilci Bilgileri',
        'identity' => 'T.C. Kimlik No',
        'gender' => 'Cinsiyet',
        'org_name' => 'Tüzel Adı',
        'org_number' => 'Tüzel Numarası',
        'phone' => 'Telefon No',
        'profession' => 'Meslek',
        'education' => 'Öğrenim Durumu',
        'email' => 'E-Posta',
        'website' => 'İnternet Sitesi',
        'member_type' => 'Üye Tür',
        'honorary' => 'Onursal Üye',
        'status' => 'Durum',
        'decision_date' => 'Yönetim Kurulu Karar Tarihi',
        'birthday' => 'Doğum Tarihi',
        'joined_at' => 'Kayıt Tarihi',
        'left_at' => 'Pasif Olma Tarihi',
        'left_reason' => 'Pasif Olma Nedeni',
        'left_notified_at' => 'Pasif Olma Bildirim Tarihi',
    ];

    /** Columns without a place of their own; they can go to custom fields. */
    public const EXTRA = ['profession', 'education', 'website', 'member_type', 'honorary'];

    private const REQUIRED = ['name', 'identity', 'status'];

    private const DATES = ['decision_date', 'birthday', 'joined_at', 'left_at', 'left_notified_at'];

    /** Contact fields filled from DERBİS, with their labels. */
    public const CONTACT_FIELDS = [
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'organization_name' => 'Kurum adı',
        'identity_number' => 'Kimlik no',
        'email' => 'E-posta',
        'phone' => 'Telefon',
        'birthday' => 'Doğum tarihi',
        'gender' => 'Cinsiyet',
    ];

    /** Account columns that feed the contact (User::syncContact()). */
    private const USER_FIELDS = [
        'first_name' => 'name',
        'last_name' => 'surname',
        'identity_number' => 'national_id',
        'phone' => 'phone_number',
        'birthday' => 'birthday',
    ];

    public function __construct(
        private SpreadsheetReader $reader,
        private MembershipService $memberships,
        private CustomFields $customFields,
    ) {}

    /**
     * Rows of the file as records keyed like COLUMNS.
     *
     * @return array{records: list<array>, error: ?string}
     */
    public function read(string $path, string $extension): array
    {
        try {
            $rows = $this->reader->read($path, $extension);
        } catch (Throwable $exception) {
            return ['records' => [], 'error' => $exception->getMessage()];
        }

        $header = array_shift($rows) ?? [];
        $positions = [];
        foreach ($header as $index => $title) {
            $key = array_search($this->normalizeHeader((string) $title), array_map($this->normalizeHeader(...), self::COLUMNS), true);
            if ($key !== false) {
                $positions[$key] = $index;
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($positions));
        if ($missing) {
            return ['records' => [], 'error' => 'Dosyada şu sütunlar bulunamadı: '.implode(', ', array_map(fn ($key) => self::COLUMNS[$key], $missing)).'. DERBİS\'ten alınan "Kurum Üyelik Listesi" dosyasını yükleyin.'];
        }

        $records = [];
        foreach ($rows as $index => $row) {
            $values = [];
            foreach (self::COLUMNS as $key => $title) {
                $value = isset($positions[$key]) ? trim((string) ($row[$positions[$key]] ?? '')) : '';
                $values[$key] = $value === '' ? null : $value;
            }
            if (array_filter($values) === []) {
                continue;
            }
            $records[] = ['row' => $index + 2] + $values;
        }

        return ['records' => $records, 'error' => $records ? null : 'Dosyada üye satırı yok.'];
    }

    /**
     * What the import would do, row by row. Nothing is written.
     *
     * @param  array{overwrite?: bool, fields?: array<string, int|null>}  $options
     * @return list<array>
     */
    public function plan(array $records, array $options = []): array
    {
        $overwrite = (bool) ($options['overwrite'] ?? false);
        $seen = [];
        $plan = [];

        foreach ($records as $record) {
            $item = $this->normalize($record);
            $key = $item['identity'] ?? ($item['email'] ? 'mail:'.$item['email'] : null);
            if ($key !== null && isset($seen[$key])) {
                $item['problems'][] = "Aynı kişi {$seen[$key]}. satırda da var.";
            }
            if ($key !== null) {
                $seen[$key] ??= $item['row'];
            }

            $contact = $item['problems'] ? null : $this->findContact($item);
            $membership = $contact ? Membership::where('contact_id', $contact->id)->first() : null;

            $item['contact_id'] = $contact?->id;
            $item['contact_name'] = $contact?->display_name;
            $item['has_account'] = (bool) $contact?->user;
            $item['changes'] = $contact && ! $item['problems'] ? $this->contactChanges($contact, $item, $overwrite) : [];
            $item['membership_changes'] = $item['problems'] ? [] : $this->membershipChanges($membership, $item, $overwrite);
            $item['action'] = match (true) {
                (bool) $item['problems'] => 'skip',
                ! $contact => 'new',
                ! $membership => 'new_membership',
                $item['changes'] || $item['membership_changes'] => 'update',
                default => 'unchanged',
            };

            $plan[] = $item;
        }

        return $plan;
    }

    /**
     * Active members who are not in the file (at least not matched).
     */
    public function missingMembers(array $plan): Collection
    {
        $matched = collect($plan)->pluck('contact_id')->filter();

        return Membership::active()->with('contact')->whereNotIn('contact_id', $matched)->orderBy('number')->get();
    }

    /**
     * Carry out the plan. Rows with problems are skipped.
     *
     * @param  array{overwrite?: bool, assign_numbers?: bool, fields?: array<string, int|null>}  $options
     * @return array<string, int>
     */
    public function apply(array $plan, array $options = []): array
    {
        $summary = ['new' => 0, 'new_membership' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0];
        $fields = CustomField::whereIn('id', array_filter($options['fields'] ?? []))->get()->keyBy('id');

        foreach ($plan as $item) {
            $summary[$item['action']]++;
            if ($item['action'] === 'skip') {
                continue;
            }

            DB::transaction(function () use ($item, $options, $fields) {
                $contact = $item['contact_id'] ? Contact::find($item['contact_id']) : null;
                $contact = $this->saveContact($contact, $item);
                $this->saveCustomFields($contact, $item, $options['fields'] ?? [], $fields);
                $this->saveMembership($contact, $item, (bool) ($options['assign_numbers'] ?? false));
            });
        }

        return $summary;
    }

    /**
     * Custom fields an extra column can go to, preselected by matching label.
     *
     * @return array<string, ?int>
     */
    public function defaultFieldMapping(Collection $fields): array
    {
        $mapping = [];
        foreach (self::EXTRA as $key) {
            $title = $this->normalizeHeader(self::COLUMNS[$key]);
            $mapping[$key] = $fields->first(fn (CustomField $field) => $this->normalizeHeader($field->label) === $title)?->id;
        }

        return $mapping;
    }

    private function normalize(array $record): array
    {
        $item = $record + ['problems' => [], 'warnings' => []];
        $item['organization'] = $this->lower((string) $record['kind']) === 'tüzel';

        foreach (self::DATES as $key) {
            $item[$key] = $this->date($record[$key]);
            if ($record[$key] !== null && $item[$key] === null) {
                $item['warnings'][] = self::COLUMNS[$key].' okunamadı: '.$record[$key];
            }
        }

        [$item['first_name'], $item['last_name']] = $this->splitName($record['name']);
        $item['org_name'] = $record['org_name'] ? $this->properCase($record['org_name']) : null;

        $identity = $item['organization'] ? $record['org_number'] : $record['identity'];
        $item['identity'] = $identity !== null ? $this->digits($identity) : null;

        if ($item['organization']) {
            if ($item['org_name'] === null) {
                $item['problems'][] = 'Tüzel üyenin adı yok.';
            }
        } else {
            if ($item['first_name'] === null) {
                $item['problems'][] = 'Ad soyad yok.';
            }
            if ($item['identity'] === null) {
                $item['problems'][] = 'T.C. kimlik no yok.';
            } elseif (! self::validIdentityNumber($item['identity'])) {
                $item['problems'][] = 'T.C. kimlik no geçersiz: '.$record['identity'];
            }
        }

        $item['email'] = $record['email'] ? $this->lower($record['email']) : null;
        if ($item['email'] && ! filter_var($item['email'], FILTER_VALIDATE_EMAIL)) {
            $item['warnings'][] = 'E-posta geçersiz, aktarılmayacak: '.$record['email'];
            $item['email'] = null;
        }

        $phone = $record['phone'] ? $this->digits($record['phone']) : null;
        $phone = $phone ? preg_replace('/^(90|0)(?=\d{10}$)/', '', $phone) : null;
        $item['phone'] = $phone && strlen($phone) >= 10 && strlen($phone) <= 15 ? $phone : null;
        if ($record['phone'] && ! $item['phone']) {
            $item['warnings'][] = 'Telefon okunamadı: '.$record['phone'];
        }

        $item['gender'] = match ($this->lower((string) $record['gender'])) {
            'kadın', 'kadin', 'k' => 'female',
            'erkek', 'e' => 'male',
            default => null,
        };
        if ($record['gender'] !== null && $item['gender'] === null) {
            $item['warnings'][] = 'Cinsiyet anlaşılamadı: '.$record['gender'];
        }

        $item['membership_status'] = match ($this->lower((string) $record['status'])) {
            'aktif' => Membership::ACTIVE,
            'pasif' => Membership::LEFT,
            default => null,
        };
        if ($item['membership_status'] === null) {
            $item['problems'][] = 'Durum anlaşılamadı: '.($record['status'] ?? 'boş');
        }

        return $item;
    }

    private function findContact(array &$item): ?Contact
    {
        if ($item['identity']) {
            $matches = Contact::where('identity_number', $item['identity'])->get();
            if ($matches->count() > 1) {
                $item['problems'][] = 'Bu kimlik no ile birden fazla kişi var; önce kişileri birleştirin.';

                return null;
            }
            if ($matches->isNotEmpty()) {
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

            return $contact;
        }

        return null;
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>  field => [old, new]
     */
    private function contactChanges(Contact $contact, array $item, bool $overwrite): array
    {
        // Account holders keep their own details: only empty fields are
        // filled and the e-mail (the sign-in address) is never changed.
        $overwrite = $overwrite && ! $contact->user;
        $changes = [];

        foreach ($this->contactValues($item) as $field => $new) {
            $old = $field === 'birthday' ? $contact->birthday?->toDateString() : $contact->{$field};
            if ($new === null || (string) $old === $new) {
                continue;
            }
            if ($field === 'email' && $contact->user) {
                continue;
            }
            if ($old === null || $old === '' || $overwrite) {
                $changes[$field] = [$old === null ? null : (string) $old, $new];
            }
        }

        return $changes;
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    private function membershipChanges(?Membership $membership, array $item, bool $overwrite): array
    {
        $changes = [];
        $status = $membership?->status;
        if ($status !== $item['membership_status']) {
            $changes['status'] = [$status ? Membership::STATUSES[$status] : null, Membership::STATUSES[$item['membership_status']]];
        }

        $dates = ['joined_at' => $item['joined_at'], 'decision_date' => $item['decision_date']];
        if ($item['membership_status'] === Membership::LEFT) {
            $dates['left_at'] = $item['left_at'];
        }
        foreach ($dates as $field => $new) {
            $old = $membership?->{$field}?->toDateString();
            if ($new !== null && $old !== $new && ($old === null || $overwrite)) {
                $changes[$field] = [$old, $new];
            }
        }

        if ($membership && ! $membership->derbis_registered) {
            $changes['derbis_registered'] = ['Hayır', 'Evet'];
        }

        return $changes;
    }

    /**
     * @return array<string, ?string>
     */
    private function contactValues(array $item): array
    {
        return [
            'first_name' => $item['organization'] ? null : $item['first_name'],
            'last_name' => $item['organization'] ? null : $item['last_name'],
            'organization_name' => $item['organization'] ? $item['org_name'] : null,
            'identity_number' => $item['identity'],
            'email' => $item['email'],
            'phone' => $item['phone'],
            'birthday' => $item['organization'] ? null : $item['birthday'],
            'gender' => $item['organization'] ? null : $item['gender'],
        ];
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
            foreach (self::USER_FIELDS as $field => $column) {
                if (array_key_exists($field, $changes)) {
                    $user->{$column} = $changes[$field];
                }
            }
            $user->save();
            unset($changes['first_name'], $changes['last_name'], $changes['identity_number'], $changes['phone'], $changes['birthday']);
            $contact->refresh();
        }

        if ($changes) {
            $contact->update($changes);
        }

        return $contact;
    }

    /**
     * @param  array<string, int|null>  $mapping  extra column => custom field id
     */
    private function saveCustomFields(Contact $contact, array $item, array $mapping, Collection $fields): void
    {
        foreach (self::EXTRA as $key) {
            $field = $fields->get($mapping[$key] ?? null);
            $value = $item[$key];
            if (! $field || $value === null || ! in_array($field->applies_to, ['both', $contact->type], true)) {
                continue;
            }

            $value = match ($field->type) {
                'checkbox' => in_array($this->lower($value), ['evet', 'e', '1', 'var'], true) ? '1' : null,
                'select' => collect($field->options ?? [])->first(fn ($option) => $this->lower((string) $option) === $this->lower($value)),
                'date' => $this->date($value),
                default => $this->properCase($value),
            };
            if ($value === null) {
                continue;
            }

            $this->customFields->save($contact, [$field], [$field->key => $value]);
        }
    }

    private function saveMembership(Contact $contact, array $item, bool $assignNumber): void
    {
        $membership = Membership::where('contact_id', $contact->id)->first();
        $status = $item['membership_status'];
        $note = 'DERBİS listesinden aktarıldı';
        $joined = $item['joined_at'] ? Carbon::parse($item['joined_at']) : today();

        if (! $membership && $status === Membership::ACTIVE) {
            $membership = $this->memberships->start($contact, $assignNumber ? $this->memberships->nextNumber() : null, $joined, $note);
        } elseif (! $membership) {
            // A former member: the record keeps the register, the member
            // affiliation only as history.
            $left = $item['left_at'] ? Carbon::parse($item['left_at']) : today();
            $membership = Membership::create([
                'contact_id' => $contact->id, 'status' => Membership::LEFT,
                'joined_at' => $item['joined_at'], 'left_at' => $left,
            ]);
            $this->memberships->event($membership, 'joined', $joined, $note);
            $this->memberships->event($membership, 'left', $left, $item['left_reason']);
            $contact->affiliations()->create([
                'affiliation_type_id' => AffiliationType::findByKey(AffiliationType::MEMBER)->id,
                'started_at' => $joined, 'ended_at' => $left,
            ]);
        } elseif ($membership->status !== $status) {
            $date = $status === Membership::LEFT
                ? ($item['left_at'] ? Carbon::parse($item['left_at']) : today())
                : today();
            $this->memberships->changeStatus($membership, $status, $date, trim($note.'. '.($status === Membership::LEFT ? $item['left_reason'] : ''), '. ') ?: null);
            $membership->refresh();
        }

        $updates = ['derbis_registered' => true];
        foreach (['joined_at', 'decision_date', 'left_at'] as $field) {
            if (isset($item['membership_changes'][$field])) {
                $updates[$field] = $item['membership_changes'][$field][1];
            }
        }
        $membership->update($updates);
    }

    /**
     * T.C. kimlik no checksum (11 digits, the 10th and 11th are checks).
     */
    public static function validIdentityNumber(string $number): bool
    {
        if (! preg_match('/^[1-9]\d{10}$/', $number)) {
            return false;
        }

        $digits = array_map('intval', str_split($number));
        $odd = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8];
        $even = $digits[1] + $digits[3] + $digits[5] + $digits[7];

        return (($odd * 7 - $even) % 10 + 10) % 10 === $digits[9]
            && array_sum(array_slice($digits, 0, 10)) % 10 === $digits[10];
    }

    private function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value > 0 ? SpreadsheetReader::excelDate((float) $value) : null;
        }

        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'd.m.Y H:i', 'd.m.Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        return null;
    }

    /**
     * "AHMET CAN YILMAZ" → ["Ahmet Can", "Yılmaz"].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name): array
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (! $words) {
            return [null, null];
        }

        $last = count($words) > 1 ? array_pop($words) : null;

        return [$this->properCase(implode(' ', $words)), $last === null ? null : $this->properCase($last)];
    }

    /**
     * Title case with Turkish letters, only for text written in capitals.
     */
    private function properCase(string $text): string
    {
        if ($text !== $this->upper($text)) {
            return $text;
        }

        return preg_replace_callback('/(^|[\s\-\'’.(])(\p{L})(\p{L}*)/u', fn ($match) => $match[1].$this->upper($match[2]).$this->lower($match[3]), $this->lower($text));
    }

    private function lower(string $text): string
    {
        return mb_strtolower(strtr(trim($text), ['I' => 'ı', 'İ' => 'i']));
    }

    private function upper(string $text): string
    {
        return mb_strtoupper(strtr($text, ['i' => 'İ', 'ı' => 'I']));
    }

    private function digits(string $value): string
    {
        // A number stored as float in a spreadsheet may come as 5.15E+10.
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $value)) {
            $value = sprintf('%.0f', (float) $value);
        }
        $value = preg_replace('/\.0+$/', '', $value);

        return preg_replace('/\D/', '', $value);
    }

    private function normalizeHeader(string $title): string
    {
        return preg_replace('/[^\p{L}\p{N}]/u', '', $this->lower($title));
    }
}
