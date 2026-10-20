<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Where a branch is, from what staff paste out of Google Maps.
 *
 * Share → Copy link gives a short maps.app.goo.gl link; the address bar gives
 * a long one; Share → Embed a map gives an <iframe>. All three are taken, and
 * the pin is read from the link itself — the place's own coordinates first,
 * then the point the map was centred on. The branch form drops its pin there,
 * for staff to nudge onto the building if need be.
 */
class MapLink
{
    /** Hosts a Google Maps link can come from. */
    private const HOSTS = '/^(maps\.app\.goo\.gl|goo\.gl|(www\.|maps\.)?google\.[a-z.]+)$/i';

    private const SHORT = '/^(maps\.app\.goo\.gl|goo\.gl)$/i';

    /**
     * The pin for what was pasted.
     *
     * @return array{latitude: float, longitude: float}
     *
     * @throws \InvalidArgumentException with a message for the form
     */
    public static function pinFor(?string $pasted): array
    {
        $pasted = trim((string) $pasted);

        if ($pasted === '') {
            throw new \InvalidArgumentException('Paste the branch\'s link from Google Maps: find the branch, then Share → Copy link.');
        }

        // "Embed a map" code: its place sits in the pb as !2d<lng>!3d<lat>.
        if (preg_match('/<iframe[^>]+src="([^"]+)"/i', $pasted, $m)) {
            $src = html_entity_decode($m[1]);

            if (self::isGoogle($src) && preg_match('/!2d(-?\d+\.\d+)!3d(-?\d+\.\d+)/', $src, $c)) {
                return self::pin((float) $c[2], (float) $c[1]);
            }

            throw new \InvalidArgumentException('That embed code is not a Google map of a place.');
        }

        if (! self::isGoogle($pasted)) {
            throw new \InvalidArgumentException('Paste the link from Google Maps: find the branch, then Share → Copy link.');
        }

        $url = rawurldecode(self::expand($pasted));

        // The place's own pin, which a link to a place always carries.
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) {
            return self::pin((float) $m[1], (float) $m[2]);
        }

        // Where the map was centred, or a point it was asked for.
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)
            || preg_match('/[?&](?:q|query|ll|destination)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/', $url, $m)) {
            return self::pin((float) $m[1], (float) $m[2]);
        }

        throw new \InvalidArgumentException('That link does not say where the place is. In Google Maps, open the branch itself, then Share → Copy link — or drag the pin onto it.');
    }

    /** @return array{latitude: float, longitude: float} */
    private static function pin(float $latitude, float $longitude): array
    {
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            throw new \InvalidArgumentException('That link points nowhere on the map.');
        }

        return ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
    }

    private static function isGoogle(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && (bool) preg_match(self::HOSTS, $host)
            && (str_contains($url, '/maps') || preg_match(self::SHORT, $host));
    }

    /** A short link, followed to the full one, which carries the place. */
    private static function expand(string $url): string
    {
        for ($hop = 0; $hop < 5 && preg_match(self::SHORT, (string) parse_url($url, PHP_URL_HOST)); $hop++) {
            try {
                $response = Http::withoutRedirecting()->timeout(8)->get($url);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('Google Maps did not answer just now. Try again in a moment, or drag the pin.');
            }

            $next = $response->header('Location');

            if (! $response->redirect() || $next === '') {
                break;
            }

            $url = $next;
        }

        return $url;
    }
}
