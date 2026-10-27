<?php

/*
| SEO técnico (27-oct-2026). Ver docs/seo.md y App\Support\Seo.
|
| Solo las páginas públicas (login y recuperar contraseña) son indexables; todo
| lo demás lleva noindex en el HTML y en la cabecera X-Robots-Tag
| (App\Http\Middleware\AddRobotsHeader). Las URLs absolutas (canonical, og:url,
| sitemap, robots) salen de APP_URL: en producción DEBE ser el dominio https real.
*/

return [
    // Nombre de la empresa/marca (og:site_name, JSON-LD Organization).
    'site_name' => env('SEO_SITE_NAME', 'Mr Lana'),

    // Nombre del producto en buscadores. Vacío = se deriva de APP_NAME
    // ("Reportes" → "Reportes Mr Lana"); siempre contiene la marca.
    'app_title' => env('SEO_APP_TITLE'),

    // Atributo lang del <html>.
    'html_lang' => env('SEO_HTML_LANG', 'es-MX'),

    'og_locale' => 'es_MX',

    // Google Search Console — verificación por meta tag (solo el valor de content).
    'google_site_verification' => env('GOOGLE_SITE_VERIFICATION'),

    // Páginas públicas indexables, por nombre de ruta. Cada una aparece en
    // sitemap.xml y lleva "index, follow"; cualquier otra ruta es noindex.
    // {app} = "Reportes Mr Lana", {site} = "Mr Lana".
    'public_pages' => [
        'login' => [
            'title' => '{app} — Iniciar sesión',
            'description' => '{app} es el sistema interno de reportes financieros de {site}: radiografía financiera, cierre mensual y metas por sucursal. Inicia sesión con tu cuenta.',
            'heading' => '{app}',
            'intro' => 'Sistema interno de reportes financieros de {site}. Inicia sesión con el correo y la contraseña que te asignó tu administrador.',
            'view' => 'resources/js/pages/auth/Login.vue',
            'priority' => '1.0',
        ],
        'password.request' => [
            'title' => 'Recuperar contraseña — {app}',
            'description' => 'Recupera el acceso a {app}, el sistema interno de reportes financieros de {site}. Te enviaremos un enlace para restablecer tu contraseña.',
            'heading' => 'Recuperar contraseña de {app}',
            'intro' => 'Ingresa tu correo y te enviaremos un enlace para restablecer tu acceso a {app}.',
            'view' => 'resources/js/pages/auth/ForgotPassword.vue',
            'priority' => '0.3',
        ],
    ],

    // Rutas sin cabecera X-Robots-Tag (archivos que leen los buscadores).
    'header_exempt_routes' => ['robots', 'sitemap'],
];
