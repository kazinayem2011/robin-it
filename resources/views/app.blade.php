<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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
