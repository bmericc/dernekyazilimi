<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Pages shown inside an iframe on the association's web site (the sites
 * listed in the organization settings). An embedded request is told apart by
 * "?in-iframe=1", the browser's "Sec-Fetch-Dest: iframe" header or, for
 * script requests of an embedded page, the "X-Embed" header.
 *
 * Embedded pages keep their own session in a partitioned cookie: it works
 * where third-party cookies are blocked, and a person signed in to the portal
 * itself is not signed in inside a frame on another site.
 */
class Embed
{
    /** Minutes a sign-in link made for a new account stays valid. */
    public const LINK_MINUTES = 10;

    private bool $active = false;

    /** @var array<string, string> page key => route name */
    private array $pages = [];

    public static function requested(Request $request): bool
    {
        return $request->boolean('in-iframe')
            || $request->headers->get('Sec-Fetch-Dest') === 'iframe'
            || $request->headers->get('X-Embed') === '1';
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function active(): bool
    {
        return $this->active;
    }

    /**
     * A page the web site may continue on in a frame, e.g. the membership
     * application after the account is opened from the site's own form.
     */
    public function page(string $key, string $route): void
    {
        $this->pages[$key] = $route;
    }

    public function has(string $key): bool
    {
        return isset($this->pages[$key]);
    }

    public function url(string $key): string
    {
        return route($this->pages[$key], ['in-iframe' => 1]);
    }

    /**
     * One-time link that signs the user in inside the frame and opens the page.
     */
    public function signInLink(User $user, string $key): string
    {
        $nonce = Str::random(40);
        Cache::put($this->cacheKey($nonce), $user->id, now()->addMinutes(self::LINK_MINUTES));

        return URL::temporarySignedRoute('embed.enter', now()->addMinutes(self::LINK_MINUTES), ['page' => $key, 'nonce' => $nonce, 'in-iframe' => 1]);
    }

    /**
     * The user a sign-in link was made for; the link works once.
     */
    public function redeem(string $nonce): ?User
    {
        $id = Cache::pull($this->cacheKey($nonce));

        return $id ? User::find($id) : null;
    }

    private function cacheKey(string $nonce): string
    {
        return 'embed-sign-in:'.hash('sha256', $nonce);
    }
}
