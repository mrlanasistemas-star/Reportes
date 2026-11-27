<?php

namespace App\Services\People;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Plantilla de personal de MR LANA PEOPLE (fuente de verdad de puesto, sucursal
 * y bajas) por su API de integración servidor a servidor, contrato
 * `people-integracion/1` (People: docs/API_INTEGRACION_SISTEMAS.md):
 *
 *   GET {PEOPLE_API_URL}/colaboradores?estatus=todos&por_pagina=1000[&cursor=]
 *   Authorization: Bearer ppl_int_…   (cliente «reportes», perfil «reportes»)
 *
 * Se siguen `meta.siguiente_cursor` mientras `meta.hay_mas`. Solo lectura y sin
 * datos sensibles (nunca se pide `incluir=`).
 *
 * - Caché `services.people.cache_minutes` (30 min por defecto).
 * - Si People no responde se usa la ÚLTIMA copia buena (`stale: true`); si
 *   nunca hubo una, `connected: false` y la pantalla sigue con Reportería.
 */
class PeopleRosterClient
{
    private const CACHE_KEY = 'people:roster:v2';

    private const LAST_GOOD_KEY = 'people:roster:last-good:v2';

    private const PER_PAGE = 1000;

    private const MAX_PAGES = 50;

    public function configured(): bool
    {
        return filled(config('services.people.token')) && filled(config('services.people.url'));
    }

    /**
     * @return array{connected:bool, stale:bool, synced_at:?string, error:?string, people:list<array>}
     */
    public function roster(bool $fresh = false): array
    {
        if (! $this->configured()) {
            return ['connected' => false, 'stale' => false, 'synced_at' => null, 'error' => 'not_configured', 'people' => []];
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $people = $this->fetchAll();
            $result = ['connected' => true, 'stale' => false, 'synced_at' => now()->toIso8601String(), 'error' => null, 'people' => $people];
            Cache::put(self::CACHE_KEY, $result, now()->addMinutes(max(1, (int) config('services.people.cache_minutes', 30))));
            Cache::forever(self::LAST_GOOD_KEY, $result);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('People: no se pudo leer la plantilla', ['error' => $e->getMessage()]);
            $last = Cache::get(self::LAST_GOOD_KEY);
            $result = is_array($last)
                ? ['stale' => true, 'error' => $e->getMessage()] + $last
                : ['connected' => false, 'stale' => false, 'synced_at' => null, 'error' => $e->getMessage(), 'people' => []];
            // Reintento en 5 min, no en cada petición.
            Cache::put(self::CACHE_KEY, $result, now()->addMinutes(5));

            return $result;
        }
    }

    /** @return list<array> */
    private function fetchAll(): array
    {
        $people = [];
        $cursor = null;
        $page = 0;

        do {
            $query = ['estatus' => 'todos', 'por_pagina' => self::PER_PAGE] + ($cursor ? ['cursor' => $cursor] : []);
            $resp = $this->http()->get('/colaboradores', $query);

            if ($resp->failed()) {
                $msg = $resp->json('message') ?? $resp->json('codigo') ?? 'HTTP '.$resp->status();
                throw new \RuntimeException("People respondió {$resp->status()}: {$msg}");
            }

            foreach ((array) $resp->json('data', []) as $raw) {
                if (is_array($raw) && ($person = $this->normalize($raw)) !== null) {
                    $people[] = $person;
                }
            }

            $cursor = $resp->json('meta.hay_mas') ? $resp->json('meta.siguiente_cursor') : null;
            $page++;
        } while ($cursor && $page < self::MAX_PAGES);

        return $people;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.people.url'), '/'))
            ->withToken((string) config('services.people.token'))
            ->acceptJson()
            ->timeout(max(5, (int) config('services.people.timeout', 20)))
            ->retry(2, 500, throw: false);
    }

    private function normalize(array $r): ?array
    {
        $name = $this->str($r['nombre_completo'] ?? trim(($r['nombres'] ?? '').' '.($r['apellidos'] ?? '')));

        if ($name === null) {
            return null;
        }

        $status = is_array($r['estatus'] ?? null) ? ($r['estatus']['valor'] ?? null) : ($r['estatus'] ?? null);
        $statusLabel = is_array($r['estatus'] ?? null) ? ($r['estatus']['etiqueta'] ?? null) : null;
        $active = array_key_exists('activo', $r) ? (bool) $r['activo'] : ! in_array(mb_strtolower((string) $status), ['inactivo', 'baja'], true);

        return [
            'id' => $this->str($r['id'] ?? null),
            'name' => $name,
            'number' => $this->str($r['numero_empleado'] ?? null),
            'noi' => $this->str($r['clave_noi'] ?? null),
            'position' => $this->str(is_array($r['puesto'] ?? null) ? ($r['puesto']['nombre'] ?? null) : ($r['puesto'] ?? null)),
            'branch' => $this->str(is_array($r['sucursal'] ?? null) ? ($r['sucursal']['nombre'] ?? null) : ($r['sucursal'] ?? null)),
            'branch_key' => is_array($r['sucursal'] ?? null) ? $this->str($r['sucursal']['clave'] ?? null) : null,
            'status' => $this->str($statusLabel ?? $status),
            'is_baja' => ! $active,
            'baja_date' => $this->str($r['fecha_baja'] ?? null),
        ];
    }

    private function str(mixed $v): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }

        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
