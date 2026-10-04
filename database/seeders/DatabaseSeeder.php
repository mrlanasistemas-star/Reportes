<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Parte 15 del cierre (04-oct-2026): faltaban BranchSeeder/OkrKpiSeeder aquí
 * — ambos idempotentes (firstOrCreate/updateOrCreate por clave natural), así
 * que correr este seeder varias veces nunca duplica catálogos. Nunca siembra
 * usuarios reales.
 */
class DatabaseSeeder extends Seeder {

    public function run(): void {
        $this->call([
            DataSourceSeeder::class,
            BranchSeeder::class,
            OkrKpiSeeder::class,
        ]);
    }

}
