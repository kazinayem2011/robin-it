<?php

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitise admin-authored HTML before it is stored.
 *
 * Blog articles and product descriptions are rendered with
 * dangerouslySetInnerHTML on public pages, and were validated as nothing more
 * than `string`. Anything an admin pasted — from a compromised account, or from
 * a page that carried more than it appeared to — became script running for every
 * visitor.
 *
 * Cleaning on the way in rather than on the way out means the database never
 * holds the hostile markup, so a template that forgets to escape cannot
 * resurrect it.
 */
class RichText
{
    /**
     * Tags an article or description may use. Deny by default: anything absent
     * from this list has its markup dropped and its text kept.
     */
    private const ALLOWED = 'h2,h3,h4,p,br,hr,strong,b,em,i,u,s,blockquote,'
        .'ul,ol,li,a[href|title|rel|target],img[src|alt|title|width|height],'
        .'table,thead,tbody,tr,th,td,code,pre,span,div';

    private static ?HTMLPurifier $purifier = null;

    /**
     * Clean a rich-text field. Null and empty stay as they are so a nullable
     * column is not turned into an empty string.
     */
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return self::purifier()->purify($html);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('AutoFormat.RemoveEmpty', true);
        // Only these schemes. Blocks javascript:, vbscript: and data: URLs,
        // which are the usual way an <a> or <img> smuggles script back in.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // Anything opened in a new tab must not keep a handle on this one.
        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedRel', ['nofollow', 'noopener', 'noreferrer']);

        $cache = storage_path('framework/cache/htmlpurifier');

        if (! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        if (is_writable($cache)) {
            $config->set('Cache.SerializerPath', $cache);
        } else {
            // A read-only deploy must not take the admin down; skip the cache.
            $config->set('Cache.DefinitionImpl', null);
        }

        return self::$purifier = new HTMLPurifier($config);
    }
}
