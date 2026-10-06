<?php

namespace App\Http\Controllers;

use App\Support\PhoneVerifier;
use Illuminate\Http\Request;

class MobileVerificationController extends Controller
{

    public function postPhoneNumberVerificationRequest(Request $request, PhoneVerifier $verifier) {

        $data = $request->validate([
            'phone_number' => ['required', 'string', PhoneVerifier::PHONE_RULE],
        ]);

        if ($error = $verifier->request($data['phone_number'])) {
            return $this->output('json', ['status' => false, 'message' => $error]);
        }

        return $this->output("json", ['status' => true]);
    }

    public function postPhoneNumberVerification(Request $request, PhoneVerifier $verifier) {

        $data = $request->validate([
            'phone_number' => ['required', 'string', PhoneVerifier::PHONE_RULE],
            'validation' => ['required', 'digits:6'],
        ]);

        if ($error = $verifier->verify($data['phone_number'], $data['validation'])) {
            return $this->output("json", ["status" => false, "message" => $error]);
        }

        $this->set_log("other", $data['phone_number']. " telefon numarası doğrulandı");

        return $this->output("json", ['status' => true]);
    }

}
