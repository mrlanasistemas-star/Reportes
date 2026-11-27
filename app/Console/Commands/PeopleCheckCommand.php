<?php

namespace App\Console\Commands;

use App\Services\People\PeopleMatcher;
use App\Services\People\PeopleRosterClient;
use Illuminate\Console\Command;

/**
 * Prueba la conexión con MR LANA PEOPLE y muestra cuántas personas trae, cuántas
 * son baja y los puestos/sucursales que reporta. Solo lectura.
 *
 *   php artisan people:check            (usa la caché si existe)
 *   php artisan people:check --fresh    (vuelve a pedir todo a People)
 */
class PeopleCheckCommand extends Command
{
    protected $signature = 'people:check {--fresh : Ignorar la caché y volver a consultar People}';

    protected $description = 'Prueba la conexión con MR LANA PEOPLE (puesto, sucursal y bajas para Datos duros)';

    public function handle(PeopleRosterClient $client): int
    {
        if (! $client->configured()) {
            $this->error('Falta PEOPLE_API_TOKEN en el .env (token ppl_int_ del cliente «reportes» de People).');

            return self::FAILURE;
        }

        $roster = $client->roster((bool) $this->option('fresh'));

        if (! $roster['connected']) {
            $this->error('Sin conexión con People: '.$roster['error']);

            return self::FAILURE;
        }

        $people = collect($roster['people']);
        $this->info(($roster['stale'] ? 'People no respondió; se usa la última copia buena. ' : 'Conectado con People. ')
            ."{$people->count()} personas · {$people->where('is_baja', true)->count()} bajas · actualizado {$roster['synced_at']}");

        $this->table(['Puesto', 'Personas'], $people->groupBy(fn ($p) => PeopleMatcher::position($p['position']) ?? 'Sin puesto')
            ->map->count()->sortDesc()->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->table(['Sucursal (como la verá Datos duros)', 'Personas'], $people->groupBy(fn ($p) => PeopleMatcher::officialBranch($p['branch']) ?? 'Sin sucursal')
            ->map->count()->sortDesc()->map(fn ($n, $k) => [$k, $n])->values()->all());

        return self::SUCCESS;
    }
}
