<?php

namespace Modules\Membership\Support;

use App\Models\AffiliationType;
use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Membership\Models\Membership;

/**
 * Membership changes. Each change is recorded in the membership history and
 * keeps the core "member" affiliation in step, so roles, ID cards and
 * dashboards that read affiliations follow the membership.
 */
class MembershipService
{
    /**
     * The next free numeric member number.
     */
    public function nextNumber(): string
    {
        $max = Membership::whereNotNull('number')->pluck('number')
            ->filter(fn ($number) => ctype_digit((string) $number))
            ->map(fn ($number) => (int) $number)
            ->max();

        return (string) (($max ?? 0) + 1);
    }

    /**
     * Members without a number and the number each would get: by joining
     * date, then board decision date (unknown dates last), from the next
     * free number on.
     *
     * @return Collection<int, array{membership: Membership, number: string}>
     */
    public function numberingPlan(bool $includeLeft = false): Collection
    {
        $statuses = $includeLeft ? [Membership::ACTIVE, Membership::SUSPENDED, Membership::LEFT] : [Membership::ACTIVE, Membership::SUSPENDED];
        $next = (int) $this->nextNumber();

        return Membership::with('contact')
            ->whereIn('status', $statuses)
            ->where(fn ($query) => $query->whereNull('number')->orWhere('number', ''))
            ->orderByRaw('joined_at is null')->orderBy('joined_at')
            ->orderByRaw('decision_date is null')->orderBy('decision_date')
            ->orderBy('id')
            ->get()
            ->map(function (Membership $membership) use (&$next) {
                return ['membership' => $membership, 'number' => (string) $next++];
            });
    }

    /**
     * Give the members of numberingPlan() their numbers; each change goes
     * into the membership history.
     */
    public function assignNumbers(bool $includeLeft = false): int
    {
        return DB::transaction(function () use ($includeLeft) {
            $plan = $this->numberingPlan($includeLeft);
            $plan->each(fn (array $row) => $this->changeNumber($row['membership'], $row['number']));

            return $plan->count();
        });
    }

    /**
     * Make the contact a member (new membership, or a former member again).
     */
    public function start(Contact $contact, ?string $number, Carbon $date, ?string $note = null): Membership
    {
        return DB::transaction(function () use ($contact, $number, $date, $note) {
            $membership = Membership::firstOrNew(['contact_id' => $contact->id]);
            $again = $membership->exists;

            $membership->fill([
                'number' => $number ?: $membership->number,
                'status' => Membership::ACTIVE,
                'joined_at' => $again && $membership->joined_at ? $membership->joined_at : $date,
                'left_at' => null,
            ])->save();

            $this->event($membership, $again ? 'reactivated' : 'joined', $date, $note);
            $this->syncAffiliation($membership, $date);

            return $membership;
        });
    }

    /**
     * Move an existing membership to active, suspended or left.
     */
    public function changeStatus(Membership $membership, string $status, Carbon $date, ?string $note = null): void
    {
        if ($membership->status === $status) {
            return;
        }

        DB::transaction(function () use ($membership, $status, $date, $note) {
            $wasApplicant = $membership->status === Membership::APPLICANT;

            $membership->fill([
                'status' => $status,
                'left_at' => $status === Membership::LEFT ? $date : null,
                'joined_at' => $status === Membership::ACTIVE && ! $membership->joined_at ? $date : $membership->joined_at,
            ])->save();

            $type = match ($status) {
                Membership::ACTIVE => $wasApplicant ? 'joined' : 'reactivated',
                Membership::SUSPENDED => 'suspended',
                Membership::LEFT => 'left',
                Membership::REJECTED => 'rejected',
                default => 'note',
            };
            $this->event($membership, $type, $date, $note);
            $this->syncAffiliation($membership, $date);
        });
    }

    public function changeNumber(Membership $membership, ?string $number): void
    {
        if ($membership->number === $number) {
            return;
        }

        $old = $membership->number;
        $membership->update(['number' => $number]);
        $this->event($membership, 'number_changed', today(), ($old ?: '—').' → '.($number ?: '—'));
    }

    public function event(Membership $membership, string $type, Carbon $date, ?string $note = null, ?int $extraMonths = null): void
    {
        $membership->events()->create([
            'type' => $type,
            'occurred_on' => $date,
            'note' => $note,
            'extra_months' => $extraMonths,
            'user_id' => Auth::id(),
        ]);
    }

    /**
     * Active membership ⇔ an active "member" affiliation.
     */
    private function syncAffiliation(Membership $membership, Carbon $date): void
    {
        $contact = $membership->contact;
        $active = $contact->affiliations()->active()->ofType(AffiliationType::MEMBER)->get();

        if ($membership->isActive() && $active->isEmpty()) {
            $contact->affiliations()->create([
                'affiliation_type_id' => AffiliationType::findByKey(AffiliationType::MEMBER)->id,
                'started_at' => $date,
            ]);
        } elseif (! $membership->isActive()) {
            $active->each(fn ($affiliation) => $affiliation->update(['ended_at' => $date]));
        }
    }
}
