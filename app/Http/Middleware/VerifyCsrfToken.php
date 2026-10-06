<?php

namespace App\Http\Middleware;

use App\Support\Embed;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Payment gateways post the payer back from their payment page.
        'payments/callback/*',
    ];

    /**
     * An embedded page takes its token from the page; a second XSRF-TOKEN
     * cookie would clash with the portal's own where both are sent.
     */
    public function shouldAddXsrfTokenCookie()
    {
        return parent::shouldAddXsrfTokenCookie() && ! app(Embed::class)->active();
    }
}
