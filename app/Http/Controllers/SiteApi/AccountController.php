<?php

namespace App\Http\Controllers\SiteApi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AccountRegistration;
use App\Support\Embed;
use App\Support\PhoneVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Registration from the web site's own form. The password never passes
 * through the site: the person sets it from the link in the email.
 *
 * With "continue" the answer carries the address of a portal page to show in
 * a frame next (e.g. the membership application): a one-time sign-in link for
 * the new account, or the page itself (which asks to sign in) when the email
 * already has an account.
 */
class AccountController extends Controller
{
    public function store(Request $request, AccountRegistration $registration, PhoneVerifier $verifier, Embed $embed): JsonResponse
    {
        $continue = $request->input('continue');
        if ($continue !== null && (! is_string($continue) || ! $embed->has($continue))) {
            return response()->json(['message' => 'Bilinmeyen devam sayfası.', 'errors' => ['continue' => ['Bilinmeyen devam sayfası.']]], 422);
        }

        $email = strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);
        if ($continue && $email !== '' && User::where('email', $email)->exists()) {
            return response()->json(['created' => false, 'continue_url' => $embed->url($continue)]);
        }

        $data = $request->validate($registration->rules() + [
            'consents' => ['nullable', 'array'],
            'consents.*' => ['boolean'],
        ], ['email.unique' => 'Bu e-posta adresiyle açılmış bir hesap var; portala giriş yapabilirsiniz.'], [
            'name' => 'Ad', 'surname' => 'Soyad', 'email' => 'E-posta', 'phone_number' => 'Telefon', 'agreement' => 'Gizlilik politikası',
        ]);

        $phone = $verifier->recent($data['phone_number']);
        if (! $phone) {
            return response()->json(['message' => 'Telefon numaranızı doğrulamanız gerekiyor.', 'errors' => ['phone_number' => ['Telefon numaranızı doğrulamanız gerekiyor.']]], 422);
        }

        $user = $registration->register($data, $phone, $data['consents'] ?? []);

        return response()->json([
            'created' => true,
            'continue_url' => $continue ? $embed->signInLink($user, $continue) : null,
        ], 201);
    }
}
