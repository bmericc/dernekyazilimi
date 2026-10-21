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

    /**
     * Card donations stopped, by management or by CardGuard after too many
     * refused cards; transfer stays open.
     */
    public function cardPaused(): bool
    {
        return $this->organization->get('donation_card_paused', '0') === '1';
    }

    /**
     * Card payments the whole site may start in an hour.
     */
    public function cardHourlyLimit(): int
    {
        return max(1, (int) $this->organization->get('donation_card_hourly_limit', '30'));
    }

    /**
     * Refused cards in an hour that stop card donations.
     */
    public function cardFailureLimit(): int
    {
        return max(1, (int) $this->organization->get('donation_card_failure_limit', '10'));
    }

    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
