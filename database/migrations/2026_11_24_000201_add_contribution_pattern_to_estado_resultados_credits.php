<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detección de aportaciones y pagos a acreedores (24-nov-2026). ADITIVA.
 *
 *  - credits.contribution_pattern: regex (sobre texto normalizado: mayúsculas, sin acentos) del
 *    ABONO con que una sucursal aporta para el servicio de deuda («DEPOSITO LEROM» $4,095).
 *  - credits.bank_pattern: se AMPLÍA el patrón del CARGO de pago al acreedor, sólo si seguía en
 *    el valor sembrado original (nunca pisa un patrón editado a mano). Columna a 255.
 */
return new class extends Migration
{
    private const PATTERNS = [
        'lerom' => [
            'old' => 'CREDITO\s+LEROM',
            'bank' => '(PAGO|CREDITO|ABONO)\s+(A\s+)?(CREDITO\s+)?LEROM|LEROM\s+(PAGO|CREDITO)',
            'contribution' => '\bLEROM\b',
        ],
        'bbva' => [
            'old' => 'CREDITO\s+BBVA',
            'bank' => 'PAGO\s+(DE\s+)?CREDITO\s+BBVA|CREDITO\s+BBVA|AMORTIZACION\s+(DE\s+)?CREDITO',
            'contribution' => '\bBBVA\b.*CREDITO|CREDITO\s+BBVA',
        ],
    ];

    public function up(): void
    {
        Schema::table('estado_resultados_credits', function (Blueprint $table) {
            if (! Schema::hasColumn('estado_resultados_credits', 'contribution_pattern')) {
                $table->string('contribution_pattern', 255)->nullable()->after('bank_pattern');
            }
            $table->string('bank_pattern', 255)->nullable()->change();
        });

        foreach (self::PATTERNS as $key => $p) {
            DB::table('estado_resultados_credits')->where('key', $key)->where('bank_pattern', $p['old'])
                ->update(['bank_pattern' => $p['bank'], 'updated_at' => now()]);
            DB::table('estado_resultados_credits')->where('key', $key)->whereNull('contribution_pattern')
                ->update(['contribution_pattern' => $p['contribution'], 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::PATTERNS as $key => $p) {
            DB::table('estado_resultados_credits')->where('key', $key)->where('bank_pattern', $p['bank'])->update(['bank_pattern' => $p['old']]);
        }
        Schema::table('estado_resultados_credits', function (Blueprint $table) {
            $table->dropColumn('contribution_pattern');
        });
    }
};
