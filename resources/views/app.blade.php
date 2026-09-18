<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/logoMrLana.png" type="image/png" sizes="256x256">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#4f46e5">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Reportes">

        @php
            // SEO — el único destino público real del sitio es /login (todo lo demás
            // exige sesión iniciada y redirige ahí, ver routes/web.php). Decidido AQUÍ,
            // en el HTML servido por PHP — nunca en el <Head> de Login.vue — porque esa
            // parte solo corre después de que el navegador ejecuta JavaScript: un
            // crawler que no la ejecute (la mayoría de los que solo arman la vista
            // previa de un link, y potencialmente el primer paso de indexación de
            // Google) seguiría viendo "noindex" en el HTML crudo pase lo que pase en Vue.
            $isPubliclyIndexable = request()->routeIs('login');
            $description = $isPubliclyIndexable
                ? 'Inicia sesión en tu panel de Reportes LANA — radiografía financiera, reportes y seguimiento de metas de tu operación.'
                : 'Accede a tu panel de Reportes LANA — radiografía financiera, reportes y seguimiento de metas de tu operación.';
        @endphp
        <meta name="robots" content="{{ $isPubliclyIndexable ? 'index, follow' : 'noindex, nofollow' }}">
        <meta name="description" content="{{ $description }}">
        <link rel="canonical" href="{{ url()->current() }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name', 'Reportes') }}">
        <meta property="og:title" content="{{ config('app.name', 'Reportes') }} LANA">
        <meta property="og:description" content="Accede a tu panel de Reportes LANA.">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:locale" content="es_MX">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ config('app.name', 'Reportes') }} LANA">
        <meta name="twitter:description" content="Accede a tu panel de Reportes LANA.">
        <meta name="twitter:image" content="{{ asset('og-image.png') }}">

        {{--
            La clave '@context' se arma por concatenación a propósito: escrita
            literal ('@context' => ...) Blade la confunde con SU PROPIA directiva
            @context (Illuminate\View\Compilers — comparte el token, no tiene nada
            que ver con JSON-LD) y la reemplaza por PHP roto antes de que
            json_encode() vea nada.
        --}}
        <script type="application/ld+json">
            {!! json_encode([
                ('@' . 'context') => 'https://schema.org',
                '@type' => 'Organization',
                'name' => config('app.name', 'Reportes') . ' LANA',
                'url' => url('/'),
                'logo' => asset('logoMrLana.png'),
            ], JSON_UNESCAPED_SLASHES) !!}
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
