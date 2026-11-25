<?php

use App\Services\EstadoResultados\Classification\ConceptCatalogDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Reglas iniciales del catálogo de conceptos (25-nov-2026): comisiones bancarias, prueba SPEI,
 * dispersión grupal y errores de depósito (ver ConceptCatalogDefaults). Idempotente: no duplica
 * reglas que ya existan (ni revive las que el usuario borró).
 *
 * En pruebas automatizadas no se insertan: cada prueba arranca con el catálogo vacío y, si las
 * necesita, llama a ConceptCatalogDefaults::install(). Nunca rompe `migrate`: un error queda en el log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        try {
            $created = ConceptCatalogDefaults::install();
            Log::info('estado_resultados.catalogo_inicial', ['reglas_creadas' => $created]);
        } catch (Throwable $e) {
            Log::warning('estado_resultados.catalogo_inicial_fallo', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // Las reglas quedan en el catálogo (el usuario pudo editarlas); se desactivan a mano.
    }
};
