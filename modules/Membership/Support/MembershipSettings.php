<?php

namespace Modules\Membership\Support;

use App\Support\Organization;
use Modules\Membership\Models\MembershipFee;

/**
 * Membership application settings of the installation, kept with the
 * organization settings and edited at /admin/memberships/settings.
 */
class MembershipSettings
{
    public function __construct(private Organization $organization)
    {
    }

    public function applicationsOpen(): bool
    {
        return $this->organization->get('membership_applications_open') === '1';
    }

    /**
     * Members an applicant must name as references; 0 turns references off.
     */
    public function referencesRequired(): int
    {
        return max(0, min(5, (int) $this->organization->get('membership_references_required', '0')));
    }

    /** At most this many accepted references per member in total; null = no limit. */
    public function referenceLimitTotal(): ?int
    {
        $value = $this->organization->get('membership_reference_limit_total');

        return $value === null ? null : (int) $value;
    }

    /** At most this many references per member per calendar year; null = no limit. */
    public function referenceLimitYearly(): ?int
    {
        $value = $this->organization->get('membership_reference_limit_yearly');

        return $value === null ? null : (int) $value;
    }

    public function referenceDays(): int
    {
        return max(1, (int) $this->organization->get('membership_reference_days', '14'));
    }

    public function askPhotoChoice(): bool
    {
        return $this->organization->get('membership_photo_choice', '1') === '1';
    }

    /**
     * Whether the association charges an entry fee on joining (set per
     * year with the dues); off, the entry fee is neither asked nor shown.
     */
    public function chargesEntryFee(): bool
    {
        return $this->organization->get('membership_entry_fee', '0') === '1';
    }

    /**
     * Ask foreign applicants for their foreign identity and residence
     * documents (section 2 of the form).
     */
    public function askForeignFields(): bool
    {
        return $this->organization->get('membership_foreign_fields', '1') === '1';
    }

    /**
     * Petition text printed at the top of the form ("... Başkanlığına"),
     * placeholders filled for the given year.
     */
    public function letter(?int $year = null): string
    {
        return $this->fill($this->organization->get('membership_application_letter') ?? self::DEFAULT_LETTER, $year);
    }

    /**
     * Instructions shown after applying and printed on the form.
     */
    public function instructions(?int $year = null): ?string
    {
        $text = $this->organization->get('membership_application_instructions');

        return $text === null ? null : $this->fill($text, $year);
    }

    /**
     * The letter and instructions as edited, placeholders unfilled.
     */
    public function rawLetter(): string
    {
        return $this->organization->get('membership_application_letter') ?? self::DEFAULT_LETTER;
    }

    public function rawInstructions(): ?string
    {
        return $this->organization->get('membership_application_instructions');
    }

    public const DEFAULT_LETTER = '<p>{dernek} Başkanlığına,</p><p>Derneğin amaç ve yükümlülüklerini benimsediğim için üye olmak istiyorum. Gerekli bilgileri doğru olarak doldurdum.</p><p>Gereğinin yapılmasını dilerim.</p>';

    /**
     * Placeholders usable in the letter and the instructions.
     *
     * @return array<string, string> placeholder => description
     */
    public static function placeholders(): array
    {
        return [
            '{dernek}' => 'Derneğin adı',
            '{kisa_ad}' => 'Derneğin kısa adı',
            '{web}' => 'Web sitesi',
            '{yil}' => 'Başvuru yılı',
            '{giris_aidati}' => 'O yılın giriş aidatı (giriş aidatı alınıyorsa)',
            '{yillik_aidat}' => 'O yılın yıllık üyelik aidatı (Aidatlar sayfasından)',
        ];
    }

    /**
     * The year's fees, or the latest earlier year's.
     */
    public function fees(?int $year = null): ?MembershipFee
    {
        return MembershipFee::forYear($year ?? now()->year);
    }

    private function fill(string $text, ?int $year): string
    {
        $year ??= now()->year;
        $fee = $this->fees($year);

        return strtr($text, array_map('e', [
            '{dernek}' => (string) $this->organization->name(),
            '{kisa_ad}' => (string) $this->organization->shortName(),
            '{web}' => (string) $this->organization->get('website_url'),
            '{yil}' => (string) $year,
            '{giris_aidati}' => ($this->chargesEntryFee() ? MembershipFee::format($fee?->entry_fee) : null) ?? '—',
            '{yillik_aidat}' => MembershipFee::format($fee?->annual_fee) ?? '—',
        ]));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
