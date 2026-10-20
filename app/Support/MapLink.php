<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * A branch's map, from whatever staff paste out of Google Maps.
 *
 * Share → Copy link gives a short maps.app.goo.gl link; the address bar gives
 * a long one; Share → Embed a map gives an <iframe>. All three are taken. The
 * pin is read from the link itself — a place's own coordinates first, then
 * the point the map was centred on — and drawn with Google's keyless embed,
 * so there is no API key to set up or pay for.
 */
class MapLink
{
    /** Hosts a Google Maps link can come from. */
    private const HOSTS = '/^(maps\.app\.goo\.gl|goo\.gl|(www\.|maps\.)?google\.[a-z.]+)$/i';

    /**
     * The address to draw for what was pasted, or null when nothing was.
     *
     * @throws \InvalidArgumentException with a message for the form
     */
    public static function embedFor(?string $pasted): ?string
    {
        $pasted = trim((string) $pasted);

        if ($pasted === '') {
            return null;
        }

        // "Embed a map" code: Google has already made the map.
        if (preg_match('/<iframe[^>]+src="([^"]+)"/i', $pasted, $m)) {
            $src = html_entity_decode($m[1]);

            if (self::isGoogle($src) && str_contains($src, '/maps/embed')) {
                return $src;
            }

            throw new \InvalidArgumentException('That embed code is not a Google map.');
        }

        if (! self::isGoogle($pasted)) {
            throw new \InvalidArgumentException('Paste the link from Google Maps: find the branch, then Share → Copy link.');
        }

        $where = self::placeIn(self::expand($pasted));

        if ($where === null) {
            throw new \InvalidArgumentException('That link does not point at a place. In Google Maps, open the branch itself, then Share → Copy link.');
        }

        return 'https://maps.google.com/maps?q='.rawurlencode($where).'&z=17&output=embed';
    }

    private static function isGoogle(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($host) && $scheme === 'https' && (bool) preg_match(self::HOSTS, $host)
            && (str_contains($url, '/maps') || preg_match('/^(maps\.app\.goo\.gl|goo\.gl)$/i', $host));
    }

    /** A short link, followed to the full one, which carries the place. */
    private static function expand(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! preg_match('/^(maps\.app\.goo\.gl|goo\.gl)$/i', $host)) {
            return $url;
        }

        for ($hop = 0; $hop < 5; $hop++) {
            try {
                $response = Http::withoutRedirecting()->timeout(8)->get($url);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('Google Maps did not answer just now. Try saving again in a moment.');
            }

            $next = $response->header('Location');

            if (! $response->redirect() || $next === '') {
                return $url;
            }

            $url = $next;

            if (! preg_match('/^(maps\.app\.goo\.gl|goo\.gl)$/i', (string) parse_url($url, PHP_URL_HOST))) {
                return $url;
            }
        }

        return $url;
    }

    /** "23.73,90.41", or a place's name, from a full Google Maps link. */
    private static function placeIn(string $url): ?string
    {
        $url = rawurldecode($url);

        // The place's own pin, which a link to a place always carries.
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) {
            return $m[1].','.$m[2];
        }

        // Where the map was centred.
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return $m[1].','.$m[2];
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        foreach (['q', 'query', 'll', 'destination'] as $key) {
            if (! empty($query[$key]) && is_string($query[$key])) {
                return trim($query[$key]);
            }
        }

        if (preg_match('#/maps/place/([^/@]+)#', $url, $m)) {
            return trim(str_replace('+', ' ', $m[1]));
        }

        return null;
    }
}
