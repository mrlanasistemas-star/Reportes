<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Efectividad V4 — integridad referencial de las tablas nuevas (aditiva).
 *
 * Las migraciones 2026_10_13_0900xx crearon las tablas solo con índices. En
 * InnoDB (VPS) se agregan las claves foráneas con borrado en cascada, que es
 * exactamente lo que el código ya hace a mano (LendusCarteraFotoImportService
 * borra filas → fotografía; EfectividadV4Incidents reemplaza la bitácora).
 *
 * Nunca borra datos: si una relación tiene filas huérfanas, esa clave se omite
 * (y se informa en consola) en vez de limpiar la tabla. SQLite (pruebas) y
 * tablas MyISAM no aplican claves foráneas: se omiten.
 */
return new class extends Migration
{
    private const FKS = [
        // [tabla, columna, tabla referida, nombre]
        ['fact_portfolio_snapshot_rows', 'portfolio_snapshot_id', 'portfolio_snapshots', 'fpsr_portfolio_snapshot_fk'],
        ['portfolio_snapshots', 'report_upload_id', 'report_uploads', 'ps_report_upload_fk'],
        ['efectividad_cobranza_run_incidents', 'efectividad_cobranza_run_id', 'efectividad_cobranza_runs', 'ecri_run_fk'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::FKS as [$tabla, $columna, $referida, $nombre]) {
            if (! Schema::hasTable($tabla) || ! Schema::hasTable($referida) || $this->existe($tabla, $nombre)) {
                continue;
            }
            if ($this->motor($tabla) !== 'INNODB' || $this->motor($referida) !== 'INNODB') {
                $this->aviso("{$tabla}.{$columna}: tabla no InnoDB, la clave foránea no se crea.");

                continue;
            }
            $huerfanas = DB::table($tabla)->whereNotIn($columna, DB::table($referida)->select('id'))->count();
            if ($huerfanas > 0) {
                $this->aviso("{$tabla}.{$columna}: {$huerfanas} fila(s) sin {$referida} — la clave foránea no se crea (no se borra nada).");

                continue;
            }
            Schema::table($tabla, fn (Blueprint $t) => $t->foreign($columna, $nombre)->references('id')->on($referida)->cascadeOnDelete());
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::FKS as [$tabla, , , $nombre]) {
            if (Schema::hasTable($tabla) && $this->existe($tabla, $nombre)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropForeign($nombre));
            }
        }
    }

    private function existe(string $tabla, string $nombre): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabla)
            ->where('CONSTRAINT_NAME', $nombre)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function motor(string $tabla): string
    {
        return strtoupper((string) DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabla)
            ->value('ENGINE'));
    }

    private function aviso(string $mensaje): void
    {
        if (app()->runningInConsole()) {
            fwrite(STDERR, "  [Efectividad V4] {$mensaje}\n");
        }
    }
};
