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

    /**
     * Marca de empresa: "Mr Lana".
     *
     * @return string Nombre de la empresa (config seo.site_name).
     */
    public static function siteName(): string
    {
        return (string) config('seo.site_name', 'Mr Lana');
    }

    /**
     * Usa seo.app_title si está configurado; si no, arma "<app.name> <marca>" solo
     * cuando app.name no contiene ya la marca (comparación solo con letras).
     *
     * Nombre del producto en buscadores; siempre contiene la marca ("Reportes Mr Lana").
     *
     * @return string Título del producto para buscadores.
     */
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

    /**
     * Raíz absoluta desde APP_URL (nunca del Host de la petición).
     *
     * @return string APP_URL sin diagonal final.
     */
    public static function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * Convierte una ruta relativa en URL absoluta sobre APP_URL.
     *
     * @param  string  $path  Ruta relativa (con o sin diagonales).
     * @return string URL absoluta.
     */
    public static function absolute(string $path): string
    {
        $path = trim($path, '/');

        return self::baseUrl().'/'.$path;
    }

    /**
     * Filtra config('seo.public_pages') dejando solo las páginas cuya ruta nombrada existe.
     *
     * @return array<string, array<string, string>> páginas públicas cuyas rutas existen
     */
    public static function publicPages(): array
    {
        return collect((array) config('seo.public_pages', []))
            ->filter(fn ($page, $name) => Route::has($name))
            ->all();
    }

    /**
     * Nombre de la ruta resuelta de la petición, si existe.
     *
     * @param  Request  $request  Petición actual.
     * @return string|null Nombre de la ruta o null.
     */
    protected static function routeName(Request $request): ?string
    {
        $route = $request->route();

        return is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
    }

    /**
     * Indica si la página puede indexarse: ruta nombrada incluida en las páginas
     * públicas y método GET. Todo lo demás (sistema interno) es noindex.
     *
     * @param  Request  $request  Petición actual.
     * @return bool true si la página es pública e indexable.
     */
    public static function isIndexable(Request $request): bool
    {
        $name = self::routeName($request);

        return $name !== null
            && array_key_exists($name, self::publicPages())
            && $request->isMethod('GET');
    }

    /**
     * Las rutas en seo.header_exempt_routes no reciben cabecera.
     *
     * Valor de X-Robots-Tag para la respuesta, o null si no debe llevar cabecera.
     *
     * @param  Request  $request  Petición actual.
     * @return string|null 'index, follow', 'noindex, nofollow' o null.
     */
    public static function robotsHeaderFor(Request $request): ?string
    {
        $name = self::routeName($request);

        if ($name !== null && in_array($name, (array) config('seo.header_exempt_routes', []), true)) {
            return null;
        }

        return self::isIndexable($request) ? self::INDEX : self::NOINDEX;
    }

    /**
     * Arma título, descripción, canonical (solo si es indexable), Open Graph y
     * JSON-LD sustituyendo los marcadores {app} y {site} de config/seo.php.
     *
     * Datos SEO de la petición actual para el root view (app.blade.php).
     *
     * @param  Request  $request  Petición actual.
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

    /**
     * Usa JSON_HEX_TAG/JSON_HEX_AMP para que el JSON no pueda cerrar la etiqueta <script>.
     *
     * JSON-LD (Organization + WebSite + WebPage), seguro para incrustar en <script>.
     *
     * @param  string  $title  Título de la página.
     * @param  string  $description  Descripción de la página.
     * @param  string  $url  URL canónica.
     * @param  string  $logo  URL absoluta del logo.
     * @param  string  $image  URL absoluta de la imagen Open Graph.
     * @param  string  $lang  Idioma (p. ej. es-MX).
     * @return string JSON listo para incrustar.
     */
    public static function jsonLd(string $title, string $description, string $url, string $logo, string $image, string $lang): string
    {
        $base = self::baseUrl();
        $org = $base.'/#organization';
        $site = $base.'/#website';

        $graph = [
            // Clave '@context' armada en PHP (no en Blade): Blade confunde un
            // '@context' literal con su propia directiva y rompe la plantilla.
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $org,
                    'name' => self::siteName(),
                    'url' => $base.'/',
                    'logo' => ['@type' => 'ImageObject', 'url' => $logo, 'width' => 256, 'height' => 256],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $site,
                    'name' => self::appTitle(),
                    'alternateName' => ['Reportes MrLana', self::siteName().' Reportes', 'Sistema de reportes '.self::siteName()],
                    'url' => $base.'/',
                    'inLanguage' => $lang,
                    'publisher' => ['@id' => $org],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $url.'#webpage',
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
