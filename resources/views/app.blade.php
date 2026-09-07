<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- The colour the phone paints its status bar and the browser its
             chrome. Rewritten by the script below to match the theme. --}}
        <meta name="theme-color" content="#f1f4f8">

        {{--
            The theme, applied before anything is painted.

            This has to be inline, blocking, and above the stylesheet. The app
            is Inertia, so the page is drawn by React after the bundle loads —
            if the theme were applied there, every visit by somebody who has
            chosen dark would paint a white page first and snap to dark a
            moment later. Three lines of duplicated logic in the head is the
            price of not having that flash, and it is worth paying.

            Only an explicit choice counts. The machine's own preference is
            deliberately not consulted: this is a light shop, and a customer
            with a dark laptop should not have it changed out from under them
            on a first visit. Anything unrecognised — including the 'system'
            this used to store — falls through to light.

            Kept deliberately tiny and total: it reads the same key
            resources/js/utils/theme.js owns, and cannot throw. localStorage
            alone throws outright in a browser with site data blocked, and an
            exception here would stop the page loading at all.
        --}}
        <script>
            (function () {
                var theme = 'light';

                try {
                    if (localStorage.getItem('robinit.theme.v1') === 'dark') {
                        theme = 'dark';
                    }
                } catch (e) {
                    /* Site data blocked; light it is. */
                }

                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.style.colorScheme = theme;

                if (theme === 'dark') {
                    document
                        .querySelector('meta[name="theme-color"]')
                        .setAttribute('content', '#0a0e17');
                }
            })();
        </script>

        {{-- The shop names itself in Site Settings; APP_NAME is only the
             fallback for an install where nobody has set one yet. --}}
        <title inertia>{{ \App\Support\BrandDetails::name() }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="alternate icon" href="/favicon.ico">

        <!-- Local Tech Fonts (Plus Jakarta Sans & Inter) -->
        <link rel="stylesheet" href="/fonts/fonts.css">

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
