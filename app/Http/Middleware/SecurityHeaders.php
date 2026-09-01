<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three headers the shop should have been sending all along.
 *
 * Deliberately not a Content-Security-Policy. A useful one has to be written
 * against every page and every inline script this application actually has,
 * and a guessed policy either breaks the site or is loose enough to be
 * decoration. Same for Strict-Transport-Security, which a browser remembers
 * for as long as it is told to and cannot be taken back by removing it.
 *
 * These three are safe to send everywhere and need no per-page thought.
 *
 * Note what this does *not* cover: everything under /storage is a symlink into
 * public/, served by Apache without PHP ever running, so no middleware sees
 * it. Those files get the same headers from public/.htaccess instead.
 */
class SecurityHeaders
{
    private const HEADERS = [
        // Honour the declared Content-Type instead of guessing from content.
        // A browser that sniffs can decide a file is HTML on its own.
        'X-Content-Type-Options' => 'nosniff',

        // No framing by another site: clickjacking, and the admin is full of
        // one-click actions worth stealing.
        'X-Frame-Options' => 'SAMEORIGIN',

        // Send the full URL only to ourselves. An order or invoice URL should
        // not travel to whatever a page happens to link out to.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            // Never overwrite: a route that has deliberately said otherwise —
            // an embeddable widget, say — keeps its own answer.
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
