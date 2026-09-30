<?php

namespace Modules\Membership\Support;

use App\Models\Contact;
use App\Models\User;
use App\Support\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Membership\Events\ApplicationApproved;
use Modules\Membership\Mail\ApplicationDecided;
use Modules\Membership\Mail\ApplicationReady;
use Modules\Membership\Mail\ReferenceDeclined;
use Modules\Membership\Mail\ReferenceInvitation;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipReference;
use Throwable;

/**
 * Membership applications: submission, member references and their
 * confirmation by email link, and the board decision.
 */
class ApplicationService
{
    public function __construct(
        private MembershipSettings $settings,
        private MembershipService $memberships,
        private Organization $organization,
    ) {
    }

    /**
     * Why the user cannot apply now, or null when they can.
     */
    public function blocker(User $user): ?string
    {
        if (! $this->settings->applicationsOpen()) {
            return 'Üyelik başvuruları şu anda kapalı.';
        }

        $membership = Membership::where('contact_id', $user->contact_id)->first();
        if ($membership?->isActive()) {
            return 'Zaten üyesiniz.';
        }
        if ($membership?->status === Membership::SUSPENDED) {
            return 'Üyeliğiniz askıda; lütfen dernekle iletişime geçin.';
        }
        if ($this->openApplication($user)) {
            return 'Değerlendirmede olan bir başvurunuz var.';
        }

        return null;
    }

    public function openApplication(User $user): ?MembershipApplication
    {
        return MembershipApplication::where('contact_id', $user->contact_id)
            ->whereIn('status', [MembershipApplication::REFERENCES_PENDING, MembershipApplication::READY])
            ->latest('id')->first();
    }

    /**
     * The active member with this number and surname (as the applicant knows
     * them); the member list itself is never shown to applicants.
     */
    public function findReferee(string $number, string $surname): ?Contact
    {
        $membership = Membership::active()->where('number', trim($number))->with('contact')->first();
        $normalize = fn (string $value) => mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], trim($value)), 'UTF-8');

        return $membership && $normalize((string) $membership->contact?->last_name) === $normalize($surname) ? $membership->contact : null;
    }

    /**
     * Why this member cannot be a reference now, or null when they can.
     */
    public function refereeBlocker(Contact $referee, Contact $applicant): ?string
    {
        if ($referee->id === $applicant->id) {
            return 'Kendinizi referans gösteremezsiniz.';
        }

        $counting = fn () => MembershipReference::where('referee_contact_id', $referee->id)
            ->where(fn ($query) => $query->where('status', MembershipReference::ACCEPTED)
                ->orWhere(fn ($query) => $query->where('status', MembershipReference::PENDING)->where('expires_at', '>', now())));

        if (($limit = $this->settings->referenceLimitTotal()) !== null && $counting()->count() >= $limit) {
            return 'Bu üye referans olabileceği sayıya ulaştı.';
        }

        // Year = calendar year, not the last 12 months.
        if (($limit = $this->settings->referenceLimitYearly()) !== null
            && $counting()->whereBetween('invited_at', [now()->startOfYear(), now()->endOfYear()])->count() >= $limit) {
            return 'Bu üye bu yıl için referans olabileceği sayıya ulaştı.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data  validated form answers
     * @param  Contact[]  $referees
     */
    public function submit(User $user, array $data, array $referees): MembershipApplication
    {
        return DB::transaction(function () use ($user, $data, $referees) {
            $contact = $user->syncContact();
            $contact->update(['gender' => $data['gender'] ?? $contact->gender]);
            $membership = Membership::firstOrNew(['contact_id' => $contact->id]);
            $membership->fill(['status' => Membership::APPLICANT, 'applied_at' => today()])->save();

            $application = MembershipApplication::create([
                'membership_id' => $membership->id,
                'contact_id' => $contact->id,
                'user_id' => $user->id,
                'reference_no' => $this->nextReferenceNo(),
                'status' => $this->settings->referencesRequired() > 0 ? MembershipApplication::REFERENCES_PENDING : MembershipApplication::READY,
                'data' => $data,
                'submitted_at' => now(),
            ]);

            $this->memberships->event($membership, 'applied', today(), 'Başvuru '.$application->reference_no);

            foreach (array_values($referees) as $index => $referee) {
                $this->invite($application, $referee, $index + 1);
            }

            if ($application->status === MembershipApplication::READY) {
                $this->notifyManagement($application);
            }

            return $application;
        });
    }

    public function invite(MembershipApplication $application, Contact $referee, int $position): MembershipReference
    {
        $token = Str::random(40);
        $reference = MembershipReference::create([
            'application_id' => $application->id,
            'applicant_contact_id' => $application->contact_id,
            'referee_contact_id' => $referee->id,
            'position' => $position,
            'token_hash' => hash('sha256', $token),
            'invited_at' => now(),
            'expires_at' => now()->addDays($this->settings->referenceDays()),
        ]);

        if ($referee->email) {
            $this->mail($referee->email, new ReferenceInvitation($reference, route('membership.references.show', [$reference, $token])));
        }

        return $reference;
    }

    /**
     * Send the referee a new link (the old one stops working).
     */
    public function reinvite(MembershipReference $reference): void
    {
        $reference->forceFill(['status' => MembershipReference::WITHDRAWN])->save();
        $this->invite($reference->application, $reference->referee, $reference->position);
    }

    public function tokenMatches(MembershipReference $reference, string $token): bool
    {
        return hash_equals($reference->token_hash, hash('sha256', $token));
    }

    public function respond(MembershipReference $reference, bool $accepted, ?string $note, ?string $ip): void
    {
        DB::transaction(function () use ($reference, $accepted, $note, $ip) {
            $reference->forceFill([
                'status' => $accepted ? MembershipReference::ACCEPTED : MembershipReference::DECLINED,
                'responded_at' => now(),
                'response_ip' => $ip,
                'response_note' => $note,
            ])->save();

            $application = $reference->application;

            if (! $accepted) {
                $email = $application->user?->email ?? $application->contact->email;
                $email && $this->mail($email, new ReferenceDeclined($reference));

                return;
            }

            $accepted = $application->references()->where('status', MembershipReference::ACCEPTED)->count();
            if ($application->status === MembershipApplication::REFERENCES_PENDING && $accepted >= $this->settings->referencesRequired()) {
                $application->update(['status' => MembershipApplication::READY]);
                $this->notifyManagement($application);
            }
        });
    }

    /**
     * The applicant names another member in place of a declined or
     * unanswered reference.
     */
    public function replaceReference(MembershipReference $old, Contact $referee): MembershipReference
    {
        return DB::transaction(function () use ($old, $referee) {
            $old->forceFill(['status' => MembershipReference::WITHDRAWN])->save();

            return $this->invite($old->application, $referee, $old->position);
        });
    }

    public function approve(MembershipApplication $application, ?string $number, Carbon $joinedAt, ?Carbon $decisionDate, ?string $decisionNumber): void
    {
        DB::transaction(function () use ($application, $number, $joinedAt, $decisionDate, $decisionNumber) {
            $membership = $application->membership;
            $membership->update(['number' => $number ?: ($membership->number ?: $this->memberships->nextNumber()), 'decision_date' => $decisionDate, 'decision_number' => $decisionNumber]);
            $this->memberships->changeStatus($membership, Membership::ACTIVE, $joinedAt, $decisionNumber ? 'Karar '.$decisionNumber : null);

            $application->forceFill(['status' => MembershipApplication::APPROVED, 'decided_at' => now(), 'decided_by' => Auth::id()])->save();
            $this->closeReferences($application);

            event(new ApplicationApproved($application));
        });

        $this->notifyApplicant($application->fresh(), true);
    }

    public function reject(MembershipApplication $application, string $note): void
    {
        DB::transaction(function () use ($application, $note) {
            $this->memberships->changeStatus($application->membership, Membership::REJECTED, today(), $note);
            $application->forceFill(['status' => MembershipApplication::REJECTED, 'decided_at' => now(), 'decided_by' => Auth::id(), 'decision_note' => $note])->save();
            $this->closeReferences($application);
        });

        $this->notifyApplicant($application->fresh(), false);
    }

    public function withdraw(MembershipApplication $application): void
    {
        DB::transaction(function () use ($application) {
            $application->update(['status' => MembershipApplication::WITHDRAWN]);
            $this->closeReferences($application);
            if ($application->membership->status === Membership::APPLICANT) {
                $application->membership->update(['status' => Membership::LEFT]);
                $this->memberships->event($application->membership, 'note', today(), 'Başvuru geri çekildi');
            }
        });
    }

    private function closeReferences(MembershipApplication $application): void
    {
        $application->references()->where('status', MembershipReference::PENDING)->update(['status' => MembershipReference::WITHDRAWN]);
    }

    private function nextReferenceNo(): string
    {
        $year = now()->year;
        $last = MembershipApplication::where('reference_no', 'like', $year.'-%')->orderByDesc('reference_no')->value('reference_no');

        return $year.'-'.str_pad((string) ($last ? ((int) substr($last, 5)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }

    private function notifyManagement(MembershipApplication $application): void
    {
        if ($email = $this->organization->notificationEmail()) {
            $this->mail($email, new ApplicationReady($application));
        }
    }

    private function notifyApplicant(MembershipApplication $application, bool $approved): void
    {
        if ($email = $application->user?->email ?? $application->contact->email) {
            $this->mail($email, new ApplicationDecided($application, $approved));
        }
    }

    private function mail(string $to, $mailable): void
    {
        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
