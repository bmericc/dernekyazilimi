<?php

namespace Modules\Dues\Support;

use App\Models\Payment;
use Illuminate\Support\Collection;
use Modules\Dues\Models\DuesCharge;

/**
 * A member's dues: charges with the part paid of each (payments settle the
 * oldest charges first), collections and the balance.
 */
class DuesAccount
{
    public readonly float $charged;

    public readonly float $paid;

    /**
     * @param  Collection<int, DuesCharge>  $charges  in settling order, cancelled ones included
     * @param  Collection<int, Payment>  $payments  dues payments of the contact, newest first
     */
    public function __construct(public readonly Collection $charges, public readonly Collection $payments)
    {
        $this->charged = round((float) $charges->reject->isCancelled()->sum('amount'), 2);
        $this->paid = round((float) $payments->filter->isPaid()->sum('amount'), 2);

        $pool = $this->paid;
        foreach ($charges as $charge) {
            if ($charge->isCancelled()) {
                continue;
            }
            $charge->paid = round(min((float) $charge->amount, max(0, $pool)), 2);
            $pool -= $charge->paid;
        }
    }

    /**
     * Owed amount; negative when the member paid more (credit).
     */
    public function balance(): float
    {
        return round($this->charged - $this->paid, 2);
    }

    public function owes(): bool
    {
        return $this->balance() > 0;
    }

    /**
     * Charges not paid in full.
     *
     * @return Collection<int, DuesCharge>
     */
    public function open(): Collection
    {
        return $this->charges->filter(fn (DuesCharge $charge) => $charge->remaining() > 0)->values();
    }

    /**
     * Transfers the member said they would make, not confirmed yet.
     *
     * @return Collection<int, Payment>
     */
    public function pendingTransfers(): Collection
    {
        return $this->payments->filter(fn (Payment $payment) => $payment->isPending() && $payment->method === Payment::TRANSFER)->values();
    }
}
