import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
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
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
