<?php

use App\Models\Branch;
use App\Models\OkrObjective;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * 4.4 (19-oct-2026) — TODA URL que el frontend OKR llama existe con el mismo
 * método. Se recorren los .vue/.ts del módulo (páginas, componentes,
 * composables y helpers) y cada '/okr…' escrito a mano se compara contra las
 * rutas registradas — así nunca vuelve a aparecer "The route okr/2/activation-check
 * could not be found" por una URL o un método desalineados.
 */
function okrFrontendFiles(): array
{
    $base = resource_path('js');
    $files = array_merge(
        File::allFiles($base . '/pages/Okr'),
        File::allFiles($base . '/components/okr'),
    );
    foreach (['lib/okrHttp.ts', 'lib/okrFormat.ts', 'composables/useOkrBaselinePreview.ts'] as $extra) {
        if (is_file($base . '/' . $extra)) {
            $files[] = new SplFileInfo($base . '/' . $extra);
        }
    }

    return array_values(array_filter($files, fn ($f) => in_array(pathinfo($f->getPathname(), PATHINFO_EXTENSION), ['vue', 'ts'], true)));
}

/** @return array<int, array{file: string, line: int, url: string, method: string}> */
function okrFrontendCalls(): array
{
    $calls = [];
    foreach (okrFrontendFiles() as $file) {
        $lines = preg_split('/\R/', File::get($file->getPathname()));
        foreach ($lines as $i => $line) {
            if (!preg_match_all('/[`\'"](\/okr(?:[\/?][^`\'"]*)?)[`\'"]/', $line, $m)) {
                continue;
            }
            $context = $line . ' ' . ($lines[$i + 1] ?? '') . ' ' . ($lines[$i + 2] ?? '');
            foreach ($m[1] as $raw) {
                $method = 'GET';
                if (preg_match('/(?:router|form|useForm\([^)]*\))\.(post|put|patch|delete)\s*\(/i', $line, $mm)) {
                    $method = strtoupper($mm[1]);
                } elseif (preg_match("/method:\s*'(POST|PUT|PATCH|DELETE)'/i", $context, $mm)) {
                    $method = strtoupper($mm[1]);
                } elseif (preg_match('/\.(post|put|patch|delete)\(\s*[`\'"]?$/i', trim(explode($raw, $line)[0] ?? ''), $mm)) {
                    $method = strtoupper($mm[1]);
                }
                $calls[] = ['file' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()), 'line' => $i + 1, 'url' => $raw, 'method' => $method];
            }
        }
    }

    return $calls;
}

/** Convierte '/okr/${props.objectiveId}/activation-check?x=1' en una URI comparable. */
function okrNormalizeUrl(string $url): string
{
    $path = explode('?', $url)[0];
    $path = preg_replace('/\$\{[^}]*\}/', '{p}', $path);

    return trim($path, '/');
}

function okrRouteMatches(string $normalized, string $method): bool
{
    $segments = explode('/', $normalized);
    /** @var LaravelRoute $route */
    foreach (Route::getRoutes() as $route) {
        if (!in_array($method, $route->methods(), true)) {
            continue;
        }
        $routeSegments = explode('/', trim($route->uri(), '/'));
        if (count($routeSegments) !== count($segments)) {
            continue;
        }
        $ok = true;
        foreach ($routeSegments as $k => $seg) {
            $isParam = str_starts_with($seg, '{');
            if ($segments[$k] === '{p}' ? !$isParam : ($isParam ? false : $seg !== $segments[$k])) {
                // Un literal del frontend nunca debe caer en un {param} (p. ej. '/okr/history'
                // contra '/okr/{objective}'): eso sería otra ruta distinta a la intención.
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return true;
        }
    }

    return false;
}

it('finds the OKR frontend calls it is supposed to check (sanity)', function () {
    $urls = array_column(okrFrontendCalls(), 'url');

    expect(count($urls))->toBeGreaterThan(25);
    expect(implode(' ', $urls))->toContain('/activation-check')->toContain('/warnings/eligibility')->toContain('/commitment-letter/review');
});

it('every /okr URL used by resources/js exists as a registered route with the same HTTP method', function () {
    $missing = [];
    foreach (okrFrontendCalls() as $call) {
        $normalized = okrNormalizeUrl($call['url']);
        if (!okrRouteMatches($normalized, $call['method'])) {
            $missing[] = "{$call['method']} /{$normalized}  ← {$call['file']}:{$call['line']}";
        }
    }

    expect($missing)->toBe([]);
});

it('the generated Wayfinder actions for OKR point to existing routes', function () {
    $dir = resource_path('js/actions/App/Http/Controllers/Okr');
    if (!is_dir($dir)) {
        $this->markTestSkipped('Sin acciones Wayfinder generadas.');
    }
    $missing = [];
    $checked = 0;
    foreach (File::files($dir) as $file) {
        preg_match_all('/methods:\s*\[([^\]]+)\],\s*url:\s*\'([^\']+)\'/', str_replace(["\r\n", "\n"], ' ', File::get($file->getPathname())), $m, PREG_SET_ORDER);
        foreach ($m as [, $methods, $uri]) {
            $method = strtoupper(trim(explode(',', $methods)[0], " \"'"));
            $normalized = trim(preg_replace('/\{[^}]+\}/', '{p}', $uri), '/');
            $checked++;
            if (!okrRouteMatches($normalized, $method)) {
                $missing[] = "{$method} {$uri} ← {$file->getFilename()}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(20);
    expect($missing)->toBe([]);
});

it('GET /okr/{id}/activation-check answers JSON for a real draft OKR (never "route could not be found")', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $branch = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Uno', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);
    $second = OkrObjective::query()->create([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Dos', 'responsible_user_id' => $admin->id, 'created_by' => $admin->id,
        'start_date' => now(), 'end_date' => now()->addWeeks(4), 'duration_weeks' => 4, 'lifecycle_status' => OkrObjective::STATUS_DRAFT,
    ]);

    $res = $this->actingAs($admin)->getJson("/okr/{$second->id}/activation-check")->assertOk();

    expect($res->json('objective.id'))->toBe($second->id);
    expect($res->json('objective.ready'))->toBeFalse();
    expect((string) $res->getContent())->not->toContain('could not be found');
});
