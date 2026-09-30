<?php

namespace Modules\FonzipImport\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Read-only client of the Fonzip API v2.
 *
 * Fonzip issues one access token at a time and refuses a new one (409) until
 * it expires, so the token is kept encrypted on the private disk (the cache
 * is cleared on deploys). Requests are spaced to stay under 60 a minute and
 * wait when Fonzip answers 429.
 */
class FonzipClient
{
    public const TOKEN_FILE = 'fonzip-import/token.json';

    private ?array $token = null;

    private float $lastRequest = 0;

    public function configured(): bool
    {
        return filled(config('fonzip-import.client_id')) && filled(config('fonzip-import.client_secret'));
    }

    /**
     * GET a JSON endpoint; query arrays are sent as repeated keys (list=a&list=b).
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, [], $body);
    }

    /**
     * The token's expiry, when one is held.
     */
    public function tokenExpiresAt(): ?int
    {
        return $this->storedToken()['expires_at'] ?? null;
    }

    private function send(string $method, string $path, array $query = [], ?array $body = null, bool $retried = false): array
    {
        $url = rtrim(config('fonzip-import.base_url'), '/').'/'.ltrim($path, '/');
        if ($query) {
            $url .= '?'.$this->query($query);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $request = $this->request()->withToken($this->token());
            $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body);

            if ($response->status() === 429) {
                $this->pause(10 * ($attempt + 1));

                continue;
            }
            if ($response->status() === 401 && ! $retried) {
                // The token was revoked (e.g. the key was renewed in Fonzip).
                $this->forgetToken();

                return $this->send($method, $path, $query, $body, true);
            }

            return $this->decode($response, "$method $path");
        }

        throw new RuntimeException("Fonzip istek sınırı aşıldı: $method $path");
    }

    private function token(): string
    {
        $this->token ??= $this->storedToken();
        if ($this->token && ($this->token['expires_at'] ?? 0) > time() + 60) {
            return $this->token['access_token'];
        }

        if (! $this->configured()) {
            throw new RuntimeException('Fonzip API anahtarı tanımlı değil (FONZIP_CLIENT_ID, FONZIP_CLIENT_SECRET).');
        }

        $response = $this->request()->asForm()->post(rtrim(config('fonzip-import.base_url'), '/').'/token', [
            'grant_type' => 'client_credentials',
            'client_id' => config('fonzip-import.client_id'),
            'client_secret' => config('fonzip-import.client_secret'),
        ]);

        if ($response->status() === 409) {
            throw new RuntimeException('Fonzip yeni erişim anahtarı vermedi: önceki anahtarın süresi dolmadı ve elde değil. Süresinin dolmasını bekleyin ya da Fonzip\'te API anahtarını yenileyip .env\'i güncelleyin.');
        }

        $data = $this->decode($response, 'POST token');
        if (empty($data['access_token'])) {
            throw new RuntimeException('Fonzip erişim anahtarı vermedi.');
        }

        $this->token = [
            'access_token' => $data['access_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
        ];
        Storage::disk('local')->put(self::TOKEN_FILE, Crypt::encryptString(json_encode($this->token)));

        return $this->token['access_token'];
    }

    private function storedToken(): ?array
    {
        if (! Storage::disk('local')->exists(self::TOKEN_FILE)) {
            return null;
        }

        try {
            return json_decode(Crypt::decryptString(Storage::disk('local')->get(self::TOKEN_FILE)), true) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function forgetToken(): void
    {
        $this->token = null;
        Storage::disk('local')->delete(self::TOKEN_FILE);
    }

    private function request(): PendingRequest
    {
        $this->throttle();

        return Http::acceptJson()
            ->withUserAgent(config('fonzip-import.user_agent'))
            ->timeout(60);
    }

    private function throttle(): void
    {
        $interval = config('fonzip-import.min_interval_ms') / 1000;
        $wait = $this->lastRequest + $interval - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastRequest = microtime(true);
    }

    private function pause(int $seconds): void
    {
        if (config('fonzip-import.min_interval_ms') > 0) {
            sleep($seconds);
        }
    }

    private function decode(Response $response, string $what): array
    {
        $data = $response->json();
        if (! $response->successful() || ! is_array($data)) {
            $detail = is_array($data) ? ($data['error_description'] ?? $data['error'] ?? $data['message'] ?? '') : '';
            throw new RuntimeException(trim("Fonzip hatası ($what): HTTP {$response->status()} ".(is_string($detail) ? $detail : json_encode($detail))));
        }

        return $data;
    }

    private function query(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            foreach ((array) $value as $item) {
                if ($item !== null) {
                    $parts[] = rawurlencode($key).'='.rawurlencode((string) $item);
                }
            }
        }

        return implode('&', $parts);
    }
}
