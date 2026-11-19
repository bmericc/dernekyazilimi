<?php

namespace Modules\MailForwarding\Support;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Laminas\XmlRpc\Client as XmlRpcClient;
use Psr\Http\Client\ClientInterface;

class PostfixAdminClient
{
    /**
     * Opens an XML-RPC session on PostfixAdmin and logs in.
     *
     * PostfixAdmin keeps the login in a PHP session, so every call of one
     * session must go through the same HTTP client, which keeps its cookies.
     */
    public function connect(): ?XmlRpcClient
    {
        $factory = new HttpFactory;
        $client = new XmlRpcClient(
            'https://'.config('services.postfixadmin.server').'/xmlrpc.php',
            $this->httpClient(),
            $factory,
            $factory,
        );
        // Every argument is a string, so the extra system.methodSignature
        // request before each call is not needed.
        $client->setSkipSystemLookup(true);

        $loggedIn = $client->getProxy('login')->login(
            config('services.postfixadmin.username'),
            config('services.postfixadmin.password'),
        );

        return $loggedIn ? $client : null;
    }

    public function updateAlias(string $aliasEmail, string $targetEmail): bool
    {
        try {
            $client = $this->connect();

            if (! $client) {
                return false;
            }

            return (bool) $client->getProxy('alias')->update($aliasEmail, $targetEmail);
        } catch (\Throwable $exception) {
            Log::error('PostfixAdmin alias update failed.', [
                'alias' => $aliasEmail,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * A new client with its own cookie jar for each session.
     */
    protected function httpClient(): ClientInterface
    {
        return new GuzzleClient(['cookies' => true, 'timeout' => 30]);
    }
}
