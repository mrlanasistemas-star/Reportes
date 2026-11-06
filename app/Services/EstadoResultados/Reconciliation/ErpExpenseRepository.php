<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Services\EstadoResultados\Support\FolioNormalizer;
use App\Services\EstadoResultados\Support\Money;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de SÓLO LECTURA del ERP de gastos (fact_expenses de la fuente gastos_erp),
 * con el MISMO universo e importe que usa Radiografía:
 *   - period_id ∈ dataIds del periodo,
 *   - importe = COALESCE(NULLIF(paid_amount,0), amount),
 *   - sucursal real vía BranchRadiographyCalculator::buildBranchMap() (método público,
 *     consumido sin cambiar su semántica).
 *
 * Cada fila se etiqueta con su "universo":
 *   radiografia  → sucursal operativa: YA está dentro del EBITDA de sucursales.
 *   corporativo  → CORPORATIVO: Radiografía la excluye por diseño; candidata a OPEX corporativo.
 *   sin_sucursal / no_operativa → fuera de Radiografía; requiere revisión en el ER.
 */
class ErpExpenseRepository
{
    public function __construct(private readonly BranchRadiographyCalculator $branchCalculator) {}

    /** @return list<array<string,mixed>> */
    public function forDataIds(array $dataIds): array
    {
        $sourceId = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        if (! $sourceId || $dataIds === []) {
            return [];
        }

        $maps = $this->branchCalculator->buildBranchMap();
        $operative = $maps['operative'];
        $corporativo = array_flip($maps['corporativo']);
        $branchNames = DB::table('branches')->pluck('name', 'id')->all();

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $sourceId)
            ->orderBy('e.id')
            ->get(['e.id', 'e.period_id', 'e.branch_id', 'e.category', 'e.concept', 'e.amount', 'e.paid_amount', 'e.expense_date', 'e.observations', 'e.raw_payload']);

        $out = [];
        $occurrence = [];
        foreach ($rows as $r) {
            $payload = is_string($r->raw_payload) ? (json_decode($r->raw_payload, true) ?: []) : (array) $r->raw_payload;
            $amount = (float) $r->paid_amount != 0.0 ? $r->paid_amount : $r->amount;
            $folio = FolioNormalizer::normalize($payload['folio'] ?? null);
            $fechaPago = $payload['fecha_pago'] ?? null;
            $fechaCaptura = $payload['fecha_captura'] ?? null;
            $primary = $fechaPago ?: ($fechaCaptura ?: ($r->expense_date ? substr((string) $r->expense_date, 0, 10) : null));

            $branchId = $r->branch_id !== null ? (int) $r->branch_id : null;
            $universe = match (true) {
                $branchId === null => 'sin_sucursal',
                isset($operative[$branchId]) => 'radiografia',
                isset($corporativo[$branchId]) => 'corporativo',
                default => 'no_operativa',
            };

            $key = implode('|', [$folio ?? '', Money::cents($amount), $fechaPago ?? '', $fechaCaptura ?? '', mb_strtoupper((string) $r->concept)]);
            $occurrence[$key] = ($occurrence[$key] ?? 0) + 1;

            $out[] = [
                'id' => (int) $r->id,
                // Huella estable entre re-importaciones del ERP (los id cambian al reimportar).
                'fingerprint' => 'erp:'.sha1($key.'|#'.$occurrence[$key]),
                'folio' => $payload['folio'] ?? null,
                'folio_normalized' => $folio,
                'amount' => Money::str2($amount),
                'fecha_pago' => $fechaPago,
                'fecha_captura' => $fechaCaptura,
                'date' => $primary,
                'date_source' => $fechaPago ? 'fecha_pago' : ($fechaCaptura ? 'fecha_captura' : 'expense_date'),
                'branch_id' => $branchId,
                'branch_name' => $universe === 'radiografia' ? $operative[$branchId] : ($branchId !== null ? ($branchNames[$branchId] ?? null) : null),
                'universe' => $universe,
                'category' => $r->category,
                'concept' => $r->concept,
                'observations' => $r->observations,
                'status_raw' => $payload['estatus'] ?? null,
                'sucursal_raw' => $payload['sucursal_raw'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Folios normalizados que existen en el ERP FUERA de los dataIds del periodo
     * (para avisar "folio registrado en ERP de otro periodo" en lugar de tratarlo como nuevo).
     *
     * @param  list<string>  $folios
     * @return array<string, list<array{period_code:string, amount:string, date:?string}>>
     */
    public function foliosOutside(array $dataIds, array $folios): array
    {
        $folios = array_values(array_unique(array_filter($folios)));
        $sourceId = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        if (! $sourceId || $folios === []) {
            return [];
        }
        $wanted = array_flip($folios);
        $out = [];
        DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('periods as p', 'p.id', '=', 'e.period_id')
            ->where('ru.data_source_id', $sourceId)
            ->whereNotIn('e.period_id', $dataIds)
            ->select(['e.id', 'e.amount', 'e.paid_amount', 'e.raw_payload', 'p.code'])
            ->orderBy('e.id')
            ->chunk(1000, function ($chunk) use (&$out, $wanted) {
                foreach ($chunk as $r) {
                    $payload = is_string($r->raw_payload) ? (json_decode($r->raw_payload, true) ?: []) : (array) $r->raw_payload;
                    $folio = FolioNormalizer::normalize($payload['folio'] ?? null);
                    if ($folio !== null && isset($wanted[$folio])) {
                        $out[$folio][] = [
                            'period_code' => (string) $r->code,
                            'amount' => Money::str2((float) $r->paid_amount != 0.0 ? $r->paid_amount : $r->amount),
                            'date' => $payload['fecha_pago'] ?? ($payload['fecha_captura'] ?? null),
                        ];
                    }
                }
            });

        return $out;
    }

    /** Firma del ERP del periodo: cambia si el ERP se re-importa con otro contenido. */
    public static function signature(array $erpRows): string
    {
        $fps = array_column($erpRows, 'fingerprint');
        sort($fps);

        return sha1(implode(',', $fps));
    }
}
