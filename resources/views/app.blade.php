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

        {{--
            What a crawler reads.

            The shop is Inertia, so every one of these used to be written by
            React once the bundle had run. Google gets there on a second pass;
            Facebook, WhatsApp, LinkedIn and Twitter never do — they read the
            HTML as delivered and stop, which is why a shared product link
            arrived with no title, no description and no picture.

            `inertia` on each tag hands it to Inertia's head manager, so
            SEOHead replaces these on the client rather than adding a second
            copy beside them. The shop names itself in Site Settings; APP_NAME
            is only the fallback for an install where nobody has set one yet.
        --}}
        @php($seo = \App\Support\Seo::for($page['props']['seo'] ?? []))

        <title inertia>{{ $seo['title'] }}</title>
        <meta inertia name="description" content="{{ $seo['description'] }}">
        @if ($seo['keywords'])
            <meta inertia name="keywords" content="{{ $seo['keywords'] }}">
        @endif
        @if ($seo['noindex'])
            <meta inertia name="robots" content="noindex, follow">
        @endif
        @if ($seo['verification'])
            <meta inertia name="google-site-verification" content="{{ $seo['verification'] }}">
        @endif
        <link inertia rel="canonical" href="{{ $seo['canonical'] }}">

        {{-- Open Graph: the share card on Facebook, WhatsApp and LinkedIn. --}}
        <meta inertia property="og:type" content="{{ $seo['type'] }}">
        <meta inertia property="og:title" content="{{ $seo['title'] }}">
        <meta inertia property="og:description" content="{{ $seo['description'] }}">
        <meta inertia property="og:url" content="{{ $seo['canonical'] }}">
        <meta inertia property="og:site_name" content="{{ $seo['site_name'] }}">
        @if ($seo['image'])
            <meta inertia property="og:image" content="{{ $seo['image'] }}">
            @if (str_starts_with($seo['image'], 'https://'))
                <meta inertia property="og:image:secure_url" content="{{ $seo['image'] }}">
            @endif
            <meta inertia property="og:image:alt" content="{{ $seo['title'] }}">
            @if ($seo['image_width'] && $seo['image_height'])
                <meta inertia property="og:image:width" content="{{ $seo['image_width'] }}">
                <meta inertia property="og:image:height" content="{{ $seo['image_height'] }}">
            @endif
        @endif

        {{--
            What puts a price, a stock state and a star rating in a search
            result rather than a bare blue link. Built here as well as on the
            page, because only Google reads the page's copy, and only on a
            second pass.
        --}}
        @if ($seo['schema'])
            <script type="application/ld+json">{!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif

        <meta inertia name="twitter:card" content="summary_large_image">
        <meta inertia name="twitter:title" content="{{ $seo['title'] }}">
        <meta inertia name="twitter:description" content="{{ $seo['description'] }}">
        @if ($seo['image'])
            <meta inertia name="twitter:image" content="{{ $seo['image'] }}">
        @endif

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">

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
