<?php

namespace App\Http\Controllers;

use App\Support\Embed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Entry of a framed page for a person whose account the web site has just
 * opened: the signed, one-time link signs them in inside the frame.
 */
class EmbedController extends Controller
{
    public function enter(Request $request, Embed $embed, string $page): RedirectResponse
    {
        abort_unless($embed->has($page), 404);

        $user = $embed->redeem((string) $request->query('nonce'));
        if ($user) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        // A used or unknown link falls back to the page's own sign-in.
        return redirect()->to($embed->url($page));
    }
}
