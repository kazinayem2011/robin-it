import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import siteConfig from '../constants/siteConfig';
import { withBrand } from '../utils/pageTitle';

export default function SEOHead({
    title,
    description,
    image,
    url,
    type = 'website',
    schemaData = null,
    // Pages behind a login or in the checkout funnel should not be indexed
    // even if a crawler reaches them past robots.txt.
    noindex = false,
}) {
    // SEO defaults come from Site Settings when the admin has set them, so the
    // SEO tab is not decorative; siteConfig remains the final fallback.
    const settings = usePage().props?.site_settings || {};
    const brandName = settings.site_name || siteConfig.name;
    const brandTagline = settings.site_tagline || siteConfig.tagline;

    /*
     * Several pages pass a title that already ends with the brand, and this
     * appended it a second time — the home page read "Robins Computer — The
     * Store of Technology — Robins Computer". A meta_title the admin wrote is
     * left exactly as written.
     */
    const pageTitle = title
        ? withBrand(title, brandName)
        : settings.meta_title || `${brandName} | ${brandTagline}`;
    const pageDescription =
        description || settings.meta_description || siteConfig.description;
    const pageImage =
        image || settings.og_image || '/images/hero_gaming_pc.png';
    const pageKeywords = settings.meta_keywords || '';
    const pageUrl =
        url || (typeof window !== 'undefined' ? window.location.href : '');

    // The canonical must not carry the query string. ?page=2, ?sort=price and
    // ?ref=fb are the same page as far as indexing goes, and pointing each at
    // itself is what creates the duplicate-content problem canonical exists to
    // solve. Paging keeps its own URL so page 2 is not claimed to be page 1.
    const canonicalUrl =
        typeof window !== 'undefined'
            ? window.location.origin + window.location.pathname
            : pageUrl;

    const defaultSchema = {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: brandName,
        url: typeof window !== 'undefined' ? window.location.origin : '',
        telephone: siteConfig.hotline,
        address: {
            '@type': 'PostalAddress',
            streetAddress: siteConfig.contactAddress,
            addressCountry: 'BD',
        },
    };

    const finalSchema = schemaData || defaultSchema;

    return (
        <Head>
            <title>{pageTitle}</title>
            <meta name="description" content={pageDescription} />
            {pageKeywords && <meta name="keywords" content={pageKeywords} />}
            {settings.google_site_verification && (
                <meta
                    name="google-site-verification"
                    content={settings.google_site_verification}
                />
            )}
            {/*
             * Canonical. A product reachable as /products/x and
             * /products/x?ref=whatever is one page, and without this each
             * variation competes with the others for the same ranking.
             */}
            <link rel="canonical" href={canonicalUrl} />

            {noindex && <meta name="robots" content="noindex, follow" />}

            <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
            <link rel="alternate icon" href="/favicon.ico" />

            {/* Open Graph / Facebook */}
            <meta property="og:type" content={type} />
            <meta property="og:title" content={pageTitle} />
            <meta property="og:description" content={pageDescription} />
            <meta property="og:image" content={pageImage} />
            <meta property="og:url" content={pageUrl} />
            <meta property="og:site_name" content={siteConfig.name} />

            {/* Twitter */}
            <meta name="twitter:card" content="summary_large_image" />
            <meta name="twitter:title" content={pageTitle} />
            <meta name="twitter:description" content={pageDescription} />
            <meta name="twitter:image" content={pageImage} />

            {/* Schema.org Structured Data */}
            <script type="application/ld+json">
                {JSON.stringify(finalSchema)}
            </script>
        </Head>
    );
}
