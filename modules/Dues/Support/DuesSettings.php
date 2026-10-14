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

    /**
     * Day of the month (1–28) the members owing at least autoReminderMinimum()
     * get a reminder by themselves; null: off.
     */
    public function autoReminderDay(): ?int
    {
        $day = (int) $this->organization->get('dues_auto_reminder_day', '0');

        return $day >= 1 && $day <= 28 ? $day : null;
    }

    public function autoReminderMinimum(): float
    {
        return max(0.01, (float) $this->organization->get('dues_auto_reminder_min', '1'));
    }

    /**
     * Days after the entry fee was asked for that an unpaid applicant gets
     * the last reminder; null: off.
     */
    public function entryFeeReminderDays(): ?int
    {
        $days = (int) $this->organization->get('dues_entry_fee_reminder_days', '0');

        return $days > 0 ? $days : null;
    }

    /**
     * Days the last reminder gives to pay (its {{deadline}}).
     */
    public function entryFeeDeadlineDays(): int
    {
        return max(1, (int) $this->organization->get('dues_entry_fee_deadline_days', '14'));
    }

    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
