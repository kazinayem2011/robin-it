<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response carries.
 *
 * Still deliberately not a Content-Security-Policy. A useful one has to be
 * written against every page and every inline script this application actually
 * has, and a guessed policy either breaks the site or is loose enough to be
 * decoration.
 *
 * Strict-Transport-Security was held back for the same reason once — a browser
 * remembers it for as long as it is told to, and removing the header later
 * does not take it back. That argument is strongest for a site with visitors
 * who have already been told, and weakest right now, before launch, when
 * almost none have. So it goes in now rather than never. public/.htaccess
 * carries the reasoning in full, including how to back it out.
 *
 * Note what this does *not* cover: everything under /storage is a symlink into
 * public/, served by Apache without PHP ever running, so no middleware sees
 * it. Those files get the same headers from public/.htaccess instead, and
 * SecurityHeadersTest asserts the two lists have not drifted apart.
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

        // Never speak to this host in the clear again. Sent on plaintext
        // responses too: RFC 6797 requires a browser to ignore it there, so
        // there is nothing to guard, and a guard could only fail silently.
        'Strict-Transport-Security' => 'max-age=31536000',
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
