<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * SEO por página (27-oct-2026). Única fuente de título, descripción, robots,
 * canonical, Open Graph y JSON-LD que pinta resources/views/app.blade.php EN EL
 * SERVIDOR — los buscadores leen ese HTML inicial antes de ejecutar JavaScript.
 * Qué páginas son públicas se define en config/seo.php ('public_pages').
 */
class Seo
{
    public const INDEX = 'index, follow';

    public const NOINDEX = 'noindex, nofollow';

    /** Marca de empresa: "Mr Lana". */
    public static function siteName(): string
    {
        return (string) config('seo.site_name', 'Mr Lana');
    }

    /** Nombre del producto en buscadores; siempre contiene la marca ("Reportes Mr Lana"). */
    public static function appTitle(): string
    {
        $explicit = trim((string) config('seo.app_title'));
        if ($explicit !== '') {
            return $explicit;
        }

        $name = trim((string) config('app.name', 'Reportes')) ?: 'Reportes';
        $site = self::siteName();

        $normalize = fn (string $v) => preg_replace('/[^a-z]/', '', mb_strtolower($v));

        return str_contains($normalize($name), $normalize($site)) ? $name : "{$name} {$site}";
    }

    /** Raíz absoluta desde APP_URL (nunca del Host de la petición). */
    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function absolute(string $path): string
    {
        $path = trim($path, '/');

        return self::baseUrl() . '/' . $path;
    }

    /** @return array<string, array<string, string>> páginas públicas cuyas rutas existen */
    public static function publicPages(): array
    {
        return collect((array) config('seo.public_pages', []))
            ->filter(fn ($page, $name) => Route::has($name))
            ->all();
    }

    protected static function routeName(Request $request): ?string
    {
        $route = $request->route();

        return is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
    }

    public static function isIndexable(Request $request): bool
    {
        $name = self::routeName($request);

        return $name !== null
            && array_key_exists($name, self::publicPages())
            && $request->isMethod('GET');
    }

    /** Valor de X-Robots-Tag para la respuesta, o null si no debe llevar cabecera. */
    public static function robotsHeaderFor(Request $request): ?string
    {
        $name = self::routeName($request);

        if ($name !== null && in_array($name, (array) config('seo.header_exempt_routes', []), true)) {
            return null;
        }

        return self::isIndexable($request) ? self::INDEX : self::NOINDEX;
    }

    /**
     * Datos SEO de la petición actual para el root view (app.blade.php).
     *
     * @return array<string, mixed>
     */
    public static function forRequest(Request $request): array
    {
        $app = self::appTitle();
        $site = self::siteName();
        $fill = fn (?string $text) => $text === null ? null : strtr($text, ['{app}' => $app, '{site}' => $site]);

        $indexable = self::isIndexable($request);
        $page = $indexable ? self::publicPages()[self::routeName($request)] : [];

        $title = $fill($page['title'] ?? null) ?? $app;
        $description = $fill($page['description'] ?? null)
            ?? "{$app}: sistema interno de reportes financieros de {$site}. Acceso solo para personal autorizado.";
        $canonical = $indexable ? self::absolute($request->path()) : null;
        $image = self::absolute('og-image.png');
        $logo = self::absolute('logoMrLana.png');
        $lang = (string) config('seo.html_lang', 'es-MX');

        return [
            'indexable' => $indexable,
            'robots' => $indexable ? self::INDEX : self::NOINDEX,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'url' => $canonical ?? self::absolute($request->path()),
            'site_name' => $site,
            'app_title' => $app,
            'html_lang' => $lang,
            'og_locale' => (string) config('seo.og_locale', 'es_MX'),
            'image' => $image,
            'image_width' => 1200,
            'image_height' => 630,
            'image_alt' => "{$app} — logo de {$site}",
            'heading' => $fill($page['heading'] ?? null),
            'intro' => $fill($page['intro'] ?? null),
            'google_site_verification' => trim((string) config('seo.google_site_verification')) ?: null,
            'json_ld' => $indexable ? self::jsonLd($title, $description, (string) $canonical, $logo, $image, $lang) : null,
        ];
    }

    /** JSON-LD (Organization + WebSite + WebPage), seguro para incrustar en <script>. */
    public static function jsonLd(string $title, string $description, string $url, string $logo, string $image, string $lang): string
    {
        $base = self::baseUrl();
        $org = $base . '/#organization';
        $site = $base . '/#website';

        $graph = [
            // Clave '@context' armada en PHP (no en Blade): Blade confunde un
            // '@context' literal con su propia directiva y rompe la plantilla.
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $org,
                    'name' => self::siteName(),
                    'url' => $base . '/',
                    'logo' => ['@type' => 'ImageObject', 'url' => $logo, 'width' => 256, 'height' => 256],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $site,
                    'name' => self::appTitle(),
                    'alternateName' => ['Reportes MrLana', self::siteName() . ' Reportes', 'Sistema de reportes ' . self::siteName()],
                    'url' => $base . '/',
                    'inLanguage' => $lang,
                    'publisher' => ['@id' => $org],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $url . '#webpage',
                    'name' => $title,
                    'description' => $description,
                    'url' => $url,
                    'inLanguage' => $lang,
                    'isPartOf' => ['@id' => $site],
                    'about' => ['@id' => $org],
                    'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => $image, 'width' => 1200, 'height' => 630],
                ],
            ],
        ];

        return (string) json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
