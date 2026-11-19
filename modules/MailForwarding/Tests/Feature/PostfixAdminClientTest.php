<?php

namespace Modules\MailForwarding\Tests\Feature;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Modules\MailForwarding\Support\PostfixAdminClient;
use Psr\Http\Client\ClientInterface;
use Tests\TestCase;

class PostfixAdminClientTest extends TestCase
{
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.postfixadmin.server' => 'mail.example.test',
            'services.postfixadmin.username' => 'admin@example.test',
            'services.postfixadmin.password' => 'secret',
        ]);
    }

    private function boolResponse(bool $value, array $headers = []): Response
    {
        $body = '<?xml version="1.0"?><methodResponse><params><param><value><boolean>'
            .(int) $value.'</boolean></value></param></params></methodResponse>';

        return new Response(200, ['Content-Type' => 'text/xml'] + $headers, $body);
    }

    private function client(Response ...$responses): PostfixAdminClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $http = new GuzzleClient(['handler' => $stack, 'cookies' => true]);

        return new class($http) extends PostfixAdminClient {
            public function __construct(private ClientInterface $http) {}

            protected function httpClient(): ClientInterface
            {
                return $this->http;
            }
        };
    }

    public function test_alias_update_keeps_the_login_session(): void
    {
        $client = $this->client(
            $this->boolResponse(true, ['Set-Cookie' => 'PHPSESSID=abc123; path=/']),
            $this->boolResponse(true),
        );

        $this->assertTrue($client->updateAlias('ada.lovelace@penguen.org.tr', 'ada@example.test'));
        $this->assertCount(2, $this->history);

        $login = $this->history[0]['request'];
        $this->assertSame('https://mail.example.test/xmlrpc.php', (string) $login->getUri());
        $this->assertStringContainsString('<methodName>login.login</methodName>', (string) $login->getBody());

        $update = $this->history[1]['request'];
        $this->assertStringContainsString('<methodName>alias.update</methodName>', (string) $update->getBody());
        $this->assertStringContainsString('ada.lovelace@penguen.org.tr', (string) $update->getBody());
        $this->assertSame('PHPSESSID=abc123', $update->getHeaderLine('Cookie'));
    }

    public function test_failed_login_does_not_update(): void
    {
        $client = $this->client($this->boolResponse(false));

        $this->assertFalse($client->updateAlias('ada.lovelace@penguen.org.tr', 'ada@example.test'));
        $this->assertCount(1, $this->history);
    }

    public function test_server_error_returns_false(): void
    {
        $client = $this->client(new Response(500));

        $this->assertFalse($client->updateAlias('ada.lovelace@penguen.org.tr', 'ada@example.test'));
    }
}
