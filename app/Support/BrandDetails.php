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
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'name' => SiteSetting::get('site_name', 'Robins Computer'),
            'tagline' => SiteSetting::get('site_tagline', 'The Store of Technology'),
            'hotline' => SiteSetting::get('site_hotline', '09600-ROBIN-IT'),
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

    /** Digits only, for a tel: link. */
    public static function hotlineHref(): string
    {
        $hotline = SiteSetting::get('site_hotline', '09600-ROBIN-IT');

        return 'tel:'.preg_replace('/[^0-9+]/', '', $hotline);
    }
}
