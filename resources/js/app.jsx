import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import siteConfig, { setBrandName } from './constants/siteConfig';
import { withBrand } from './utils/pageTitle';

/*
 * Every page already ends its title with the shop's name, so appending
 * VITE_APP_NAME on top produced "Custom PC Builder — Robins Computer - Laravel"
 * — the brand twice, the second time wrong, in every browser tab and at the top
 * of every printed page. The shop's own name is the authority here; the env
 * value is a Laravel default nobody set.
 */
const pageTitle = (title) => withBrand(title, siteConfig.name);

createInertiaApp({
    title: pageTitle,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            // Tests co-located under Pages must be excluded, or the glob
            // treats them as page components and Vite bundles them — Vitest
            // and Testing Library included — into the production build. One
            // test file was shipping as the largest asset on the site.
            import.meta.glob([
                './Pages/**/*.jsx',
                '!./Pages/**/__tests__/**',
                '!./Pages/**/*.test.jsx',
            ]),
        ),
    setup({ el, App, props }) {
        // Before the first render, so the very first page title already uses
        // the name the shop set rather than the literal this module ships with.
        setBrandName(props.initialPage?.props?.brand_name);

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
