<?php

namespace Modules\Membership\Events;

use Modules\Membership\Models\MembershipApplication;

/**
 * The application has the references it needs (or needs none). A listener
 * that asks the applicant for something first, e.g. the entry fee, sets
 * $awaitingEntryFee; the application then waits for entryFeeSettled()
 * instead of going to the board.
 */
class ReferencesConfirmed
{
    public bool $awaitingEntryFee = false;

    public function __construct(public MembershipApplication $application)
    {
    }
}
