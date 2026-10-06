<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Support\AccountRegistration;
use App\Support\PhoneVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showRegistrationForm()
    {
        $inIframe = request()->boolean('in-iframe');
        $response = response()->view('auth.register', compact('inIframe'));

        if ($inIframe) {
            $response->headers->set('Content-Security-Policy', app(\App\Support\Organization::class)->frameAncestorsPolicy());
        }

        return $response;
    }

    public function register(Request $request, AccountRegistration $registration, PhoneVerifier $verifier)
    {
        $data = $request->validate($registration->rules() + [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $phoneVerification = $verifier->recent($data['phone_number']);
        if (! $phoneVerification) {
            return back()->withErrors(['phone_number' => 'Telefon numaranızı doğrulamanız gerekiyor.'])->withInput();
        }

        $user = $registration->register($data, $phoneVerification, (array) $request->input('consents', []));
        Auth::login($user);

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
