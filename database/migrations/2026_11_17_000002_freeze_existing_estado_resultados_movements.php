<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los periodos que ya están en producción NO cambian con el reparto por fecha (17-nov-2026):
 * todo movimiento cargado antes de este cambio queda como "legado" (congelado en su periodo,
 * con el mismo in_period que ya tenía) y esos periodos no reciben movimientos de archivos
 * subidos en otro periodo. El reparto por fecha aplica sólo a lo que se suba de aquí en adelante.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('estado_resultados_movements')->update(['placement' => 'legado']);
    }

    public function down(): void
    {
        DB::table('estado_resultados_movements')->where('placement', 'legado')->update(['placement' => 'periodo']);
    }
};
