<?php

namespace App\Http\Controllers\SiteApi;

use App\Http\Controllers\Controller;
use App\Support\PhoneVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function store(Request $request, PhoneVerifier $verifier): JsonResponse
    {
        $data = $request->validate(['phone_number' => ['required', 'string', PhoneVerifier::PHONE_RULE]], [], ['phone_number' => 'Telefon']);

        return $this->answer($verifier->request($data['phone_number']));
    }

    public function verify(Request $request, PhoneVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'phone_number' => ['required', 'string', PhoneVerifier::PHONE_RULE],
            'code' => ['required', 'digits:6'],
        ], [], ['phone_number' => 'Telefon', 'code' => 'Doğrulama kodu']);

        return $this->answer($verifier->verify($data['phone_number'], $data['code']));
    }

    private function answer(?string $error): JsonResponse
    {
        return $error ? response()->json(['message' => $error], 422) : response()->json(['status' => true]);
    }
}
