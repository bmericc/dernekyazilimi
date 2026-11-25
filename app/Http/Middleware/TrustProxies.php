<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * config/trustedproxy.php, with "cloudflare" expanded to Cloudflare's ranges.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = parent::proxies() ?: config('trustedproxy.proxies');
        if (! is_string($configured) || trim($configured) === '') {
            return $configured ?: null;
        }
        if (in_array(trim($configured), ['*', '**'], true)) {
            return trim($configured);
        }

        return collect(explode(',', $configured))
            ->map(fn (string $proxy) => trim($proxy))
            ->filter()
            ->flatMap(fn (string $proxy) => strtolower($proxy) === 'cloudflare' ? config('trustedproxy.cloudflare', []) : [$proxy])
            ->unique()
            ->values()
            ->all();
    }
}
