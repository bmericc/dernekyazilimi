<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment was collected (card approved or transfer confirmed); the module
 * owning the payable reacts according to $payment->purpose.
 */
class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public Payment $payment)
    {
    }
}
