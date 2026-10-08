<?php

namespace App\Http\Controllers\SiteApi;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Support\Agreements;
use App\Support\Consents;
use App\Support\Organization;
use App\Support\Payments\Payments;
use App\Support\SiteApi;
use Illuminate\Http\JsonResponse;

/**
 * What the web site needs to draw its forms: the association, the privacy
 * policy and the payment terms in force, the registration form and each
 * module's own section.
 */
class ConfigController extends Controller
{
    public function __invoke(Organization $organization, Agreements $agreements, Payments $payments, SiteApi $api): JsonResponse
    {
        return response()->json([
            'organization' => [
                'name' => $organization->name(),
                'short_name' => $organization->shortName(),
                'primary_color' => $organization->get('primary_color'),
                'portal_url' => url('/'),
            ],
            'privacy' => $this->agreement($agreements, Agreement::PRIVACY),
            'payment' => [
                'terms' => $this->agreement($agreements, Agreement::PAYMENT_TERMS),
                'logos' => $payments->logos(),
            ],
            'registration' => [
                'label' => trans(config('app.register_label', 'auth.register')),
                'consents' => collect(Consents::CHANNELS)->only(['email', 'sms', 'whatsapp'])->all(),
                'login_url' => route('login'),
            ],
        ] + $api->sections());
    }

    /**
     * @return array{key: string, title: string, version: int, url: string}|null
     */
    private function agreement(Agreements $agreements, string $key): ?array
    {
        if (! $version = $agreements->current($key)) {
            return null;
        }

        return [
            'key' => $key,
            'title' => $version->agreement->title,
            'version' => $version->version,
            'url' => route('agreements.show', $key),
        ];
    }
}
