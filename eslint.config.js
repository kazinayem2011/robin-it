import js from '@eslint/js';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';

/**
 * Deliberately narrow: this is not a style linter — Prettier already owns
 * formatting — it is here to catch references to things that do not exist.
 *
 * Products/Show.jsx rendered <ProductImage> without importing it. Nothing
 * failed at build time, so every product detail page threw at render and showed
 * a blank screen, in production, invisible to the entire PHP suite. That is one
 * rule away from impossible.
 */
export default [
    {
        ignores: ['public/**', 'node_modules/**', 'vendor/**', 'storage/**'],
    },
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: 'module',
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
            globals: {
                ...globals.browser,
                ...globals.es2024,
                route: 'readonly', // Ziggy, injected into the page by Blade.
            },
        },
        plugins: { react, 'react-hooks': reactHooks },
        settings: { react: { version: 'detect' } },
        rules: {
            ...js.configs.recommended.rules,

            // The rule this whole config exists for.
            'react/jsx-no-undef': 'error',
            'no-undef': 'error',

            // Count JSX usage, otherwise every imported component reads as
            // unused and the real warnings drown.
            'react/jsx-uses-vars': 'error',
            'react/jsx-uses-react': 'error',

            // Hooks called conditionally are always a bug.
            'react-hooks/rules-of-hooks': 'error',

            // The SearchInput loop — an effect depending on a callback that is
            // recreated every render — is exactly what this reports. A warning
            // rather than an error, because a deliberate ref-based escape hatch
            // is legitimate and is annotated where it is used.
            'react-hooks/exhaustive-deps': 'warn',

            'no-unused-vars': [
                'warn',
                {
                    argsIgnorePattern: '^_',
                    varsIgnorePattern: '^_',
                    caughtErrors: 'none',
                },
            ],
            'no-empty': 'off',
        },
    },
    {
        files: ['resources/js/**/*.test.{js,jsx}', 'resources/js/__tests__/**'],
        languageOptions: {
            globals: { ...globals.node, ...globals.browser },
        },
    },
];
