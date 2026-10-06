<?php

namespace App\Support;

use App\Models\PhoneVerification;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\MobileVerification;
use App\Notifications\PhoneVerificationRecipient;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Phone number verification by a six digit code sent as SMS. Both methods
 * return null on success, otherwise the message for the person.
 */
class PhoneVerifier
{
    public const PHONE_RULE = 'regex:/^\+?[0-9]{10,15}$/';

    /** Minutes a verified number stays usable for opening an account. */
    public const VALID_MINUTES = 30;

    public function request(string $phone): ?string
    {
        $rateLimitKey = 'phone-verification-request:'.$phone;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            return 'Çok fazla kod istediniz, lütfen bir dakika sonra tekrar deneyin.';
        }

        $code = (string) random_int(100000, 999999);
        RateLimiter::hit($rateLimitKey, 60);

        // SMS carries the code; WhatsApp is an extra copy. A WhatsApp failure after
        // the SMS went out must not discard a code the person has already received.
        $recipient = new PhoneVerificationRecipient($phone, $code);
        try {
            Notification::send($recipient, new MobileVerification(SmsChannel::class));
        } catch (Throwable $e) {
            report($e);

            return 'Doğrulama kodu gönderilemedi, lütfen daha sonra tekrar deneyin.';
        }

        foreach (array_diff(MobileVerification::channels(), [SmsChannel::class]) as $channel) {
            try {
                Notification::send($recipient, new MobileVerification($channel));
            } catch (Throwable $e) {
                report($e);
            }
        }

        $verification = $this->find($phone) ?? new PhoneVerification();
        $verification->value = $phone;
        $verification->value_type = 'phone_number';
        $verification->verification_code = Hash::make($code);
        $verification->verification_code_expires_at = now()->addMinutes(10);
        $verification->verification_attempts = 0;
        $verification->verified = false;
        $verification->verified_at = null;
        $verification->status = 1;
        $verification->save();

        return null;
    }

    public function verify(string $phone, string $code): ?string
    {
        $verification = $this->find($phone);
        if (! $verification) {
            return 'Bu numaraya gönderilmiş bir doğrulama kodu yok. Lütfen yeniden kod isteyin.';
        }
        if (! $verification->verification_code
            || ! $verification->verification_code_expires_at
            || $verification->verification_code_expires_at->isPast()
            || $verification->verification_attempts >= 5) {
            return 'Doğrulama kodunun süresi doldu. Lütfen yeniden kod isteyin.';
        }

        if (! Hash::check($code, $verification->verification_code)) {
            $verification->increment('verification_attempts');

            return 'Doğrulama kodu hatalı.';
        }

        $verification->verified = true;
        $verification->verified_at = now();
        $verification->verification_code = null;
        $verification->verification_code_expires_at = null;
        $verification->verification_attempts = 0;
        $verification->save();

        return null;
    }

    /**
     * The recent verification of the number, needed to open an account with it.
     */
    public function recent(string $phone): ?PhoneVerification
    {
        return PhoneVerification::query()
            ->where('value_type', 'phone_number')
            ->where('value', $phone)
            ->where('verified', true)
            ->where('verified_at', '>=', now()->subMinutes(self::VALID_MINUTES))
            ->first();
    }

    private function find(string $phone): ?PhoneVerification
    {
        return PhoneVerification::where('value_type', 'phone_number')->where('value', $phone)->first();
    }
}
