<?php

namespace Modules\Membership\Support;

use App\Models\Contact;
use App\Models\User;
use App\Support\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use App\Mail\TemplateMail;
use App\Support\Notifier;
use Modules\Membership\Events\ApplicationApproved;
use Modules\Membership\Events\ReferencesConfirmed;
use Modules\Membership\Models\MembershipFee;
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

        return $this->contactBlocker($user->syncContact());
    }

    /**
     * Why this person cannot have a new application, whoever enters it
     * (also management, with applications closed); null when they can.
     */
    public function contactBlocker(Contact $contact): ?string
    {
        $membership = Membership::where('contact_id', $contact->id)->first();
        if ($membership?->isActive()) {
            return 'Zaten üyesiniz.';
        }
        if ($membership?->status === Membership::SUSPENDED) {
            return 'Üyeliğiniz askıda; lütfen dernekle iletişime geçin.';
        }
        if ($this->openApplicationFor($contact)) {
            return 'Değerlendirmede olan bir başvurunuz var.';
        }

        return null;
    }

    public function openApplication(User $user): ?MembershipApplication
    {
        return $user->contact_id ? $this->openApplicationFor($user->syncContact()) : null;
    }

    public function openApplicationFor(Contact $contact): ?MembershipApplication
    {
        return MembershipApplication::where('contact_id', $contact->id)
            ->whereIn('status', MembershipApplication::OPEN)
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
     * A new application: from the applicant's own form, or entered by
     * management from a form that came in on paper or by email.
     *
     * @param  array<string, mixed>  $data  validated form answers
     * @param  Contact[]  $referees
     */
    public function submit(Contact $contact, ?User $user, array $data, array $referees, ?Carbon $submittedAt = null): MembershipApplication
    {
        $application = DB::transaction(function () use ($contact, $user, $data, $referees, $submittedAt) {
            $contact->update(['gender' => $data['gender'] ?? $contact->gender]);
            $membership = Membership::firstOrNew(['contact_id' => $contact->id]);
            $membership->fill(['status' => Membership::APPLICANT, 'applied_at' => ($submittedAt ?? today())->toDateString()])->save();

            $application = MembershipApplication::create([
                'membership_id' => $membership->id,
                'contact_id' => $contact->id,
                'user_id' => $user?->id ?? $contact->user?->id,
                'reference_no' => $this->nextReferenceNo(),
                'status' => MembershipApplication::REFERENCES_PENDING,
                'data' => $data,
                'submitted_at' => $submittedAt ?? now(),
            ]);

            $this->memberships->event($membership, 'applied', ($submittedAt ?? today())->copy()->startOfDay(), 'Başvuru '.$application->reference_no);

            if ($email = $this->applicantEmail($application)) {
                $this->mail($email, new TemplateMail('membership.application-received', $this->applicantValues($application)));
            }

            foreach (array_values($referees) as $index => $referee) {
                $this->invite($application, $referee, $index + 1);
            }

            if ($this->settings->referencesRequired() === 0) {
                $this->confirmReferences($application);
            }

            return $application;
        });

        return $application->fresh();
    }

    /**
     * The references are in: on to the entry fee when a module collects
     * one, else to the board.
     */
    public function confirmReferences(MembershipApplication $application): void
    {
        $event = new ReferencesConfirmed($application);
        event($event);

        if ($event->awaitingEntryFee) {
            $application->update(['status' => MembershipApplication::FEE_PENDING]);
            $this->memberships->event($application->membership, 'note', today(), 'Referanslar tamamlandı; giriş aidatı bekleniyor');

            return;
        }

        $application->update(['status' => MembershipApplication::READY]);
        $this->notifyManagement($application);
    }

    /**
     * The entry fee is paid (or waived): the application goes to the board.
     */
    public function entryFeeSettled(MembershipApplication $application): void
    {
        if ($application->status !== MembershipApplication::FEE_PENDING) {
            return;
        }

        $application->update(['status' => MembershipApplication::READY]);
        $this->memberships->event($application->membership, 'note', today(), 'Giriş aidatı ödendi; karar bekliyor');
        $this->notifyManagement($application);
    }

    public function applicantEmail(MembershipApplication $application): ?string
    {
        return $application->user?->email ?? $application->answer('email') ?? $application->contact?->email;
    }

    /**
     * Placeholder values about the applicant, for the emails sent to them.
     *
     * @return array<string, string|null>
     */
    public function applicantValues(MembershipApplication $application): array
    {
        $fee = $this->settings->fees($application->submitted_at->year);

        return [
            'name' => $application->contact?->display_name,
            'application_no' => $application->reference_no,
            'references_required' => (string) $this->settings->referencesRequired(),
            'entry_fee' => $this->settings->chargesEntryFee() ? MembershipFee::format($fee?->entry_fee) : null,
            'annual_fee' => MembershipFee::format($fee?->annual_fee),
            'application_url' => $application->user_id ? route('membership.application') : null,
            'steps' => new HtmlString('<ol>'.collect($this->steps($fee))->map(fn (string $step) => '<li>'.e($step).'</li>')->implode('').'</ol>'),
        ];
    }

    /**
     * What happens after applying, for this installation: references and
     * the entry fee only when the association asks for them.
     *
     * @return string[]
     */
    private function steps(?MembershipFee $fee): array
    {
        $steps = [];
        if (($required = $this->settings->referencesRequired()) > 0) {
            $steps[] = "Referans gösterdiğiniz {$required} üyeye onay bağlantısı gönderildi; hepsi onayladığında size haber veririz.";
        }
        // The dues module collects the entry fee; without it the step does not happen.
        if ($this->settings->chargesEntryFee() && (float) $fee?->entry_fee > 0 && app(\App\Modules\ModuleManager::class)->enabled('dues')) {
            $steps[] = 'Giriş aidatını ('.MembershipFee::format($fee->entry_fee).') ödemeniz için size kişisel bir ödeme bağlantısı gönderilir.';
        }
        $steps[] = 'Başvurunuz ilk yönetim kurulu toplantısında değerlendirilir.';
        $steps[] = 'Kabul edilirseniz üye numaranız ve yıllık aidat bilgisi e-postayla size bildirilir.';

        return $steps;
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
                $email = $this->applicantEmail($application);
                $email && $this->mail($email, new ReferenceDeclined($reference));

                return;
            }

            $accepted = $application->references()->where('status', MembershipReference::ACCEPTED)->count();
            if ($application->status === MembershipApplication::REFERENCES_PENDING && $accepted >= $this->settings->referencesRequired()) {
                $this->confirmReferences($application);
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
            $membership->update(['number' => $number ?: $membership->number, 'decision_date' => $decisionDate, 'decision_number' => $decisionNumber]);
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
        app(Notifier::class)->send($approved ? 'membership.approved' : 'membership.rejected', ApplicationDecided::values($application),
            $this->applicantEmail($application), $application->answer('phone') ?? $application->contact?->phone, $application->contact,
            mailable: new ApplicationDecided($application, $approved));
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
