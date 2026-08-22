import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import siteConfig from '../constants/siteConfig';

export default function SEOHead({
    title,
    description,
    image,
    url,
    type = 'website',
    schemaData = null,
}) {
    // SEO defaults come from Site Settings when the admin has set them, so the
    // SEO tab is not decorative; siteConfig remains the final fallback.
    const settings = usePage().props?.site_settings || {};
    const brandName = settings.site_name || siteConfig.name;
    const brandTagline = settings.site_tagline || siteConfig.tagline;

    const pageTitle = title
        ? `${title} — ${brandName}`
        : settings.meta_title || `${brandName} | ${brandTagline}`;
    const pageDescription =
        description || settings.meta_description || siteConfig.description;
    const pageImage =
        image || settings.og_image || '/images/hero_gaming_pc.png';
    const pageKeywords = settings.meta_keywords || '';
    const pageUrl =
        url || (typeof window !== 'undefined' ? window.location.href : '');

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
