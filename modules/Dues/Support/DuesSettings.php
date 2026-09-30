<?php

namespace Modules\Dues\Support;

use App\Support\Organization;

/**
 * Dues settings of the installation, kept with the organization settings
 * and edited at /admin/dues/settings. The amounts per year are the
 * membership fees (/admin/membership-fees).
 */
class DuesSettings
{
    public function __construct(private Organization $organization)
    {
    }

    /**
     * The public balance lookup page (/odeme).
     */
    public function publicPage(): bool
    {
        return $this->organization->get('dues_public_page', '0') === '1';
    }

    /**
     * Affiliation types whose holders pay no yearly dues (e.g. honorary members).
     *
     * @return int[]
     */
    public function exemptAffiliationTypes(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->organization->get('dues_exempt_affiliation_types')))));
    }

    /**
     * Text shown above the member's dues and the payment form.
     */
    public function intro(): ?string
    {
        return $this->organization->get('dues_intro');
    }

    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
