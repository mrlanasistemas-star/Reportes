<?php

namespace App\Services\EstadoResultados\Sources;

use App\Models\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico de SÓLO LECTURA de las fuentes que el Estado de Resultados toma de
 * «Carga de archivos» (report_uploads + data_sources + tablas de hechos), para decir
 * EXACTAMENTE por qué una fuente se ve como faltante y qué hacer.
 *
 * Por qué el ER puede no ver un archivo que sí se subió: en Carga de archivos subir el
 * archivo sólo lo GUARDA (report_uploads.status = pending); sus renglones llegan a
 * fact_expenses / fact_noi_movements hasta que se pulsa «Cargar registros» (job en cola).
 * El ER —igual que Radiografía— lee los renglones, no el archivo. Además, sustituir un
 * archivo borra los renglones del anterior hasta volver a «Cargar registros».
 *
 * Mismo universo que Radiografía: period_id ∈ dataIds (mes + semanas base).
 */
class UploadModuleInspector
{
    /** Fuentes de Carga de archivos que el ER usa directamente. */
    public const SOURCES = [
        'erp' => [
            'code' => 'gastos_erp',
            'label' => 'ERP de gastos',
            'fact' => 'fact_expenses',
            'affects' => 'Gastos corporativos (OPEX corporativo y nómina corporativa del ERP) y el cruce de pagos del banco contra requisiciones.',
        ],
        'nomina' => [
            'code' => 'noi_nomina',
            'label' => 'Nómina NOI de sucursales',
            'fact' => 'fact_noi_movements',
            // El ER cuenta TODOS los movimientos NOI del universo (NOI y NOI fiscal), igual que Radiografía.
            'count_all' => true,
            'affects' => 'Nómina de sucursales (vía Radiografía) y la detección de pagos de nómina del banco que ya están contados.',
        ],
    ];

    public const STATE_LISTA = 'lista';

    public const STATE_FALTA = 'falta';

    public const STATE_PROBLEMAS = 'con_problemas';

    /** @var array<int, array<string, array>> */
    private array $memo = [];

    /**
     * @param  list<int>  $dataIds
     * @return array<string, array<string, mixed>> clave → diagnóstico
     */
    public function inspect(Period $period, array $dataIds): array
    {
        $memoKey = $period->id.':'.implode(',', $dataIds);
        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }
        $out = [];
        foreach (self::SOURCES as $key => $def) {
            $out[$key] = $this->inspectSource($period, $dataIds, $key, $def);
        }

        return $this->memo[$memoKey] = $out;
    }

    private function inspectSource(Period $period, array $dataIds, string $key, array $def): array
    {
        $label = (string) $period->label;
        $source = DB::table('data_sources')->where('code', $def['code'])->first(['id', 'name']);
        $base = [
            'key' => $key, 'code' => $def['code'], 'label' => $def['label'],
            'module_label' => $source?->name ?? $def['label'],
            'affects' => $def['affects'], 'period_label' => $label,
            'uploads' => [], 'fact_rows' => 0, 'run' => null, 'elsewhere' => [],
        ];
        if (! $source) {
            return $base + $this->verdict(self::STATE_FALTA, 'fuente_no_configurada',
                'La fuente «'.$def['label'].'» no está dada de alta en Carga de archivos.',
                'Pide a un administrador que active la fuente '.$def['code'].' en Carga de archivos.');
        }

        $uploads = $dataIds === [] ? collect() : DB::table('report_uploads as ru')
            ->leftJoin('periods as p', 'p.id', '=', 'ru.period_id')
            ->leftJoin('users as u', 'u.id', '=', 'ru.uploaded_by')
            ->where('ru.data_source_id', $source->id)
            ->whereIn('ru.period_id', $dataIds)
            ->orderByDesc('ru.id')
            ->get(['ru.id', 'ru.period_id', 'ru.original_name', 'ru.status', 'ru.uploaded_at', 'ru.created_at', 'ru.updated_at', 'u.name as uploader']);
        $rowsByUpload = $uploads->isEmpty() || ! Schema::hasTable($def['fact']) ? [] : DB::table($def['fact'])
            ->whereIn('report_upload_id', $uploads->pluck('id')->all())
            ->whereIn('period_id', $dataIds)
            ->groupBy('report_upload_id')->selectRaw('report_upload_id, COUNT(*) as c')->pluck('c', 'report_upload_id')->all();
        // MISMO conteo que usa el ER (EstadoResultadosDatasetBuilder::sourcesShared): renglones de la fuente en el universo.
        $factRows = match (true) {
            $dataIds === [] || ! Schema::hasTable($def['fact']) => 0,
            ! empty($def['count_all']) => (int) DB::table($def['fact'])->whereIn('period_id', $dataIds)->count(),
            default => (int) DB::table($def['fact'].' as f')->join('report_uploads as r', 'r.id', '=', 'f.report_upload_id')
                ->whereIn('f.period_id', $dataIds)->where('r.data_source_id', $source->id)->count(),
        };
        $run = Schema::hasTable('period_database_update_runs') ? DB::table('period_database_update_runs')
            ->where('period_id', $period->id)->latest('id')->first(['id', 'status', 'error_message', 'queued_at', 'started_at', 'finished_at', 'created_at']) : null;

        $base['uploads'] = $uploads->map(fn ($u) => [
            'id' => (int) $u->id, 'period_id' => (int) $u->period_id, 'name' => (string) $u->original_name,
            'status' => (string) $u->status, 'status_label' => self::STATUS_LABELS[(string) $u->status] ?? (string) $u->status,
            'rows' => (int) ($rowsByUpload[$u->id] ?? 0), 'uploaded_by' => $u->uploader,
            'uploaded_at' => self::fmt($u->uploaded_at ?? $u->created_at),
        ])->values()->all();
        $base['fact_rows'] = $factRows;
        $base['run'] = $run ? [
            'id' => (int) $run->id, 'status' => (string) $run->status, 'error' => $run->error_message ? mb_substr((string) $run->error_message, 0, 300) : null,
            'at' => self::fmt($run->finished_at ?? $run->started_at ?? $run->queued_at ?? $run->created_at),
        ] : null;
        $base['elsewhere'] = $this->elsewhere($period, $source->id, $dataIds);

        $active = array_values(array_filter($base['uploads'], fn ($u) => $u['status'] !== 'replaced'));
        $current = $active[0] ?? null;
        $file = $current ? '«'.$current['name'].'»' : null;
        $runActive = $run && in_array($run->status, ['queued', 'running'], true);

        if ($factRows > 0) {
            $pendingNewer = array_values(array_filter($active, fn ($u) => in_array($u['status'], ['pending', 'processing'], true) && $u['rows'] === 0));
            if ($pendingNewer !== []) {
                return $base + $this->verdict(self::STATE_PROBLEMAS, 'archivo_nuevo_sin_cargar',
                    'Hay '.$factRows.' registros cargados, pero el archivo «'.$pendingNewer[0]['name'].'» de '.$label.' aún no se carga a la base de datos.',
                    'En Carga de archivos → '.$label.', pulsa «Cargar registros» para incorporar el archivo nuevo.');
            }

            return $base + $this->verdict(self::STATE_LISTA, 'lista',
                number_format($factRows).' registros de '.$label.($file ? ' (archivo '.$file.')' : '').', tomados de Carga de archivos.', null);
        }

        $elsewhereHint = $base['elsewhere'] !== []
            ? ' Hay un archivo de esta fuente en '.implode(', ', array_unique(array_column($base['elsewhere'], 'period_label'))).' que cubre semanas de '.$label.': verifica que se subió en el mes correcto.'
            : '';

        if (! $current) {
            $replaced = count($base['uploads']) > 0;

            return $base + $this->verdict(self::STATE_FALTA, $replaced ? 'archivo_eliminado' : 'sin_archivo',
                ($replaced ? 'El archivo de '.$def['label'].' de '.$label.' se sustituyó o eliminó y no hay uno vigente.' : 'No se ha subido el '.$def['label'].' de '.$label.' en Carga de archivos.').$elsewhereHint,
                'Entra a Carga de archivos, elige '.$label.', sube el archivo en «'.$base['module_label'].'» y pulsa «Cargar registros».');
        }

        return $base + match (true) {
            $runActive || $current['status'] === 'processing' => $this->verdict(self::STATE_PROBLEMAS, 'carga_en_proceso',
                'El archivo '.$file.' ya está subido y la carga de registros de '.$label.' está en proceso'.($run ? ' (desde '.$base['run']['at'].')' : '').'.',
                'Espera a que termine la carga de registros en Carga de archivos y recarga esta página. Si se queda detenida, revisa el proceso en Carga de archivos → '.$label.'.'),
            $current['status'] === 'failed' => $this->verdict(self::STATE_PROBLEMAS, 'carga_fallida',
                'El archivo '.$file.' de '.$label.' no se pudo cargar a la base de datos (quedó con error).',
                'Revisa el error en Carga de archivos → '.$label.', corrige o vuelve a subir el archivo y pulsa «Cargar registros».'),
            $current['status'] === 'pending' && $run && $run->status === 'failed' => $this->verdict(self::STATE_PROBLEMAS, 'carga_fallida',
                'El archivo '.$file.' ya está subido, pero la última carga de registros de '.$label.' terminó con error'.($base['run']['error'] ? ': '.$base['run']['error'] : '.'),
                'Revisa el error en Carga de archivos → '.$label.' y vuelve a pulsar «Cargar registros».'),
            $current['status'] === 'pending' => $this->verdict(self::STATE_PROBLEMAS, 'archivo_sin_cargar',
                'El archivo '.$file.' de '.$label.' ya está en Carga de archivos (subido el '.($current['uploaded_at'] ?? '—').'), pero sus registros todavía no se cargan a la base de datos. El Estado de Resultados lee los registros, no el archivo.',
                'En Carga de archivos → '.$label.', pulsa «Cargar registros». No hace falta volver a subirlo.'),
            default => $this->verdict(self::STATE_PROBLEMAS, 'sin_registros',
                'El archivo '.$file.' de '.$label.' se cargó, pero no dejó ningún registro dentro del periodo ('.$period->start_date?->format('d/m/Y').' – '.$period->end_date?->format('d/m/Y').').'.$elsewhereHint,
                $key === 'erp'
                    ? 'Revisa que las fechas de pago de las requisiciones caigan dentro del periodo y que tengan un estatus contable (pagada/autorizada). Corrige el archivo, súbelo de nuevo y pulsa «Cargar registros».'
                    : 'Revisa que el archivo sea el del periodo correcto, súbelo de nuevo y pulsa «Cargar registros».'),
        };
    }

    /** Archivos de la fuente subidos en OTRO mes cuyas semanas cubiertas tocan este periodo. */
    private function elsewhere(Period $period, int $sourceId, array $dataIds): array
    {
        if ($dataIds === []) {
            return [];
        }
        $wanted = array_flip($dataIds);
        $rows = DB::table('report_uploads as ru')->join('periods as p', 'p.id', '=', 'ru.period_id')
            ->where('ru.data_source_id', $sourceId)->whereNotIn('ru.period_id', $dataIds)->where('ru.status', '!=', 'replaced')
            ->whereNotNull('ru.covered_period_ids')->orderByDesc('ru.id')->limit(50)
            ->get(['ru.id', 'ru.period_id', 'ru.original_name', 'ru.status', 'ru.covered_period_ids', 'p.name', 'p.year', 'p.month']);
        $out = [];
        foreach ($rows as $r) {
            $covered = json_decode((string) $r->covered_period_ids, true) ?: [];
            if (array_intersect_key(array_flip(array_map('intval', $covered)), $wanted) !== []) {
                $p = Period::query()->find($r->period_id);
                $out[] = ['upload_id' => (int) $r->id, 'period_id' => (int) $r->period_id, 'period_label' => (string) ($p?->label ?? $r->name), 'name' => (string) $r->original_name, 'status' => (string) $r->status];
            }
        }

        return $out;
    }

    private function verdict(string $state, string $reason, string $message, ?string $fix): array
    {
        return ['state' => $state, 'reason' => $reason, 'message' => $message, 'fix' => $fix];
    }

    public const STATUS_LABELS = [
        'pending' => 'Subido, sin cargar registros',
        'processing' => 'Cargando registros',
        'processed' => 'Registros cargados',
        'failed' => 'Con error',
        'replaced' => 'Sustituido',
    ];

    private static function fmt(mixed $ts): ?string
    {
        if (! $ts) {
            return null;
        }
        try {
            return Carbon::parse((string) $ts)->timezone('America/Mexico_City')->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $ts;
        }
    }
}
