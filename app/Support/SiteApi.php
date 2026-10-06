<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Str;

/**
 * The API the association's web site (e.g. the WordPress plugin) talks to,
 * server to server: /api/site/*. A request carries the API key made in the
 * organization settings and the address of the site, which must be one of the
 * sites allowed to embed pages.
 */
class SiteApi
{
    private const KEY_SETTING = 'site_api_key_hash';

    /** @var array<string, Closure> */
    private array $sections = [];

    public function __construct(private Organization $organization)
    {
    }

    public function hasKey(): bool
    {
        return $this->organization->get(self::KEY_SETTING) !== null;
    }

    /**
     * Make a new key, replacing the old one; only its hash is kept.
     */
    public function generateKey(): string
    {
        $key = 'dy_'.Str::random(48);
        $this->organization->save([self::KEY_SETTING => hash('sha256', $key)]);

        return $key;
    }

    public function revokeKey(): void
    {
        $this->organization->save([self::KEY_SETTING => null]);
    }

    public function validKey(?string $key): bool
    {
        $hash = $this->organization->get(self::KEY_SETTING);

        return $hash !== null && is_string($key) && $key !== '' && hash_equals($hash, hash('sha256', $key));
    }

    /**
     * Whether the site address belongs to a site allowed to embed pages.
     */
    public function allowsSite(?string $url): bool
    {
        $origin = self::origin($url);

        return $origin !== null && in_array($origin, array_filter(array_map([self::class, 'origin'], $this->organization->frameAncestors())), true);
    }

    /**
     * A part of GET /api/site/config; modules describe their forms here.
     */
    public function describe(string $key, Closure $section): void
    {
        $this->sections[$key] = $section;
    }

    /**
     * @return array<string, mixed>
     */
    public function sections(): array
    {
        return array_map(fn (Closure $section) => $section(), $this->sections);
    }

    private static function origin(?string $url): ?string
    {
        $parts = parse_url(strtolower(trim((string) $url)));
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return null;
        }

        return 'https://'.$parts['host'].(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '');
    }
}
