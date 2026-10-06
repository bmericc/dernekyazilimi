<?php

namespace App\Http\Middleware;

use App\Support\SiteApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/site/*: the API key ("X-Api-Key") and an allowed site address
 * ("X-Site-Url"). The site forwards its visitor's address and browser, which
 * then stand for the request's own in records and rate limits.
 */
class AuthenticateSite
{
    public function __construct(private SiteApi $api)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->api->validKey($request->header('X-Api-Key'))) {
            return response()->json(['message' => 'API anahtarı geçersiz.'], 401);
        }

        if (! $this->api->allowsSite($request->header('X-Site-Url'))) {
            return response()->json(['message' => 'Bu site adresi portalın kurum ayarlarındaki gömülebilecek siteler arasında değil.'], 403);
        }

        $ip = $request->header('X-Client-Ip');
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $ip);
            $request->headers->remove('X-Forwarded-For');
            $request->headers->remove('Forwarded');
        }
        if ($agent = $request->header('X-Client-User-Agent')) {
            $request->headers->set('User-Agent', $agent);
        }

        return $next($request);
    }
}
