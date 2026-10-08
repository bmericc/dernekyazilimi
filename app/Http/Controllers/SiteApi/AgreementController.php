<?php

namespace App\Http\Controllers\SiteApi;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use Illuminate\Http\JsonResponse;

/**
 * Text of an agreement in force, for the web site to show on a page of its
 * own: the text is kept in one place, the portal.
 */
class AgreementController extends Controller
{
    public function __invoke(string $key): JsonResponse
    {
        $agreement = Agreement::where('key', $key)->firstOrFail();
        $version = $agreement->currentVersion ?? abort(404);

        return response()->json([
            'key' => $agreement->key,
            'title' => $agreement->title,
            'version' => $version->version,
            'published_at' => $version->published_at->toIso8601String(),
            'content' => $version->content,
            'url' => route('agreements.show', $agreement->key),
        ]);
    }
}
