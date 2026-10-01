<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

use App\Notifications\Channels\SmsChannel;
use App\Notifications\MobileVerification;
use App\Notifications\PhoneVerificationRecipient;
use App\Models\PhoneVerification;

class MobileVerificationController extends Controller
{

    public function postPhoneNumberVerificationRequest(Request $request) {

        $validator = $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        $phone_number = $request->get("phone_number");
        $rateLimitKey = 'phone-verification-request:'.$phone_number;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            return $this->output('json', ['status' => false, 'message' => 'Çok fazla kod istediniz, lütfen bir dakika sonra tekrar deneyin.']);
        }

        $verification_code = (string) random_int(100000, 999999);
        RateLimiter::hit($rateLimitKey, 60);

        $contactPermission = PhoneVerification::where("value_type", "phone_number")->where("value", $phone_number)->first();
        if($contactPermission==null) $contactPermission = new PhoneVerification();

        // SMS carries the code; WhatsApp is an extra copy. A WhatsApp failure after
        // the SMS went out must not discard a code the person has already received.
        $recipient = new PhoneVerificationRecipient($phone_number, $verification_code);
        try {
            Notification::send($recipient, new MobileVerification(SmsChannel::class));
        } catch (\Throwable $e) {
            report($e);

            return $this->output('json', ['status' => false, 'message' => 'Doğrulama kodu gönderilemedi, lütfen daha sonra tekrar deneyin.']);
        }

        foreach (array_diff(MobileVerification::channels(), [SmsChannel::class]) as $channel) {
            try {
                Notification::send($recipient, new MobileVerification($channel));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $contactPermission->value = $phone_number;
        $contactPermission->value_type = "phone_number";
        $contactPermission->verification_code = Hash::make($verification_code);
        $contactPermission->verification_code_expires_at = now()->addMinutes(10);
        $contactPermission->verification_attempts = 0;
        $contactPermission->verified = false;
        $contactPermission->verified_at = null;
        $contactPermission->status = 1;
        $contactPermission->save();

        return $this->output("json", ['status' => true]);
    }

    public function postPhoneNumberVerification(Request $request) {

        $validator = $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'validation' => ['required', 'digits:6'],
        ]);

        $phone_number = $request->get("phone_number");
        $validation = $request->get("validation");

        $contactPermission = PhoneVerification::where("value_type", "phone_number")->where("value", $phone_number)->first();
        if($contactPermission==null) {
            return $this->output("json", ["status" => false, "message" => "Bu numaraya gönderilmiş bir doğrulama kodu yok. Lütfen yeniden kod isteyin."]);
        }
        if (! $contactPermission->verification_code
            || ! $contactPermission->verification_code_expires_at
            || $contactPermission->verification_code_expires_at->isPast()
            || $contactPermission->verification_attempts >= 5) {
            return $this->output('json', ['status' => false, 'message' => 'Doğrulama kodunun süresi doldu. Lütfen yeniden kod isteyin.']);
        }

        if (! Hash::check($validation, $contactPermission->verification_code)) {
            $contactPermission->increment('verification_attempts');
            return $this->output("json", ["status" => false, "message" => "Doğrulama kodu hatalı."]);
        }

        $contactPermission->verified = true;
        $contactPermission->verified_at = now();
        $contactPermission->verification_code = null;
        $contactPermission->verification_code_expires_at = null;
        $contactPermission->verification_attempts = 0;
        $contactPermission->save();

        $this->set_log("other", $phone_number. " telefon numarası doğrulandı");

        return $this->output("json", ['status' => true]);
    }

}
