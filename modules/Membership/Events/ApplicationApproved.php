<?php

namespace Modules\Membership\Events;

use Modules\Membership\Models\MembershipApplication;

/**
 * An application was accepted and its applicant became a member (modules
 * that require membership react, e.g. dues charge the entry fee).
 */
class ApplicationApproved
{
    public function __construct(public MembershipApplication $application)
    {
    }
}
