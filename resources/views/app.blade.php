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

            `inertia` goes last on every tag, after name/property and content.
            WhatsApp builds its preview on the sender's phone with a parser
            that expects those first: with `inertia` in front it read none of
            these tags, only <title>, so every shared link showed a bare title
            with no picture and no description. Inertia finds its tags by the
            attribute being present, wherever it sits.
        --}}
        @php($seo = \App\Support\Seo::for($page['props']['seo'] ?? []))

        <title inertia>{{ $seo['title'] }}</title>
        <meta name="description" content="{{ $seo['description'] }}" inertia>
        @if ($seo['keywords'])
            <meta name="keywords" content="{{ $seo['keywords'] }}" inertia>
        @endif
        @if ($seo['noindex'])
            <meta name="robots" content="noindex, follow" inertia>
        @endif
        @if ($seo['verification'])
            <meta name="google-site-verification" content="{{ $seo['verification'] }}" inertia>
        @endif
        <link rel="canonical" href="{{ $seo['canonical'] }}" inertia>

        {{-- Open Graph: the share card on Facebook, WhatsApp and LinkedIn. --}}
        <meta property="og:type" content="{{ $seo['type'] }}" inertia>
        <meta property="og:title" content="{{ $seo['title'] }}" inertia>
        <meta property="og:description" content="{{ $seo['description'] }}" inertia>
        <meta property="og:url" content="{{ $seo['canonical'] }}" inertia>
        <meta property="og:site_name" content="{{ $seo['site_name'] }}" inertia>
        @if ($seo['image'])
            <meta property="og:image" content="{{ $seo['image'] }}" inertia>
            @if (str_starts_with($seo['image'], 'https://'))
                <meta property="og:image:secure_url" content="{{ $seo['image'] }}" inertia>
            @endif
            <meta property="og:image:alt" content="{{ $seo['title'] }}" inertia>
            @if ($seo['image_width'] && $seo['image_height'])
                <meta property="og:image:width" content="{{ $seo['image_width'] }}" inertia>
                <meta property="og:image:height" content="{{ $seo['image_height'] }}" inertia>
            @endif
        @endif
        {{-- An article's date, section and author, which Facebook and LinkedIn show on its card. --}}
        @foreach ($seo['article'] ?? [] as $property => $value)
            <meta property="article:{{ $property }}" content="{{ $value }}" inertia>
        @endforeach

        {{--
            What puts a price, a stock state and a star rating in a search
            result rather than a bare blue link. Built here as well as on the
            page, because only Google reads the page's copy, and only on a
            second pass.
        --}}
        {{--
            JSON_HEX_TAG: slashes are left unescaped, so without it a product
            or article title containing "</script>" would close this tag early
            and the rest of the title would be read as HTML. It writes < and >
            as < and >, which is still the same JSON.
        --}}
        @if ($seo['schema'])
            <script type="application/ld+json">{!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endif

        <meta name="twitter:card" content="summary_large_image" inertia>
        <meta name="twitter:title" content="{{ $seo['title'] }}" inertia>
        <meta name="twitter:description" content="{{ $seo['description'] }}" inertia>
        @if ($seo['image'])
            <meta name="twitter:image" content="{{ $seo['image'] }}" inertia>
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
