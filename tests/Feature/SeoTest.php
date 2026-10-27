<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SEO técnico (27-oct-2026): solo login/recuperar contraseña son indexables, con
 * metas renderizadas en el servidor; todo lo demás lleva noindex (meta + cabecera).
 * Ver config/seo.php, App\Support\Seo y docs/seo.md.
 */
beforeEach(function () {
    config(['app.url' => 'https://reportes.example.com', 'app.name' => 'Reportes']);
});

it('renders the login page with server-side SEO metas and index, follow', function () {
    $response = $this->get('/login');

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow');

    $html = $response->getContent();

    // Un solo <title>, con la marca, gestionado por Inertia (data-inertia).
    expect(substr_count($html, '<title'))->toBe(1)
        ->and($html)->toContain('<title data-inertia="">Reportes Mr Lana — Iniciar sesión</title>')
        ->toContain('<html lang="es-MX"')
        ->toContain('<meta name="robots" content="index, follow">')
        ->toContain('<meta name="description" content="Reportes Mr Lana es el sistema interno de reportes financieros de Mr Lana')
        ->toContain('<link rel="canonical" href="https://reportes.example.com/login">')
        ->toContain('<meta property="og:title" content="Reportes Mr Lana — Iniciar sesión">')
        ->toContain('<meta property="og:site_name" content="Mr Lana">')
        ->toContain('<meta property="og:url" content="https://reportes.example.com/login">')
        ->toContain('<meta property="og:image" content="https://reportes.example.com/og-image.png')
        ->toContain('<meta property="og:image:width" content="1200">')
        ->toContain('<meta property="og:image:height" content="630">')
        ->toContain('<meta property="og:locale" content="es_MX">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<h1>Reportes Mr Lana</h1>')
        ->toContain('alt="Logo Mr Lana" width="64" height="64"')
        ->not->toContain('noindex');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $ld = json_decode($m[1] ?? '', true);

    expect($ld)->toBeArray()
        ->and($ld['@context'])->toBe('https://schema.org');

    $types = collect($ld['@graph'])->keyBy('@type');

    expect($types['Organization']['name'])->toBe('Mr Lana')
        ->and($types['Organization']['url'])->toBe('https://reportes.example.com/')
        ->and($types['Organization']['logo']['url'])->toBe('https://reportes.example.com/logoMrLana.png')
        ->and($types['WebSite']['name'])->toBe('Reportes Mr Lana')
        ->and($types['WebPage']['url'])->toBe('https://reportes.example.com/login');
});

it('makes the forgot password page indexable too', function () {
    $html = $this->get('/forgot-password')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'index, follow')
        ->getContent();

    expect($html)->toContain('<title data-inertia="">Recuperar contraseña — Reportes Mr Lana</title>')
        ->toContain('<link rel="canonical" href="https://reportes.example.com/forgot-password">');
});

it('prints the Google Search Console verification meta only when configured', function () {
    expect($this->get('/login')->getContent())->not->toContain('google-site-verification');

    config(['seo.google_site_verification' => 'abc123XYZ']);

    expect($this->get('/login')->getContent())
        ->toContain('<meta name="google-site-verification" content="abc123XYZ">');
});

it('marks authenticated pages as noindex in the HTML and the header', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/guia-sistema');

    $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $html = $response->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->not->toContain('rel="canonical"')
        ->not->toContain('application/ld+json')
        ->and(substr_count($html, '<title'))->toBe(1);
});

it('sends noindex on redirects of private pages for guests', function () {
    $this->get('/dashboard')
        ->assertRedirect(route('login'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('sends noindex on the API', function () {
    $this->getJson('/api/v1/health')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->getJson('/api/v1/branches')->assertUnauthorized()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('serves robots.txt allowing only public pages, with an absolute sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk()->assertHeaderMissing('X-Robots-Tag');
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');

    $body = $response->getContent();

    expect($body)->toContain("User-agent: *\n")
        ->toContain("Allow: /login\n")
        ->toContain("Allow: /forgot-password\n")
        ->toContain("Allow: /build/\n")
        ->toContain("Disallow: /dashboard\n")
        ->toContain("Disallow: /api/\n")
        ->toContain("Disallow: /storage/\n")
        ->toContain("Disallow: /documentacion-api\n")
        ->toContain("Disallow: /\n")
        ->toContain('Sitemap: https://reportes.example.com/sitemap.xml')
        ->not->toContain('localhost');
});

it('serves a valid sitemap.xml with only the public URLs', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()->assertHeaderMissing('X-Robots-Tag');
    expect($response->headers->get('Content-Type'))->toStartWith('application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();

    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $locs = array_map('strval', $xml->xpath('//s:url/s:loc'));
    $lastmods = array_map('strval', $xml->xpath('//s:url/s:lastmod'));

    expect($locs)->toBe([
        'https://reportes.example.com/login',
        'https://reportes.example.com/forgot-password',
    ])
        ->and($lastmods)->toHaveCount(2)
        ->and($lastmods[0])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});
