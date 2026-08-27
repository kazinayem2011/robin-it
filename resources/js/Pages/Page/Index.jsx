import React from 'react';
import { Head } from '@inertiajs/react';
import { mainLayout } from '../../Layouts/MainLayout';
import siteConfig from '../../constants/siteConfig';
import './Page.css';

/**
 * Anything the shop writes itself: privacy, terms, the return policy.
 *
 * The body is purified when it is saved, not when it is rendered, so what is
 * in the column is already what is safe to show.
 */
export default function ContentPage({ page = {}, updatedAt = null }) {
    return (
        <>
            <Head
                title={`${page.meta_title || page.title} — ${siteConfig.name}`}
            >
                {page.meta_description && (
                    <meta
                        head-key="description"
                        name="description"
                        content={page.meta_description}
                    />
                )}
            </Head>

            <article className="doc-page container">
                <header className="doc-head">
                    <h1>{page.title}</h1>
                    {page.subtitle && <p>{page.subtitle}</p>}
                    {updatedAt && (
                        <span className="doc-updated">
                            Last updated {updatedAt}
                        </span>
                    )}
                </header>

                <div
                    className="doc-body"
                    dangerouslySetInnerHTML={{ __html: page.body || '' }}
                />
            </article>
        </>
    );
}

ContentPage.layout = mainLayout;
