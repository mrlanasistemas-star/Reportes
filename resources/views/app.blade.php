<!DOCTYPE html>
@php
    // Datos SEO por página: view composer en AppServiceProvider (respaldo aquí).
    $seo ??= \App\Support\Seo::forRequest(request());
@endphp
<html lang="{{ $seo['html_lang'] }}" @class(['dark' => ($appearance ?? 'light') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            // Fase 8.2 (15-oct-2026): claro por defecto; la preferencia guardada
            // (localStorage primero, luego la cookie que ya leyó el servidor)
            // se aplica ANTES de pintar — sin parpadeo entre login y app aunque
            // la cookie se haya perdido y solo quede localStorage.
            (function() {
                var appearance = '{{ $appearance ?? "light" }}';

                try {
                    var stored = window.localStorage.getItem('appearance');

                    if (stored === 'light' || stored === 'dark' || stored === 'system') {
                        appearance = stored;
                    }
                } catch (e) {}

                var dark = appearance === 'dark'
                    || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>

        {{-- Fondo del <html> igual al token --background (theme.css) para que la
             primera pintura no destelle en blanco/negro distinto al de la app. --}}
        <style>
            html {
                background-color: #f8fafc;
            }

            html.dark {
                background-color: #0a0a0a;
            }
        </style>

        @php
            // Cache busting (retoma 21-sep-2026, punto 17): sin esto, un navegador que ya
            // cacheó favicon.ico/og-image.png nunca vuelve a pedirlos aunque el archivo en
            // disco cambie — filemtime() cambia solo cuando el archivo realmente cambia, así
            // que la URL solo invalida caché cuando hace falta (nunca en cada request).
            $assetV = fn (string $path) => file_exists(public_path($path)) ? '?v=' . filemtime(public_path($path)) : '';
        @endphp
        <link rel="icon" href="/favicon.ico{{ $assetV('favicon.ico') }}" sizes="any">
        <link rel="icon" href="/favicon-32.png{{ $assetV('favicon-32.png') }}" type="image/png" sizes="32x32">
        <link rel="icon" href="/logoMrLana.png{{ $assetV('logoMrLana.png') }}" type="image/png" sizes="256x256">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png{{ $assetV('apple-touch-icon.png') }}">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="msapplication-TileImage" content="{{ url('/logoMrLana.png' . $assetV('logoMrLana.png')) }}">
        <meta name="theme-color" content="#047857">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="{{ $seo['app_title'] }}">
        <meta name="application-name" content="{{ $seo['app_title'] }}">

        {{--
            SEO por página (27-oct-2026) — TODO se decide aquí, en el HTML que sirve
            PHP, porque es lo que leen los buscadores antes de ejecutar JavaScript.
            Los datos salen de App\Support\Seo (view composer en AppServiceProvider);
            qué páginas son públicas está en config/seo.php. Lo privado lleva
            además la cabecera X-Robots-Tag (App\Http\Middleware\AddRobotsHeader).
        --}}
        <meta name="robots" content="{{ $seo['robots'] }}">
        <meta name="googlebot" content="{{ $seo['robots'] }}">
        <meta name="description" content="{{ $seo['description'] }}">
        @if ($seo['canonical'])
            <link rel="canonical" href="{{ $seo['canonical'] }}">
        @endif
        @if ($seo['google_site_verification'])
            <meta name="google-site-verification" content="{{ $seo['google_site_verification'] }}">
        @endif

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $seo['site_name'] }}">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ $seo['url'] }}">
        <meta property="og:image" content="{{ $seo['image'] . $assetV('og-image.png') }}">
        <meta property="og:image:secure_url" content="{{ $seo['image'] . $assetV('og-image.png') }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="{{ $seo['image_width'] }}">
        <meta property="og:image:height" content="{{ $seo['image_height'] }}">
        <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
        <meta property="og:locale" content="{{ $seo['og_locale'] }}">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $seo['title'] }}">
        <meta name="twitter:description" content="{{ $seo['description'] }}">
        <meta name="twitter:image" content="{{ $seo['image'] . $assetV('og-image.png') }}">
        <meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">

        @if ($seo['json_ld'])
            {{-- JSON armado y escapado en PHP (App\Support\Seo::jsonLd) — nunca
                 escribir '@' . 'context' literal aquí: Blade lo toma por directiva. --}}
            <script type="application/ld+json">{!! $seo['json_ld'] !!}</script>
        @endif

        @if ($seo['indexable'])
            {{-- Core Web Vitals del login: el logo (LCP en móvil) y la foto del panel
                 lateral (LCP en escritorio, solo ≥1024px) se piden desde el <head>. --}}
            {{-- Misma URL exacta que el <img> de AuthBrand (sin ?v) o el navegador lo baja dos veces. --}}
            <link rel="preload" as="image" href="/logoMrLana.png" type="image/png">
            @php
                try {
                    $loginSideImage = \Illuminate\Support\Facades\Vite::asset('resources/js/img/login-side.jpg');
                } catch (\Throwable $e) {
                    $loginSideImage = null;
                }
            @endphp
            @if ($loginSideImage)
                <link rel="preload" as="image" href="{{ $loginSideImage }}" media="(min-width: 1024px)" fetchpriority="high">
            @endif
        @endif

        <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        {{-- data-inertia="" = Inertia reemplaza ESTE <title> con el de <Head> (si
             no lo lleva, Inertia agrega un segundo <title> y quedan dos). --}}
        <x-inertia::head>
            <title data-inertia="">{{ $seo['title'] }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        @if ($seo['indexable'] && $seo['heading'])
            {{-- Contenido indexable sin JavaScript: el login es SPA y #app llega
                 vacío en el HTML inicial (no hay SSR, ver docs/seo.md). --}}
            <noscript>
                <main style="max-width: 32rem; margin: 4rem auto; padding: 0 1rem; font-family: system-ui, sans-serif;">
                    <img src="/logoMrLana.png" alt="Logo Mr Lana" width="64" height="64">
                    <h1>{{ $seo['heading'] }}</h1>
                    <p>{{ $seo['intro'] }}</p>
                    <p>Para iniciar sesión activa JavaScript en tu navegador.</p>
                </main>
            </noscript>
        @endif
        <x-inertia::app />
    </body>
</html>
