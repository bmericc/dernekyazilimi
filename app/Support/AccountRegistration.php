<?php

namespace App\Support;

use App\Mail\AccountCreated;
use App\Mail\Welcome;
use App\Models\Agreement;
use App\Models\PhoneVerification;
use App\Models\ProcessLogs;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Opens an account for a person who registers: on the portal's own form with
 * a password, or from the association's web site without one (the person
 * sets it from the link in the email).
 */
class AccountRegistration
{
    public function __construct(private Agreements $agreements, private Consents $consents)
    {
    }

    /**
     * Rules of the fields every registration form asks.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'min:3'],
            'surname' => ['required', 'string', 'max:255', 'min:2'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone_number' => ['required', 'string', PhoneVerifier::PHONE_RULE],
            'agreement' => $this->agreements->rules(Agreement::PRIVACY),
        ];
    }

    /**
     * @param  array{name: string, surname: string, email: string, phone_number: string, password?: string}  $data
     * @param  array<string, bool>  $consents  channel => granted; the form asks email, sms and whatsapp
     */
    public function register(array $data, PhoneVerification $phone, array $consents): User
    {
        $password = $data['password'] ?? null;

        $user = User::create([
            'name' => $this->capitalize($data['name']),
            'surname' => $this->capitalize($data['surname']),
            'national_id' => null,
            'email' => strtolower($data['email']),
            'phone_number' => $data['phone_number'],
            'password' => Hash::make($password ?? Str::random(40)),
            'agreement_at' => now(),
            'phone_number_verified_at' => $phone->verified_at,
        ]);

        if ($password !== null) {
            Mail::to($user->email)->send(new Welcome($user));
            $this->log($user->email.' adresine hoş geldiniz e-postası gönderildi');
        } else {
            $link = route('password.reset', ['token' => Password::broker()->createToken($user), 'email' => $user->email]);
            Mail::to($user->email)->send(new AccountCreated($user->name.' '.$user->surname, $link));
            $this->log($user->email.' adresine parola belirleme e-postası gönderildi');
        }

        $this->agreements->accept($user, 'register', Agreement::PRIVACY);
        // Unticked means declined.
        $this->consents->set($user->contact, collect(['email', 'sms', 'whatsapp'])->mapWithKeys(fn ($channel) => [$channel => ! empty($consents[$channel])])->all(), 'register');
        event(new Registered($user));

        return $user;
    }

    private function capitalize(string $text): string
    {
        $words = [];
        foreach (explode(' ', strtolower(str_replace(['Ö', 'Ç', 'Ş', 'Ğ', 'Ü', 'I', 'İ'], ['ö', 'ç', 'ş', 'ğ', 'ü', 'ı', 'i'], $text))) as $word) {
            $first = mb_substr($word, 0, 1, 'UTF-8');
            $words[] = strtoupper(str_replace(['ö', 'ç', 'ş', 'ğ', 'ü', 'ı', 'i'], ['Ö', 'Ç', 'Ş', 'Ğ', 'Ü', 'I', 'İ'], $first)).mb_substr($word, 1, 100, 'UTF-8');
        }

        return trim(implode(' ', $words));
    }

    private function log(string $text): void
    {
        $log = new ProcessLogs();
        $log->process_by = Auth::id();
        $log->process_type = 'other';
        $log->process = $text;
        $log->request_ip = request()?->ip();
        $log->save();
    }
}
