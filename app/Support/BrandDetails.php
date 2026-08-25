<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Store details for outgoing email.
 *
 * The templates previously hardcoded the showroom address and brand name while
 * the admin edits those under Site Settings, so changing them there had no
 * effect on what customers received.
 */
class BrandDetails
{
    /**
     * The shop's name.
     *
     * Site Settings is the authority — it is the field an admin actually edits.
     * APP_NAME is only the fallback for an install where nobody has set one
     * yet, which is why it reads as a generic placeholder rather than any
     * particular shop's name.
     */
    public static function name(): string
    {
        $name = trim((string) SiteSetting::get('site_name', ''));

        return $name !== '' ? $name : (string) config('app.name');
    }

    /**
     * The registered company name, for the footer copyright and anywhere else
     * the legal entity is named rather than the shop.
     *
     * Any trailing full stop is trimmed: it is followed by ". All Rights
     * Reserved", and a name written "… Ltd." rendered as "Ltd..".
     */
    public static function legalName(): string
    {
        $legal = trim((string) SiteSetting::get('site_legal_name', ''));

        return rtrim($legal !== '' ? $legal : self::name(), '. ');
    }

    /**
     * The shop's phone number.
     *
     * hotline_number is what the storefront reads; site_hotline is the older
     * key the admin form used to write, kept so an install that only has that
     * one does not lose its number.
     */
    public static function hotline(): string
    {
        foreach (['hotline_number', 'site_hotline'] as $key) {
            $value = trim((string) SiteSetting::get($key, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '16789';
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'name' => self::name(),
            'legal_name' => self::legalName(),
            'tagline' => SiteSetting::get('site_tagline', 'The Store of Technology'),
            'hotline' => self::hotline(),
            'address' => SiteSetting::get(
                'site_address',
                'Shop #301-304, Level 3, IDB Bhaban, Agargaon, Dhaka - 1207'
            ),
            'email' => SiteSetting::get('support_email', 'support@robinscomputer.com'),
            'url' => rtrim(config('app.url'), '/'),
            'logo' => self::logoUrl(),
        ];
    }

    /**
     * Absolute URL of the email logo, or null to fall back to the wordmark.
     *
     * Email clients have no page context, so a relative path never resolves —
     * this must always be absolute. Admins can point `site_logo` at an uploaded
     * file; otherwise the bundled logo is used.
     */
    public static function logoUrl(): ?string
    {
        $path = trim((string) SiteSetting::get('site_logo', '/images/logo.png'));

        if ($path === '') {
            return null;
        }

        // Already absolute (e.g. a CDN URL) — leave it alone.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim(config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * The logo as a path the browser resolves against the current host.
     *
     * Pages should use this rather than logoUrl(): an absolute URL built from
     * APP_URL breaks the moment the app is served from anywhere else — which is
     * exactly what happened on the invoice, where APP_URL still said :8000 and
     * the header rendered a broken image. Email is the opposite case and does
     * need something absolute or embedded.
     */
    public static function logoWebPath(): ?string
    {
        $path = trim((string) SiteSetting::get('site_logo', '/images/logo.png'));

        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return '/'.ltrim($path, '/');
    }

    /**
     * Filesystem path of the logo when it is a local file, otherwise null.
     *
     * Emails embed this directly rather than linking to it. A URL only works if
     * the site is publicly reachable — during local development APP_URL is
     * http://localhost:8000, which an inbox cannot fetch, so the header came out
     * blank. Embedding also survives the image blocking most clients apply to
     * remote content by default.
     */
    public static function localLogoPath(): ?string
    {
        $path = trim((string) SiteSetting::get('site_logo', '/images/logo.png'));

        if ($path === '' || preg_match('#^https?://#i', $path)) {
            return null;
        }

        $relative = ltrim($path, '/');

        // /storage/... is the public disk, served through the storage symlink.
        $file = str_starts_with($relative, 'storage/')
            ? storage_path('app/public/'.substr($relative, strlen('storage/')))
            : public_path($relative);

        return is_file($file) ? $file : null;
    }

    /** Digits only, for a tel: link. */
    public static function hotlineHref(): string
    {
        return 'tel:'.preg_replace('/[^0-9+]/', '', self::hotline());
    }
}
