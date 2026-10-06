<?php

namespace Modules\Donation\Support;

use App\Models\Agreement;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Support\Agreements;
use App\Support\Payments\Payments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Donation\Models\Donation;

class DonationService
{
    /** Names of the donation form's fields in validation messages. */
    public const ATTRIBUTES = ['amount' => 'Tutar', 'cause_id' => 'Bağış amacı', 'name' => 'Ad soyad', 'email' => 'E-posta', 'phone' => 'Telefon', 'message' => 'Mesaj', 'method' => 'Ödeme yöntemi'];

    public function __construct(private Payments $payments, private DonationSettings $settings, private Agreements $agreements)
    {
    }

    /**
     * Rules of the donation form.
     *
     * @param  array<string, string>  $methods  see methods()
     */
    public function rules(array $methods): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:'.$this->settings->minimum(), 'max:1000000', 'decimal:0,2'],
            'cause_id' => ['nullable', Rule::exists('donation_causes', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+() .-]+$/'],
            'hide_name' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:1000'],
            'method' => ['required', Rule::in(array_keys($methods))],
            'agreement' => $this->agreements->rules(Agreement::PRIVACY),
        ];
    }

    /**
     * Card gateways and whether transfer is possible, for the donation form.
     *
     * @return array<string, string> method key => label ("gateway:<id>" or "transfer")
     */
    public function methods(): array
    {
        $methods = [];
        $gateways = PaymentGateway::for('donation');
        foreach ($gateways as $gateway) {
            $methods['gateway:'.$gateway->id] = $gateways->count() > 1 ? 'Kredi / banka kartı ('.$gateway->name.')' : 'Kredi / banka kartı';
        }
        if (BankAccount::for('donation')->isNotEmpty()) {
            $methods['transfer'] = 'Havale / EFT';
        }

        return $methods;
    }

    /**
     * Record the donation and its pending payment. The donor is the signed-in
     * person, or a contact found or created by email (without an account).
     *
     * @param  array{name: string, email: string, phone?: ?string, amount: string, cause_id?: ?int, hide_name?: bool, message?: ?string, method: string}  $data
     */
    public function submit(array $data, ?User $user): Payment
    {
        return DB::transaction(function () use ($data, $user) {
            $contact = $user ? $user->syncContact() : $this->guestContact($data);

            $donation = Donation::create([
                'cause_id' => $data['cause_id'] ?? null,
                'contact_id' => $contact->id,
                'amount' => $data['amount'],
                'donor_name' => $data['name'],
                'donor_email' => $data['email'],
                'donor_phone' => $data['phone'] ?? null,
                'hide_name' => ! empty($data['hide_name']),
                'message' => $data['message'] ?? null,
            ]);

            return $this->payments->create('donation', $donation, $data['amount'], $data['method'] === 'transfer' ? Payment::TRANSFER : Payment::CARD,
                ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null], $contact);
        });
    }

    /**
     * A donation received outside the site (transfer seen in the bank, cash).
     */
    public function record(array $data, ?Contact $contact): Payment
    {
        return DB::transaction(function () use ($data, $contact) {
            $donation = Donation::create([
                'cause_id' => $data['cause_id'] ?? null,
                'contact_id' => $contact?->id,
                'amount' => $data['amount'],
                'donor_name' => $data['name'],
                'donor_email' => $data['email'] ?? null,
                'donor_phone' => $data['phone'] ?? null,
                'hide_name' => ! empty($data['hide_name']),
                'message' => $data['note'] ?? null,
                'source' => 'manual',
                'created_by' => Auth::id(),
            ]);

            $payment = $this->payments->create('donation', $donation, $data['amount'], $data['method'],
                ['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null], $contact, ['ip' => null]);
            $this->payments->confirm($payment, Carbon::parse($data['paid_at']), isset($data['bank_account_id']) ? BankAccount::find($data['bank_account_id']) : null, $data['note'] ?? null);

            return $payment;
        });
    }

    private function guestContact(array $data): Contact
    {
        $existing = Contact::where('email', $data['email'])->whereDoesntHave('user')->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $parts = preg_split('/\s+/', trim($data['name'])) ?: [];
        $last = count($parts) > 1 ? array_pop($parts) : null;

        return Contact::create(['first_name' => implode(' ', $parts), 'last_name' => $last, 'email' => $data['email'], 'phone' => $data['phone'] ?? null]);
    }
}
