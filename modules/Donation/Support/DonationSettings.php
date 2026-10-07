<?php

namespace Modules\Donation\Support;

use App\Support\Organization;

/**
 * Donation page settings, kept with the organization settings.
 */
class DonationSettings
{
    public function __construct(private Organization $organization)
    {
    }

    public function open(): bool
    {
        return $this->organization->get('donation_open', '1') === '1';
    }

    public function intro(): ?string
    {
        return $this->organization->get('donation_intro');
    }

    public function thanks(): ?string
    {
        return $this->organization->get('donation_thanks');
    }

    /**
     * Suggested amounts shown as buttons.
     *
     * @return int[]
     */
    public function amounts(): array
    {
        return collect(explode(',', (string) $this->organization->get('donation_amounts', '100,250,500,1000')))
            ->map(fn ($value) => (int) trim($value))->filter(fn ($value) => $value > 0)->values()->all();
    }

    /**
     * Whether the donor may only pick one of the amounts; card providers
     * can require this.
     */
    public function fixedOnly(): bool
    {
        return $this->organization->get('donation_fixed_only', '0') === '1' && $this->amounts();
    }

    public function minimum(): int
    {
        return max(1, (int) $this->organization->get('donation_minimum', '10'));
    }

    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
