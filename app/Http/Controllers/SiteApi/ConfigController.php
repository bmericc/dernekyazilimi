<?php

namespace App\Http\Controllers\SiteApi;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Support\Agreements;
use App\Support\Consents;
use App\Support\Organization;
use App\Support\SiteApi;
use Illuminate\Http\JsonResponse;

/**
 * What the web site needs to draw its forms: the association, the privacy
 * policy in force, the registration form and each module's own section.
 */
class ConfigController extends Controller
{
    public function __invoke(Organization $organization, Agreements $agreements, SiteApi $api): JsonResponse
    {
        $privacy = $agreements->current(Agreement::PRIVACY);

        return response()->json([
            'organization' => [
                'name' => $organization->name(),
                'short_name' => $organization->shortName(),
                'primary_color' => $organization->get('primary_color'),
                'portal_url' => url('/'),
            ],
            'privacy' => $privacy ? [
                'title' => $privacy->agreement->title,
                'version' => $privacy->version,
                'url' => route('agreements.show', Agreement::PRIVACY),
            ] : null,
            'registration' => [
                'label' => trans(config('app.register_label', 'auth.register')),
                'consents' => collect(Consents::CHANNELS)->only(['email', 'sms', 'whatsapp'])->all(),
                'login_url' => route('login'),
            ],
        ] + $api->sections());
    }
}
