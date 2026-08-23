import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

/**
 * Vitest runs without laravel-vite-plugin: that plugin exists to emit a
 * manifest for Blade, which a test run has no use for. It is also where the
 * `@` alias comes from, so the alias has to be restated here or every
 * `@/Components` import fails to resolve.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/__tests__/setup.js'],
        include: ['resources/js/**/*.{test,spec}.{js,jsx}'],
        restoreMocks: true,
    },
});
