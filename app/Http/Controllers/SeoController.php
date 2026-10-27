<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use Illuminate\Http\Response;

/**
 * robots.txt y sitemap.xml (27-oct-2026) — servidos por ruta, no como archivos
 * estáticos en public/, para que el dominio salga siempre de APP_URL (config
 * 'app.url') y nunca quede un localhost/dominio viejo pegado en producción.
 */
class SeoController extends Controller
{
    /** Rutas privadas listadas explícitamente (además del "Disallow: /" final). */
    private const PRIVATE_PATHS = [
        'dashboard', 'dashboard-data', 'dashboard-trend', 'home',
        'historico-general', 'periodos', 'asignaciones-empleado-sucursal',
        'empleados', 'validaciones', 'guia-sistema', 'reportes-mensuales',
        'efectividad-cobranza', 'okr', 'settings', 'notificaciones', 'documentacion-api',
        'api/', 'storage/', 'docs', 'up', 'reset-password', 'two-factor-challenge',
        'email', 'user', 'confirm-password', 'logout',
    ];

    /** Recursos que Google necesita para renderizar las páginas públicas. */
    private const PUBLIC_ASSETS = [
        '/$', '/build/', '/logoMrLana.png', '/logo-mr-lana.png', '/og-image.png',
        '/favicon.ico', '/favicon-32.png', '/apple-touch-icon.png', '/manifest.webmanifest',
    ];

    public function robots(): Response
    {
        $allow = collect(Seo::publicPages())
            ->keys()
            ->map(fn (string $name) => '/' . ltrim((string) parse_url(route($name), PHP_URL_PATH), '/'))
            ->merge(self::PUBLIC_ASSETS)
            ->map(fn (string $path) => "Allow: {$path}");

        $disallow = collect(self::PRIVATE_PATHS)
            ->map(fn (string $path) => "Disallow: /{$path}")
            ->push('Disallow: /');

        $body = "# Reportes Mr Lana — solo el inicio de sesión es público.\n"
            . "User-agent: *\n"
            . $allow->merge($disallow)->implode("\n")
            . "\n\nSitemap: " . Seo::absolute('sitemap.xml') . "\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $urls = collect(Seo::publicPages())->map(function (array $page, string $name) {
            $file = isset($page['view']) ? base_path($page['view']) : null;
            $lastmod = $file && is_file($file) ? filemtime($file) : time();
            $path = (string) parse_url(route($name), PHP_URL_PATH);

            return "  <url>\n"
                . '    <loc>' . e(Seo::absolute($path)) . "</loc>\n"
                . '    <lastmod>' . gmdate('Y-m-d', $lastmod) . "</lastmod>\n"
                . "    <changefreq>monthly</changefreq>\n"
                . '    <priority>' . e($page['priority'] ?? '0.5') . "</priority>\n"
                . '  </url>';
        });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . $urls->implode("\n")
            . "\n</urlset>\n";

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
