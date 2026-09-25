<?php

namespace Modules\Membership\Support;

use App\Support\Organization;

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
     * Petition text printed at the top of the form ("... Başkanlığına").
     */
    public function letter(): string
    {
        return $this->organization->get('membership_application_letter')
            ?? '<p>'.e($this->organization->name()).' Başkanlığına,</p><p>Derneğin amaç ve yükümlülüklerini benimsediğim için üye olmak istiyorum. Gerekli bilgileri doğru olarak doldurdum.</p><p>Gereğinin yapılmasını dilerim.</p>';
    }

    /**
     * Instructions shown after applying and printed on the form.
     */
    public function instructions(): ?string
    {
        return $this->organization->get('membership_application_instructions');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
