<?php

namespace Modules\Dues\Support;

use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\ContactAffiliation;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Support\Payments\Payments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Modules\Dues\Mail\DuesStatement;
use Modules\Dues\Models\DuesCharge;
use Modules\Dues\Models\DuesExemption;
use Modules\Membership\Models\Membership;
use Modules\Membership\Models\MembershipApplication;
use Modules\Membership\Models\MembershipFee;
use Modules\Membership\Support\MembershipSettings;

/**
 * Dues: charging members (yearly in bulk, the entry fee on acceptance, or
 * one by one), their balance, and collecting through the core payments.
 */
class DuesService
{
    public const LINK_DAYS = 7;

    public function __construct(private Payments $payments, private DuesSettings $settings, private MembershipSettings $membershipSettings)
    {
    }

    public function account(Contact $contact): DuesAccount
    {
        return new DuesAccount(
            DuesCharge::where('contact_id', $contact->id)->inOrder()->get(),
            Payment::where('purpose', 'dues')->where('contact_id', $contact->id)->latest('id')->get(),
        );
    }

    /**
     * Adds "charged" and "paid" to a contact query (for lists and totals).
     */
    public function withBalance(Builder $query): Builder
    {
        return $query->addSelect([
            'dues_charged' => DuesCharge::selectRaw('coalesce(sum(amount), 0)')->whereColumn('contact_id', 'contacts.id')->whereNull('cancelled_at'),
            'dues_paid' => Payment::selectRaw('coalesce(sum(amount), 0)')->whereColumn('contact_id', 'contacts.id')->where('purpose', 'dues')->where('status', Payment::SUCCEEDED),
        ]);
    }

    /**
     * SQL of the balance, for filtering and ordering a withBalance() query.
     */
    public function balanceSql(): string
    {
        return "((select coalesce(sum(amount), 0) from dues_charges where dues_charges.contact_id = contacts.id and cancelled_at is null)
            - (select coalesce(sum(amount), 0) from payments where payments.contact_id = contacts.id and purpose = 'dues' and status = 'succeeded'))";
    }

    /**
     * Total owed by all members (credits not netted off).
     */
    public function totalOwed(): float
    {
        return (float) DB::query()->fromSub(Contact::query()->select('contacts.id')->selectRaw($this->balanceSql().' as balance'), 'balances')
            ->where('balance', '>', 0)->sum('balance');
    }

    /**
     * A charge; null when the member already has that year's dues / entry fee.
     */
    public function charge(Contact $contact, string $kind, int $year, float|string $amount, ?string $description = null): ?DuesCharge
    {
        $key = DuesCharge::periodKey($kind, $year);
        if ($key && DuesCharge::where('contact_id', $contact->id)->where('period_key', $key)->exists()) {
            return null;
        }

        return DuesCharge::create([
            'contact_id' => $contact->id,
            'kind' => $kind,
            'year' => $year,
            'amount' => $amount,
            'description' => $description,
            'period_key' => $key,
            'created_by' => Auth::id(),
        ]);
    }

    public function cancel(DuesCharge $charge, ?string $note): void
    {
        $charge->update(['cancelled_at' => now(), 'cancel_note' => $note]);
    }

    public function restore(DuesCharge $charge): void
    {
        $charge->update(['cancelled_at' => null, 'cancel_note' => null]);
    }

    /**
     * Why the member pays no yearly dues for the year, or null.
     */
    public function exemption(Contact $contact, int $year, ?Membership $membership = null): ?string
    {
        $membership ??= Membership::where('contact_id', $contact->id)->first();
        if ($membership?->status === Membership::SUSPENDED) {
            return 'Üyeliği askıda';
        }
        if ($reason = DuesExemption::where('contact_id', $contact->id)->covering($year)->value('reason')) {
            return 'Muaf: '.$reason;
        }
        $types = $this->settings->exemptAffiliationTypes();
        if ($types && ($type = $contact->affiliations()->active()->whereIn('affiliation_type_id', $types)->with('type')->first())) {
            return 'Muaf sıfat: '.$type->type->name;
        }

        return null;
    }

    /**
     * Who would get the year's dues: active and suspended members who had
     * joined by the end of the year, each with the reason when skipped.
     *
     * @return Collection<int, array{membership: Membership, amount: float, skip: ?string}>
     */
    public function preview(int $year): Collection
    {
        $fee = MembershipFee::forYear($year);
        $amount = (float) ($fee?->annual_fee ?? 0);

        $charged = DuesCharge::where('period_key', DuesCharge::periodKey(DuesCharge::ANNUAL, $year))->pluck('contact_id')->flip();
        $exempt = DuesExemption::covering($year)->pluck('reason', 'contact_id');
        $types = $this->settings->exemptAffiliationTypes();
        $byType = $types ? ContactAffiliation::active()->whereIn('affiliation_type_id', $types)->with('type')->get()->keyBy('contact_id') : collect();

        return Membership::with('contact')
            ->whereIn('status', [Membership::ACTIVE, Membership::SUSPENDED])
            ->where(fn ($query) => $query->whereNull('joined_at')->orWhereYear('joined_at', '<=', $year))
            ->whereHas('contact')
            ->get()
            ->sortBy(fn (Membership $membership) => [ctype_digit((string) $membership->number) ? (int) $membership->number : PHP_INT_MAX, $membership->contact->display_name])
            ->map(fn (Membership $membership) => [
                'membership' => $membership,
                'amount' => $amount,
                'skip' => match (true) {
                    isset($charged[$membership->contact_id]) => 'Aidatı yazılmış',
                    $membership->status === Membership::SUSPENDED => 'Üyeliği askıda',
                    isset($exempt[$membership->contact_id]) => 'Muaf: '.$exempt[$membership->contact_id],
                    isset($byType[$membership->contact_id]) => 'Muaf sıfat: '.$byType[$membership->contact_id]->type->name,
                    default => null,
                },
            ])
            ->values();
    }

    /**
     * Charge the year's dues to everyone preview() does not skip.
     */
    public function chargeYear(int $year): int
    {
        $fee = MembershipFee::forYear($year);
        abort_unless($fee && (float) $fee->annual_fee > 0, 422, 'Bu yıl için aidat tanımlı değil.');

        return DB::transaction(fn () => $this->preview($year)
            ->filter(fn (array $row) => $row['skip'] === null)
            ->filter(fn (array $row) => $this->charge($row['membership']->contact, DuesCharge::ANNUAL, $year, $fee->annual_fee) !== null)
            ->count());
    }

    /**
     * A new member: the entry fee (when the association charges one) and the
     * full yearly dues of the joining year.
     */
    public function chargeNewMember(MembershipApplication $application): void
    {
        $membership = $application->membership->fresh();
        $contact = $membership->contact;
        $year = ($membership->joined_at ?? today())->year;
        $fee = MembershipFee::forYear($year);
        if (! $fee) {
            return;
        }

        if ($this->membershipSettings->chargesEntryFee() && (float) $fee->entry_fee > 0) {
            $this->charge($contact, DuesCharge::ENTRY, $year, $fee->entry_fee);
        }
        if ((float) $fee->annual_fee > 0 && ! $this->exemption($contact, $year, $membership)) {
            $this->charge($contact, DuesCharge::ANNUAL, $year, $fee->annual_fee);
        }
    }

    /**
     * Card gateways and whether transfer is possible, for the payment form.
     *
     * @return array<string, string> "gateway:<id>" or "transfer" => label
     */
    public function methods(): array
    {
        $methods = [];
        $gateways = PaymentGateway::for('dues');
        foreach ($gateways as $gateway) {
            $methods['gateway:'.$gateway->id] = $gateways->count() > 1 ? 'Kredi / banka kartı ('.$gateway->name.')' : 'Kredi / banka kartı';
        }
        if (BankAccount::for('dues')->isNotEmpty()) {
            $methods['transfer'] = 'Havale / EFT';
        }

        return $methods;
    }

    /**
     * A pending payment the member makes from the site.
     */
    public function pay(Contact $contact, float|string $amount, string $method): Payment
    {
        return $this->payments->create('dues', Membership::where('contact_id', $contact->id)->first(), $amount,
            $method === 'transfer' ? Payment::TRANSFER : Payment::CARD, [], $contact);
    }

    /**
     * A collection management saw in the bank or received in cash.
     */
    public function record(Contact $contact, float|string $amount, string $method, Carbon $paidAt, ?BankAccount $account, ?string $note): Payment
    {
        return DB::transaction(function () use ($contact, $amount, $method, $paidAt, $account, $note) {
            $payment = $this->payments->create('dues', Membership::where('contact_id', $contact->id)->first(), $amount, $method, [], $contact, ['ip' => null]);
            $this->payments->confirm($payment, $paidAt, $account, $note);

            return $payment;
        });
    }

    /**
     * The contact's balance lookup (/odeme): TC identity number, email or
     * mobile number; only a single member match counts.
     */
    public function lookup(string $value): ?Contact
    {
        $value = trim($value);

        if (ctype_digit($value) && strlen($value) === 11 && $value[0] !== '0') {
            $query = Contact::where('identity_number', $value);
        } elseif (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $query = Contact::where('email', strtolower($value));
        } elseif (strlen($digits = preg_replace('/\D/', '', $value)) >= 10) {
            $query = Contact::where('phone', 'like', '%'.substr($digits, -10));
        } else {
            return null;
        }

        $contacts = $query->whereIn('id', Membership::select('contact_id'))->whereNotNull('email')->limit(2)->get();

        return $contacts->count() === 1 ? $contacts->first() : null;
    }

    /**
     * Signed link to the contact's dues page without signing in; it stops
     * working when the contact's email changes.
     */
    public function link(Contact $contact): string
    {
        return URL::temporarySignedRoute('dues.public.account', now()->addDays(self::LINK_DAYS), ['contact' => $contact->id, 'hash' => $this->hash($contact)]);
    }

    /**
     * Email the member's balance with the personal payment link.
     */
    public function sendStatement(Contact $contact, bool $reminder = false): bool
    {
        if (! filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $account = $this->account($contact);
        Mail::to($contact->email)->send(new DuesStatement(
            $contact,
            MembershipFee::format(max(0, $account->balance())),
            $account->open()->map(fn (DuesCharge $charge) => ['label' => $charge->label(), 'remaining' => MembershipFee::format($charge->remaining())])->all(),
            $this->link($contact),
            $reminder,
        ));

        return true;
    }

    public function hash(Contact $contact): string
    {
        return substr(sha1('dues|'.strtolower((string) $contact->email).'|'.$contact->id), 0, 16);
    }
}
