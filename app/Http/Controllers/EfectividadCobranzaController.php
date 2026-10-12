<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase 8-11 (sesión anterior) — Módulo web independiente "Efectividad de
 * Cobranza" (nunca escondido dentro de OKR ni solo dentro del histórico).
 * Conecta el EfectividadCobranzaAprobadaReportService (adaptador real +
 * calculador aprobado, ya probados) con una pantalla real: seleccionar
 * periodo/sucursal, generar en cola, ver progreso real, descargar Excel,
 * consultar historial.
 *
 * Auditoría 2026-10-12 (Fase 4/5/10):
 *  - `generate()` usaba un "check-then-create" sin protección — dos POST casi
 *    simultáneos podían pasar ambos la verificación antes de que cualquiera
 *    insertara, duplicando la fila y el Job. Ahora el bloque completo corre
 *    dentro de un `Cache::lock()` (atómico vía INSERT con clave única en la
 *    tabla `cache_locks` — funciona igual en MyISAM que en InnoDB, a
 *    diferencia de una transacción, que en este MySQL real NO es atómica
 *    porque el esquema usa MyISAM — ver docs/efectividad-cobranza-aprobada.md).
 *  - `download()`/`serializeRun()` ya no asumen una ruta absoluta de disco
 *    (`file_exists($run->output_excel_path)` fallaba al migrar de Windows a
 *    Linux) — `output_excel_path` ahora es una ruta relativa al disco privado
 *    `local`, reconstruida aquí con `Storage::disk('local')`.
 */
class EfectividadCobranzaController extends Controller
{
    public function index(Request $request): Response
    {
        $periodId = $request->integer('period_id') ?: null;
        $branchId = $request->integer('branch_id') ?: null;

        $periods = Period::query()
            ->orderByDesc('start_date')
            ->get(['id', 'code', 'name', 'type', 'year', 'month', 'start_date', 'end_date'])
            ->map(fn (Period $p) => [
                'id'    => $p->id,
                'label' => $p->label,
                'code'  => $p->code,
                'type'  => $p->type,
            ]);

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name]);

        $period = $periodId ? Period::query()->find($periodId) : null;

        $latestRun = $period
            ? EfectividadCobranzaRun::latestFor($period->id, $branchId)
            : null;

        $history = $period
            ? EfectividadCobranzaRun::query()
                ->where('period_id', $period->id)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
                ->latest('id')
                ->limit(10)
                ->get(['id', 'status', 'coverage', 'output_excel_path', 'created_at', 'finished_at'])
            : collect();

        return Inertia::render('EfectividadCobranza/Index', [
            'periods'    => $periods,
            'branches'   => $branches,
            'selected'   => ['period_id' => $periodId, 'branch_id' => $branchId],
            'latestRun'  => $latestRun ? $this->serializeRun($latestRun) : null,
            'history'    => $history->map(fn (EfectividadCobranzaRun $r) => [
                'id'            => $r->id,
                'status'        => $r->status,
                'coverage'      => $r->coverage,
                'has_excel'     => (bool) $r->output_excel_path,
                'created_at'    => $r->created_at?->toISOString(),
                'finished_at'   => $r->finished_at?->toISOString(),
            ]),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_id' => ['required', 'integer', 'exists:periods,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $period   = Period::query()->findOrFail($data['period_id']);
        $branchId = $data['branch_id'] ?? null;
        $userId   = $request->user()?->id;

        // Fase 4 (auditoría 2026-10-12) — "check-then-create" atómico: el lock
        // se adquiere ANTES de consultar si ya hay una corrida activa, y se
        // libera al salir del closure. Dos POST simultáneos para la MISMA
        // identidad (period_id+branch_id) ya no pueden crear dos filas/Jobs:
        // el segundo espera hasta 5s, y al entrar ya ve la fila que el
        // primero acaba de crear.
        $lockKey = "efectividad-cobranza-generate-{$period->id}-" . ($branchId ?? 'general');

        try {
            $payload = Cache::lock($lockKey, 15)->block(5, function () use ($period, $branchId, $userId) {
                $existing = EfectividadCobranzaRun::query()
                    ->where('period_id', $period->id)
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
                    ->whereIn('status', [EfectividadCobranzaRun::STATUS_QUEUED, EfectividadCobranzaRun::STATUS_RUNNING])
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return ['run_id' => $existing->id, 'status' => $existing->status, 'reused' => true];
                }

                $run = EfectividadCobranzaRun::query()->create([
                    'period_id'  => $period->id,
                    'branch_id'  => $branchId,
                    'status'     => EfectividadCobranzaRun::STATUS_QUEUED,
                    'queued_at'  => now(),
                    'log'        => 'En cola para generarse…',
                    'metadata'   => ['progress_percent' => 0, 'current_step' => 'En cola'],
                    'created_by' => $userId,
                ]);

                GenerateEfectividadCobranzaAprobadaJob::dispatch($run->id);

                return ['run_id' => $run->id, 'status' => $run->status, 'reused' => false];
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // No se pudo adquirir el lock en 5s — otra solicitud lo tiene. Nunca se
            // crea una fila "por si acaso"; se informa honestamente al cliente.
            return response()->json([
                'error' => 'Ya hay una solicitud de generación en curso para este periodo/sucursal. Intenta de nuevo en unos segundos.',
            ], 409);
        }

        return response()->json($payload);
    }

    public function progress(EfectividadCobranzaRun $run): JsonResponse
    {
        return response()->json($this->serializeRun($run));
    }

    public function download(EfectividadCobranzaRun $run): RedirectResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $absolutePath = $this->resolveExcelAbsolutePath($run);

        if ($run->status !== EfectividadCobranzaRun::STATUS_SUCCESS || !$absolutePath) {
            abort(404, 'El archivo Excel de este reporte no está disponible.');
        }

        return response()->download($absolutePath, basename($absolutePath), [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    private function serializeRun(EfectividadCobranzaRun $run): array
    {
        $isSuccess = $run->status === EfectividadCobranzaRun::STATUS_SUCCESS;

        return [
            'id'           => $run->id,
            'period_id'    => $run->period_id,
            'branch_id'    => $run->branch_id,
            'status'       => $run->status,
            'log'          => $run->log,
            'metadata'     => $run->metadata,
            'coverage'     => $run->coverage,
            'rows'         => $isSuccess ? ($run->result_rows ?? []) : [],
            'has_excel'    => $isSuccess && $this->resolveExcelAbsolutePath($run) !== null,
            'error_message' => $run->error_message,
            'created_at'   => $run->created_at?->toISOString(),
            'finished_at'  => $run->finished_at?->toISOString(),
        ];
    }

    /**
     * Fase 10 (auditoría 2026-10-12) — `output_excel_path` guarda una ruta
     * RELATIVA al disco privado `local` desde esta corrección en adelante
     * (reconstruida aquí con Storage::disk('local')->path(), nunca asumiendo
     * el path Windows/Linux de quien lo generó). Las filas generadas ANTES de
     * esta corrección guardaron una ruta ABSOLUTA (del servidor donde
     * corrieron entonces) — se siguen resolviendo tal cual, sin migrar ni
     * borrar esas filas, para no romper descargas de reportes ya generados.
     */
    private function resolveExcelAbsolutePath(EfectividadCobranzaRun $run): ?string
    {
        $stored = $run->output_excel_path;
        if (!$stored) {
            return null;
        }

        $isAbsolute = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $stored) === 1;

        if ($isAbsolute) {
            return file_exists($stored) ? $stored : null;
        }

        return Storage::disk('local')->exists($stored) ? Storage::disk('local')->path($stored) : null;
    }
}
