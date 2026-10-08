<?php

namespace Modules\Correspondence\Support;

use App\Support\Organization;

/**
 * Correspondence settings, kept with the organization settings.
 */
class CorrespondenceSettings
{
    public const DEFAULT_FORMAT = '{yil}/{sira}';

    public function __construct(private Organization $organization)
    {
    }

    /**
     * Template of the document number: {kutuk} the association's registry
     * number, {yil} year, {sira} sequence number ({sira:4} zero-padded to
     * four digits), {kod} file plan code.
     */
    public function numberFormat(): string
    {
        return $this->organization->get('correspondence_number_format') ?: self::DEFAULT_FORMAT;
    }

    /** First number of a year that has no letters yet. */
    public function startNumber(): int
    {
        return max(1, (int) $this->organization->get('correspondence_start_number', '1'));
    }

    /** The association's registry number ("kütük no", e.g. 06-061-115) from the organization settings, usually the start of its document numbers. */
    public function registryNumber(): ?string
    {
        return $this->organization->get('registry_no');
    }

    /** Identifier of the organization in e-Yazışma packages: its MERSİS number from the organization settings. */
    public function identifier(): ?string
    {
        return $this->organization->get('mersis_no');
    }

    public function identifierScheme(): string
    {
        return $this->organization->get('correspondence_identifier_scheme') ?: 'MERSIS';
    }

    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
