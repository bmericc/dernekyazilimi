<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn () => request()->ip());
    }

    private function ipFrom(string $remote, string $forwardedFor): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->get('/_test/ip')
            ->getContent();
    }

    public function test_forwarded_header_is_ignored_without_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('162.158.1.1', $this->ipFrom('162.158.1.1', '203.0.113.5'));
    }

    public function test_cloudflare_keyword_trusts_cloudflare_ranges(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare']);

        $this->assertSame('203.0.113.5', $this->ipFrom('162.158.1.1', '203.0.113.5'));
        $this->assertSame('203.0.113.5', $this->ipFrom('2606:4700::1', '203.0.113.5'));
    }

    public function test_client_cannot_spoof_through_untrusted_address(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare']);

        $this->assertSame('198.51.100.7', $this->ipFrom('198.51.100.7', '203.0.113.5'));
    }

    public function test_spoofed_leftmost_entry_is_not_used(): void
    {
        config(['trustedproxy.proxies' => 'cloudflare,127.0.0.1']);

        // Visitor sent "1.2.3.4" itself; Cloudflare appended the real address.
        $this->assertSame('203.0.113.5', $this->ipFrom('127.0.0.1', '1.2.3.4, 203.0.113.5, 162.158.1.1'));
    }
}
