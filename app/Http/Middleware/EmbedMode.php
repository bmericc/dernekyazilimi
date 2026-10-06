<?php

namespace App\Http\Middleware;

use App\Support\Embed;
use App\Support\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches an embedded request (see App\Support\Embed) to the frame's own
 * partitioned session cookie and lets only the allowed sites frame the answer.
 * Runs before the session starts.
 */
class EmbedMode
{
    public function __construct(private Embed $embed, private Organization $organization)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Embed::requested($request)) {
            return $next($request);
        }

        // The admin panel is never shown in a frame.
        abort_if($request->is('admin', 'admin/*'), 403);

        $this->embed->activate();
        config([
            'session.cookie' => config('session.cookie').'_embed',
            'session.same_site' => 'none',
            'session.secure' => true,
            'session.partitioned' => true,
        ]);

        $response = $next($request);
        $response->headers->set('Content-Security-Policy', $this->organization->frameAncestorsPolicy());

        return $response;
    }
}
