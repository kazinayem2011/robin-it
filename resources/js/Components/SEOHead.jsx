import React from 'react';
import { Head } from '@inertiajs/react';
import siteConfig from '../constants/siteConfig';

export default function SEOHead({
    title,
    description,
    image,
    url,
    type = 'website',
    schemaData = null,
}) {
    const pageTitle = title
        ? `${title} — ${siteConfig.name}`
        : `${siteConfig.name} | ${siteConfig.tagline}`;
    const pageDescription = description || siteConfig.description;
    const pageImage = image || '/images/hero_gaming_pc.png';
    const pageUrl =
        url || (typeof window !== 'undefined' ? window.location.href : '');

    const defaultSchema = {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name: siteConfig.name,
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
