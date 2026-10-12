<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fase 8-11 — Módulo web independiente "Efectividad de Cobranza" (nunca
 * escondido dentro de OKR ni solo dentro del histórico). Conecta el
 * EfectividadCobranzaAprobadaReportService (adaptador real + calculador
 * aprobado, ya probados) con una pantalla real: seleccionar periodo/sucursal,
 * generar en cola, ver progreso real, descargar Excel, consultar historial.
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

        // Evita encolar dos corridas concurrentes de la MISMA identidad —
        // si ya hay una queued/running, se reutiliza en vez de duplicar.
        $existing = EfectividadCobranzaRun::query()
            ->where('period_id', $period->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($existing) {
            return response()->json(['run_id' => $existing->id, 'status' => $existing->status]);
        }

        $run = EfectividadCobranzaRun::query()->create([
            'period_id'  => $period->id,
            'branch_id'  => $branchId,
            'status'     => 'queued',
            'queued_at'  => now(),
            'log'        => 'En cola para generarse…',
            'metadata'   => ['progress_percent' => 0, 'current_step' => 'En cola'],
            'created_by' => $request->user()?->id,
        ]);

        GenerateEfectividadCobranzaAprobadaJob::dispatch($run->id);

        return response()->json(['run_id' => $run->id, 'status' => $run->status]);
    }

    public function progress(EfectividadCobranzaRun $run): JsonResponse
    {
        return response()->json($this->serializeRun($run));
    }

    public function download(EfectividadCobranzaRun $run): RedirectResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if ($run->status !== 'success' || !$run->output_excel_path || !file_exists($run->output_excel_path)) {
            abort(404, 'El archivo Excel de este reporte no está disponible.');
        }

        return response()->download($run->output_excel_path, basename($run->output_excel_path), [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    private function serializeRun(EfectividadCobranzaRun $run): array
    {
        return [
            'id'           => $run->id,
            'period_id'    => $run->period_id,
            'branch_id'    => $run->branch_id,
            'status'       => $run->status,
            'log'          => $run->log,
            'metadata'     => $run->metadata,
            'coverage'     => $run->coverage,
            'rows'         => $run->status === 'success' ? ($run->result_rows ?? []) : [],
            'has_excel'    => $run->status === 'success' && (bool) $run->output_excel_path && file_exists((string) $run->output_excel_path),
            'error_message' => $run->error_message,
            'created_at'   => $run->created_at?->toISOString(),
            'finished_at'  => $run->finished_at?->toISOString(),
        ];
    }
}
