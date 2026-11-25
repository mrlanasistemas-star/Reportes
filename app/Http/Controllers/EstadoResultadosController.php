<?php

namespace App\Http\Controllers;

use App\Jobs\RunEstadoResultadosTaskJob;
use App\Models\EstadoResultados\EstadoResultadosExport;
use App\Models\EstadoResultados\EstadoResultadosTask;
use App\Models\Period;
use App\Services\Access\DataScopeService;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Calculation\RadiographyBaseProvider;
use App\Services\EstadoResultados\Classification\ClassificationService;
use App\Services\EstadoResultados\Comparatives\ComparativesWarmer;
use App\Services\EstadoResultados\Comparatives\ComparisonAccessException;
use App\Services\EstadoResultados\Comparatives\ComparisonService;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use App\Services\EstadoResultados\Export\EstadoResultadosPdfExporter;
use App\Services\EstadoResultados\Import\CorporatePayrollUploadService;
use App\Services\EstadoResultados\Import\EstadoResultadosDuplicateUploadException;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosAbilities;
use App\Services\EstadoResultados\Reporting\EstadoResultadosApprovalService;
use App\Services\EstadoResultados\Reporting\EstadoResultadosRedactor;
use App\Services\EstadoResultados\Reporting\EstadoResultadosReportResolver;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Reporting\ReportStatus;
use App\Services\EstadoResultados\RulesRefreshService;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\ProcessingLockedException;
use App\Services\EstadoResultados\Support\Treatment;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Estado de Resultados. Web, Excel y PDF obtienen el dataset del MISMO resolver
 * (EstadoResultadosReportResolver): snapshot aprobado o cálculo en vivo, con la
 * redacción de nómina/DG aplicada en backend según los permisos del usuario.
 * Toda escritura se rechaza con HTTP 423 si el periodo tiene una versión aprobada vigente.
 *
 * Cubre la pantalla Inertia `EstadoResultados/Index` y sus endpoints JSON: dataset, cargas de
 * estados de cuenta y NOI de corporativo, procesamiento (en segundo plano), clasificación manual,
 * ajustes, aprobación/versiones, detalle restringido de nómina/DG y exportes Excel/PDF.
 * El alcance por sucursal se valida con DataScopeService en cada acción.
 */
class EstadoResultadosController extends Controller
{
    /**
     * @param  EstadoResultadosDatasetBuilder  $builder  Calcula el dataset en vivo y la lista de alcances.
     * @param  EstadoResultadosReportResolver  $resolver  Fuente única del dataset (snapshot aprobado o en vivo), redactado o no.
     * @param  PeriodLockGuard  $lock  Bloquea escrituras (423) si el periodo está aprobado y cerrado.
     * @param  EstadoResultadosAbilities  $abilities  Permisos finos del usuario (ver_nomina, ver_dg, etc.).
     * @param  DataScopeService  $dataScope  Alcance de sucursales permitido al usuario.
     */
    public function __construct(
        private readonly EstadoResultadosDatasetBuilder $builder,
        private readonly EstadoResultadosReportResolver $resolver,
        private readonly PeriodLockGuard $lock,
        private readonly EstadoResultadosAbilities $abilities,
        private readonly DataScopeService $dataScope,
    ) {}

    /**
     * Renderiza la pantalla del Estado de Resultados (Inertia `EstadoResultados/Index`).
     *
     * Envía los periodos mensuales (sin los de prueba TEST-%), el periodo inicial (el del enlace
     * `?periodo=` si existe; si no, el más reciente con un aviso), el alcance inicial, el alcance
     * de sucursales del usuario, catálogos (cuentas, tratamientos, estados de ajuste, estados del
     * reporte), permisos, reglas de carga (tamaño y extensiones) y la tolerancia de días de la
     * conciliación (config estado_resultados.conciliacion.tolerancia_dias, 3 por defecto).
     *
     * @param  Request  $request  Query opcional: periodo, alcance.
     */
    public function index(Request $request): Response
    {
        $periods = Period::query()
            ->where('type', 'monthly')
            ->where('code', 'not like', 'TEST-%')
            ->orderByDesc('start_date')
            ->get(['id', 'code', 'name', 'year', 'month', 'start_date', 'end_date', 'type'])
            ->map(fn (Period $p) => [
                'id' => $p->id, 'code' => $p->code, 'label' => $p->label,
                'start' => $p->start_date->format('Y-m-d'), 'end' => $p->end_date->format('Y-m-d'),
            ])->values();

        // Un enlace guardado puede apuntar a un periodo que ya no existe (o que no es mensual):
        // se abre el más reciente y se avisa, en lugar de un error técnico y un selector vacío.
        $requested = $request->integer('periodo');
        $latestId = (int) ($periods->first()['id'] ?? 0);
        $initialId = $requested && $periods->contains('id', $requested) ? $requested : $latestId;
        $periodNotice = $requested && $initialId !== $requested && $latestId
            ? 'El periodo del enlace ya no está disponible; te mostramos '.($periods->first()['label'] ?? 'el más reciente').'.'
            : null;

        return Inertia::render('EstadoResultados/Index', [
            'periods' => $periods,
            'initialPeriodId' => $initialId,
            'periodNotice' => $periodNotice,
            'initialScope' => $this->initialScope($request),
            // Alcance de datos por sucursal del usuario (DataScopeService): general = puede ver el consolidado.
            'branchScope' => $request->user() ? $this->dataScope->describe($request->user()) + ['allowed_names' => $this->dataScope->allowedBranchNames($request->user())] : null,
            'slots' => collect(EstadoResultadosUploadService::SLOTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'treatments' => Treatment::catalog(),
            'adjustmentStates' => AdjustmentService::STATES,
            'can' => $this->abilities->for($request->user()),
            'reportStatuses' => ReportStatus::catalog(),
            'uploadRules' => [
                'max_mb' => EstadoResultadosUploadService::maxMb(),
                'extensions' => EstadoResultadosUploadService::EXTENSIONS,
                'accept' => '.'.implode(',.', EstadoResultadosUploadService::EXTENSIONS),
            ],
            'toleranceDays' => (int) config('estado_resultados.conciliacion.tolerancia_dias', 3),
        ]);
    }

    /**
     * Dataset del Estado de Resultados para la pantalla (JSON) y la lista de alcances visibles.
     *
     * Eleva memory_limit a 1 GB. Valida periodo mensual, versión (`?version=`) y alcance
     * (`?alcance=`). Usa una sola sesión del builder para compartir la foto de Radiografía.
     * Si se pide la versión en vivo (sin `version`), despacha en segundo plano el calentado de
     * comparativas (ComparativesWarmer). Agrega `Server-Timing` con el tiempo del ER y si la base
     * de Radiografía se calculó o salió de caché.
     *
     * @param  Request  $request  Query: alcance, version.
     * @param  Period  $period  Periodo mensual.
     * @return JsonResponse { dataset, scopes }; 422 si el resolver rechaza la petición; 403 sin acceso al alcance.
     */
    public function dataset(Request $request, Period $period): JsonResponse
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $version = $this->versionParam($request);
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);

        try {
            $t0 = microtime(true);
            // Reglas nuevas (25-nov-2026): si el periodo se clasificó con otra versión de reglas o
            // de catálogo, se encola su reproceso sin acción del usuario (nunca lanza; con cola
            // sync ya terminó y el dataset de abajo sale actualizado).
            $autoTask = $version === null ? app(RulesRefreshService::class)->refreshIfStale($period) : null;
            // Una sola foto de Radiografía para alcances + dataset de esta petición
            // (RadiographyBaseProvider es #[Scoped]: el builder del resolver comparte la sesión).
            $response = $this->builder->session(fn () => response()->json([
                'dataset' => $this->resolver->resolve($period, $scope, $request->user(), $version),
                'scopes' => $this->visibleScopes($request, $this->resolver->scopes($period, $version)),
                // Reproceso automático en curso: la pantalla espera la tarea y recarga sola.
                'auto_refresh' => $autoTask && ! $autoTask->isFinished() ? $autoTask->toStatusArray() : null,
            ]));
            // Deja calentando en segundo plano las comparativas que la pantalla pedirá enseguida.
            if ($version === null) {
                ComparativesWarmer::dispatchFor($period);
            }
            $base = app(RadiographyBaseProvider::class)->stats;
            $response->headers->set('Server-Timing', sprintf('er;desc="Estado de Resultados";dur=%.0f, base;desc="Radiografia %s";dur=%.0f',
                (microtime(true) - $t0) * 1000, $base['computed'] > 0 ? 'calculada' : 'en cache', $base['ms_computing']));

            return $response;
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Cargas ─────────────────────────────────────────────────────────

    /**
     * Carga un estado de cuenta bancario (archivo 1 o 2 de una cuenta) al periodo.
     *
     * Requiere alcance general y periodo no bloqueado (423). El servicio valida extensión,
     * contenido, estructura y celdas, registra el intento (aun si se rechaza), guarda el archivo
     * y escribe en BD la carga y sus movimientos; NO procesa la conciliación.
     * Casos de duplicado (mismo hash ya cargado):
     *  - misma cuenta, otra parte → 422 (un archivo no puede ser a la vez el 1 y el 2);
     *  - misma cuenta y parte → respuesta idempotente (no duplica nada);
     *  - otra cuenta → error de importación.
     *
     * @param  Request  $request  `slot` (cuenta), `part` (1|2, por defecto 1), `file`.
     * @param  Period  $period  Periodo mensual.
     * @param  EstadoResultadosUploadService  $service  Lectura y persistencia del estado de cuenta.
     * @return JsonResponse Mensaje, `idempotent`, `upload` {id, part, warnings, control_ok}; o error con `attempt_id`.
     */
    public function upload(Request $request, Period $period, EstadoResultadosUploadService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La carga de estados de cuenta del Estado de Resultados');
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'slot' => ['required', 'in:'.implode(',', array_keys(EstadoResultadosUploadService::SLOTS))],
            // 20-nov-2026: cada cuenta admite archivo 1 (obligatorio) y archivo 2 (opcional).
            'part' => ['nullable', 'integer', 'in:'.implode(',', array_keys(EstadoResultadosUploadService::PARTS))],
            'file' => ['required', 'file'],
        ], [
            'slot.required' => 'Elige la cuenta del estado de cuenta.',
            'slot.in' => 'Cuenta no válida.',
            'part.integer' => 'Elige «Archivo 1» o «Archivo 2» de la cuenta.',
            'part.in' => 'Elige «Archivo 1» o «Archivo 2» de la cuenta.',
            'file.required' => 'Selecciona el archivo del estado de cuenta.',
            'file.file' => 'No se recibió el archivo completo. Vuelve a intentarlo.',
        ]);
        $part = (int) ($data['part'] ?? 1);

        try {
            // Extensión, contenido, tamaño, estructura y celdas se validan en el servicio
            // para que TODO rechazo quede registrado como intento con sus errores por fila.
            $upload = $service->upload($period, $data['file'], $data['slot'], $request->user()?->id, $part);
        } catch (EstadoResultadosDuplicateUploadException $e) {
            if ($e->existing->account_slot === $data['slot'] && (int) ($e->existing->file_part ?? 1) !== $part) {
                // El mismo archivo no puede ser a la vez el archivo 1 y el 2 de la cuenta.
                return response()->json([
                    'message' => 'Ese archivo ya está cargado como «Archivo '.(int) ($e->existing->file_part ?? 1).'» de esta cuenta. El otro archivo debe ser un estado de cuenta distinto.',
                    'details' => ['No se duplicó ningún movimiento.'],
                    'errors' => [],
                    'attempt_id' => $e->attemptId(),
                ], 422);
            }
            if ($e->existing->account_slot === $data['slot']) {
                // Reintento idempotente: mismo archivo, misma cuenta → no se duplica nada.
                return response()->json([
                    'message' => $e->getMessage(),
                    'idempotent' => true,
                    'attempt_id' => $e->attemptId(),
                    'upload' => ['id' => $e->existing->id, 'warnings' => $e->existing->warnings ?? [], 'control_ok' => $e->existing->control_ok],
                ]);
            }

            return $this->importError($e);
        } catch (EstadoResultadosImportException $e) {
            return $this->importError($e);
        }

        return response()->json([
            'message' => 'Archivo cargado: '.$upload->movements_count.' movimientos. Presiona "Procesar conciliación" para conciliar y clasificar.',
            'idempotent' => false,
            'upload' => ['id' => $upload->id, 'part' => (int) $upload->file_part, 'warnings' => $upload->warnings ?? [], 'control_ok' => $upload->control_ok],
        ]);
    }

    /**
     * Quita el «Archivo 2 (opcional)» de una cuenta (20-nov-2026). El archivo 1 es obligatorio
     * y sólo se sustituye. La carga queda en el historial como reemplazada.
     *
     * Requiere alcance general y periodo no bloqueado. Escribe en BD (marca la carga como
     * reemplazada); sus movimientos dejan de contar hasta volver a procesar la conciliación.
     *
     * @param  string  $slot  Cuenta (administrativa | excedentes).
     * @param  int  $part  Parte del archivo a quitar (el servicio sólo permite la 2).
     */
    public function removeUploadPart(Request $request, Period $period, string $slot, int $part, EstadoResultadosUploadService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'Quitar un estado de cuenta del Estado de Resultados');
        $this->lock->assertUnlocked($period);

        try {
            $upload = $service->removePart($period, $slot, $part, $request->user()?->id);
        } catch (EstadoResultadosImportException $e) {
            return $this->importError($e);
        }

        return response()->json([
            'message' => 'Se quitó el archivo 2 «'.$upload->original_name.'» de '.(EstadoResultadosUploadService::SLOTS[$slot] ?? $slot).'. Queda en el historial y sus movimientos ya no cuentan. Presiona "Procesar conciliación" para volver a cruzar la cuenta.',
            'upload' => ['id' => $upload->id, 'part' => (int) $upload->file_part],
        ]);
    }

    /**
     * NOI de corporativo (fuente propia del ER). Incremental: sólo inserta los renglones de ESTE
     * archivo; no reprocesa estados de cuenta ni toca Carga de archivos / Radiografía.
     *
     * Requiere alcance general y periodo no bloqueado. Si el mismo archivo ya estaba cargado
     * responde idempotente sin duplicar.
     *
     * @param  Request  $request  `file` obligatorio.
     * @return JsonResponse Mensaje con colaboradores y percepciones, `idempotent` y `upload` {id, warnings}.
     */
    public function uploadPayroll(Request $request, Period $period, CorporatePayrollUploadService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La carga del NOI de corporativo del Estado de Resultados');
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'file' => ['required', 'file'],
        ], [
            'file.required' => 'Selecciona el archivo del NOI de corporativo.',
            'file.file' => 'No se recibió el archivo completo. Vuelve a intentarlo.',
        ]);

        try {
            ['upload' => $upload, 'idempotent' => $idempotent] = $service->upload($period, $data['file'], $request->user()?->id);
        } catch (EstadoResultadosImportException $e) {
            return $this->importError($e);
        }

        return response()->json([
            'message' => $idempotent
                ? 'Este mismo NOI de corporativo ya estaba cargado: no se duplicó nada.'
                : 'NOI de corporativo cargado: '.$upload->employees_count.' colaborador(es), percepciones $'.number_format((float) $upload->total_percepciones, 2).'. Ya se refleja en Nómina corporativa; no hace falta volver a procesar la conciliación.',
            'idempotent' => $idempotent,
            'upload' => ['id' => $upload->id, 'warnings' => array_column((array) ($upload->warnings ?? []), 'message')],
        ]);
    }

    /**
     * Historial de intentos de carga del periodo (exitosos, rechazados y duplicados). Requiere alcance general.
     */
    public function uploadAttempts(Request $request, Period $period, EstadoResultadosUploadService $service): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El historial de cargas del Estado de Resultados');

        return response()->json(['attempts' => $service->attempts($period)]);
    }

    /**
     * Procesar conciliación (23-nov-2026): se encola (RunEstadoResultadosTaskJob) en lugar de
     * correr en la petición web. Con cola `sync` (pruebas, sin worker) se ejecuta en el acto y
     * se responde igual que antes; con worker responde 202 y la pantalla consulta la tarea.
     *
     * Requiere alcance general y periodo no bloqueado. Si ya hay un procesamiento abierto del
     * periodo responde 409 con `processing: true` y la tarea en curso.
     */
    public function process(Request $request, Period $period): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'El procesamiento de la conciliación');
        $this->lock->assertUnlocked($period);

        return $this->enqueueTask($period, EstadoResultadosTask::KIND_PROCESS, $request->user()?->id, [],
            ['message' => (new ProcessingLockedException($period->id))->getMessage(), 'processing' => true],
            'La conciliación se está procesando en segundo plano. Puedes seguir trabajando; la pantalla se actualizará al terminar.');
    }

    /**
     * Estado de una tarea en segundo plano (polling liviano de la pantalla).
     *
     * Antes de leer cierra como error las tareas abandonadas del periodo (escribe en BD).
     *
     * @param  int  $task  Id de la tarea; 404 si no pertenece al periodo.
     */
    public function task(Request $request, Period $period, int $task): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El estado de las tareas del Estado de Resultados');
        EstadoResultadosTask::closeAbandoned($period->id);
        $row = EstadoResultadosTask::query()->where('period_id', $period->id)->findOrFail($task);

        return response()->json(['task' => $row->toStatusArray()]);
    }

    /**
     * Tareas abiertas del periodo (para retomar el «en proceso» al recargar la página).
     * También cierra antes las tareas abandonadas. Requiere alcance general.
     */
    public function activeTasks(Request $request, Period $period): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El estado de las tareas del Estado de Resultados');
        EstadoResultadosTask::closeAbandoned($period->id);

        return response()->json([
            'tasks' => EstadoResultadosTask::query()->active()->where('period_id', $period->id)->orderBy('id')->get()
                ->map(fn (EstadoResultadosTask $t) => $t->toStatusArray())->values(),
        ]);
    }

    /**
     * Crea la tarea (una abierta por periodo y tipo, con la fila del periodo bloqueada para
     * que un doble clic no encole dos) y la despacha. Si al volver del dispatch ya terminó
     * (cola sync) se responde con su resultado exacto; si no, 202 + estado de la tarea.
     *
     * Efectos: transacción con lockForUpdate sobre la fila del periodo, INSERT en
     * estado_resultados_tasks y despacho de RunEstadoResultadosTaskJob.
     *
     * @param  string  $kind  EstadoResultadosTask::KIND_PROCESS | KIND_APPROVE.
     * @param  int|null  $userId  Usuario que la solicita.
     * @param  array  $input  Parámetros para el job (p.ej. notas y hash esperado al aprobar).
     * @param  array  $busyBody  Cuerpo de la respuesta 409 si ya hay una tarea abierta del mismo tipo.
     * @param  string  $queuedMessage  Mensaje de la respuesta 202.
     */
    private function enqueueTask(Period $period, string $kind, ?int $userId, array $input, array $busyBody, string $queuedMessage): JsonResponse
    {
        EstadoResultadosTask::closeAbandoned($period->id);

        $task = DB::transaction(function () use ($period, $kind, $userId, $input) {
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
            if (EstadoResultadosTask::query()->active()->where('period_id', $period->id)->where('kind', $kind)->exists()) {
                return null;
            }

            return EstadoResultadosTask::query()->create([
                'period_id' => $period->id,
                'kind' => $kind,
                'status' => EstadoResultadosTask::STATUS_QUEUED,
                'user_id' => $userId,
                'input' => $input ?: null,
                'progress_percent' => 0,
                'current_step' => 'En cola',
                'queued_at' => now(),
            ]);
        });

        if (! $task) {
            $active = EstadoResultadosTask::query()->active()->where('period_id', $period->id)->where('kind', $kind)->latest('id')->first();

            return response()->json($busyBody + ['task' => $active?->toStatusArray()], 409);
        }

        RunEstadoResultadosTaskJob::dispatch($task->id);
        $task->refresh();

        if ($task->isFinished()) {
            return response()->json($task->response ?? [], $task->http_status ?: 200);
        }

        return response()->json(['message' => $queuedMessage, 'queued' => true, 'task' => $task->toStatusArray()], 202);
    }

    // ── Clasificación ──────────────────────────────────────────────────

    /**
     * Clasifica manualmente uno o varios movimientos (hasta 2000) con un tratamiento asignable.
     *
     * Requiere alcance general, periodo no bloqueado y motivo (mín. 3 caracteres). Los
     * movimientos de nómina/DG sólo los reclasifica quien tiene el permiso correspondiente (403).
     * Efecto: agrega filas a la bitácora append-only de clasificaciones.
     *
     * @param  Request  $request  `fingerprints[]`, `treatment`, `notes`.
     * @return JsonResponse Mensaje y `count`; 422 si no se encontró ningún movimiento.
     */
    public function classify(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La clasificación de movimientos');
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:2000'],
            'fingerprints.*' => ['string', 'max:64'],
            'treatment' => ['required', 'string', 'in:'.implode(',', Treatment::ASSIGNABLE)],
            'notes' => ['required', 'string', 'min:3', 'max:2000'],
            // «Ya considerado» (25-nov-2026): motivo y, en modo parcial, el importe ya considerado.
            'considered_reason' => ['nullable', 'string', 'in:'.implode(',', array_keys(Treatment::CONSIDERED_REASONS))],
            'considered_amount' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ], [
            'notes.required' => 'Escribe el motivo de la clasificación.', 'notes.min' => 'Escribe el motivo de la clasificación.',
            'considered_reason.in' => 'Elige el motivo de «Ya considerado».',
            'considered_amount.regex' => 'Captura el importe ya considerado con hasta 2 decimales (p. ej. 1250.50).',
        ]);

        $items = $this->itemsByFingerprint($request, $period, $data['fingerprints']);
        if ($items === []) {
            return response()->json(['message' => 'No se encontraron los movimientos seleccionados.'], 422);
        }
        $considered = null;
        if (! empty($data['considered_reason']) || ! empty($data['considered_amount'])) {
            $considered = $this->consideredSplit($items, $data);
            if (is_string($considered)) {
                return response()->json(['message' => $considered, 'errors' => ['considered_amount' => [$considered]]], 422);
            }
        }
        try {
            $count = $service->classify($period, $items, $data['treatment'], $data['notes'], $request->user()?->id, $considered);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $message = $considered !== null && $considered['amount'] !== null
            ? 'Ya considerado $'.number_format((float) $considered['amount'], 2).' ('.Treatment::CONSIDERED_REASONS[$considered['reason']].'); el resto se clasificó como "'.Treatment::label($data['treatment']).'".'
            : $count.' movimiento(s) clasificados como "'.Treatment::label($data['treatment']).'".';

        return response()->json(['message' => $message, 'count' => $count]);
    }

    /**
     * Valida el reparto «Ya considerado» de /clasificar. Total (sin importe): el tratamiento debe ser
     * el del motivo (Radiografía ⇒ «Ya incluido en Radiografía»; otro ⇒ «Ya considerado»). Parcial:
     * un solo movimiento del periodo, importe > 0 y MENOR que lo clasificable (bruto − conciliado con
     * ERP) al centavo, y el resto con un tratamiento distinto al de la parte ya considerada.
     *
     * @param  list<array<string,mixed>>  $items  movimientos (itemsByFingerprint)
     * @param  array<string,mixed>  $data  datos validados
     * @return array{reason:string, amount:?string}|string reparto o mensaje de error
     */
    private function consideredSplit(array $items, array $data): array|string
    {
        $reason = (string) ($data['considered_reason'] ?? '');
        if ($reason === '') {
            return 'Elige el motivo de «Ya considerado».';
        }
        $consideredTreatment = Treatment::consideredTreatment($reason);
        $amount = $data['considered_amount'] ?? null;
        if ($amount === null || $amount === '') {
            return $data['treatment'] === $consideredTreatment ? ['reason' => $reason, 'amount' => null]
                : 'Para marcar todo el importe como ya considerado usa «'.Treatment::label($consideredTreatment).'».';
        }
        if (count($items) !== 1) {
            return 'El importe ya considerado se captura de un movimiento a la vez.';
        }
        $item = $items[0];
        if (! ($item['in_period'] ?? true) || ($item['source'] ?? '') === 'noi_corp') {
            return 'Este movimiento no admite un importe parcial ya considerado.';
        }
        if ($data['treatment'] === $consideredTreatment) {
            return 'Elige a qué tratamiento va el resto (distinto de «'.Treatment::label($consideredTreatment).'»).';
        }
        $value = Money::of($amount);
        $max = Money::of($item['classifiable_total'] ?? '0');
        if (! $value->isPositive()) {
            return 'El importe ya considerado debe ser mayor a $0.00.';
        }
        if (! $value->isLessThan($max)) {
            return 'El importe ya considerado debe ser menor que lo clasificable del movimiento ($'.number_format((float) Money::str2($max), 2).'). Para todo el importe usa el modo «Total».';
        }

        return ['reason' => $reason, 'amount' => Money::str2($value)];
    }

    /**
     * Acepta en bloque las sugerencias automáticas de los movimientos indicados.
     *
     * Sólo aplica a movimientos que siguen PENDIENTES y cuya sugerencia es asignable; los agrupa
     * por tratamiento sugerido y los clasifica (bitácora), con la nota dada o «Aceptó la
     * sugerencia automática.». Requiere alcance general y periodo no bloqueado.
     *
     * @param  Request  $request  `fingerprints[]`, `notes` opcional.
     * @return JsonResponse Mensaje y `count` de sugerencias aceptadas.
     */
    public function acceptSuggestions(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La clasificación de movimientos');
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'fingerprints' => ['required', 'array', 'min:1', 'max:2000'],
            'fingerprints.*' => ['string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $items = $this->itemsByFingerprint($request, $period, $data['fingerprints'], withSuggestion: true);
        $byTreatment = [];
        foreach ($items as $item) {
            if ($item['current'] === Treatment::PENDIENTE && $item['suggested'] && Treatment::isAssignable($item['suggested'])) {
                $byTreatment[$item['suggested']][] = $item;
            }
        }
        $count = 0;
        $notes = trim((string) ($data['notes'] ?? '')) ?: 'Aceptó la sugerencia automática.';
        foreach ($byTreatment as $treatment => $list) {
            $count += $service->classify($period, $list, $treatment, $notes, $request->user()?->id);
        }

        return response()->json(['message' => $count.' sugerencia(s) aceptadas.', 'count' => $count]);
    }

    /**
     * Historial de clasificaciones de un movimiento (`?fingerprint=`).
     *
     * Requiere alcance general; si el movimiento es de nómina/DG exige además el permiso de detalle.
     */
    public function history(Request $request, Period $period, ClassificationService $service): JsonResponse
    {
        $this->dataScope->requireGeneral($request->user(), 'El historial de clasificación');
        $fp = (string) $request->query('fingerprint', '');
        $movement = collect($this->resolver->unredacted($period, EstadoResultadosDatasetBuilder::CONSOLIDADO)['movements'] ?? [])->firstWhere('fingerprint', $fp);
        if ($movement) {
            $this->ensureCanSeeMovement($request, $movement);
        }

        return response()->json(['history' => $service->history($period->id, $fp)]);
    }

    // ── Ajustes ────────────────────────────────────────────────────────

    /**
     * Guarda un ajuste contable tipificado (sin información / $0 confirmado / capturado) de un alcance.
     *
     * Periodo no bloqueado; el alcance debe existir en el periodo (422) y estar autorizado al
     * usuario (403). Efecto: actualiza el ajuste y agrega su renglón de bitácora.
     *
     * @param  Request  $request  `scope`, `concept`, `state`, `amount` opcional, `notes` opcional.
     * @return JsonResponse Mensaje; 422 si el servicio rechaza los datos.
     */
    public function saveAdjustment(Request $request, Period $period, AdjustmentService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->lock->assertUnlocked($period);
        $data = $request->validate([
            'scope' => ['required', 'string', 'max:80'],
            'concept' => ['required', 'string', 'in:'.implode(',', array_keys(AdjustmentService::CONCEPTS))],
            'state' => ['required', 'string', 'in:'.implode(',', AdjustmentService::STATES)],
            'amount' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->validScope($period, $data['scope']);
        $this->authorizeScope($request, $data['scope']);

        try {
            $service->save($period, $data['scope'], $data['concept'], $data['state'], $data['amount'] ?? null, $data['notes'] ?? null, $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Ajuste guardado.']);
    }

    /**
     * Bitácora de cambios de los ajustes del alcance pedido (`?alcance=`), validando acceso al alcance.
     */
    public function adjustmentLogs(Request $request, Period $period, AdjustmentService $service): JsonResponse
    {
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);

        return response()->json(['logs' => $service->logs($period->id, $scope)]);
    }

    // ── Aprobación y versiones ─────────────────────────────────────────

    /**
     * Aprobar (23-nov-2026): congela el dataset de los ~14 alcances, lo que necesitaba hasta
     * 2 GB dentro de PHP-FPM. Ahora se encola (RunEstadoResultadosTaskJob, mismo servicio y
     * mismos argumentos); con cola `sync` responde igual que antes.
     *
     * Requiere alcance general. `expected_hash` (64 caracteres) es el hash del dataset que el
     * usuario revisó, para que no se apruebe algo distinto a lo que vio. Si otra aprobación del
     * periodo está en curso responde 409 (`conflict: aprobacion_en_curso`).
     *
     * @param  Request  $request  `notes` y `expected_hash` opcionales.
     */
    public function approve(Request $request, Period $period): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'La aprobación del Estado de Resultados');
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_hash' => ['nullable', 'string', 'size:64'],
        ]);

        return $this->enqueueTask($period, EstadoResultadosTask::KIND_APPROVE, $request->user()?->id,
            ['notes' => $data['notes'] ?? null, 'expected_hash' => $data['expected_hash'] ?? null],
            ['message' => 'Otro usuario está aprobando este periodo en este momento. Intenta de nuevo en unos segundos.', 'conflict' => 'aprobacion_en_curso'],
            'La aprobación se está procesando en segundo plano. La pantalla se actualizará al terminar.');
    }

    /**
     * Reabre un periodo aprobado para preparar una nueva versión.
     *
     * Requiere alcance general y motivo (mín. 10 caracteres). La versión aprobada anterior sigue
     * vigente hasta que se apruebe la nueva. Efecto: el servicio marca la reapertura y la bitácora.
     *
     * @param  Request  $request  `reason` obligatorio.
     * @return JsonResponse Mensaje, número de la versión borrador y resumen de la versión anterior.
     */
    public function openNewVersion(Request $request, Period $period, EstadoResultadosApprovalService $service): JsonResponse
    {
        $this->ensureMonthly($period);
        $this->dataScope->requireGeneral($request->user(), 'Abrir una nueva versión del Estado de Resultados');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'reason.required' => 'Escribe el motivo para abrir una nueva versión.',
            'reason.min' => 'Escribe el motivo para abrir una nueva versión (mínimo 10 caracteres).',
        ]);
        $previous = $service->openNewVersion($period, $request->user(), $data['reason']);

        return response()->json([
            'message' => 'Se abrió la versión '.($previous->version + 1).' de '.$period->label.'. La versión '.$previous->version.' sigue vigente hasta que se apruebe la nueva.',
            'draft_version' => $previous->version + 1,
            'previous' => EstadoResultadosApprovalService::summary($previous->load(['approver:id,name', 'reopener:id,name'])),
        ]);
    }

    /**
     * Historial de versiones aprobadas del periodo. No valida alcance de sucursal (sólo lo que
     * imponga la ruta).
     */
    public function versions(Request $request, Period $period, EstadoResultadosApprovalService $service): JsonResponse
    {
        return response()->json($service->history($period));
    }

    // ── Detalle restringido (nómina / DG) ──────────────────────────────

    /**
     * Detalle restringido de nómina (secciones y movimientos de nómina sin redactar).
     * El permiso `ver_nomina` lo impone la ruta; ver restrictedDetail().
     */
    public function payrollDetail(Request $request, Period $period): JsonResponse
    {
        return $this->restrictedDetail($request, $period, EstadoResultadosRedactor::NOMINA_SECTIONS, fn ($m) => EstadoResultadosRedactor::isPayroll($m));
    }

    /**
     * Detalle restringido de Dirección General (secciones y movimientos DG sin redactar).
     * El permiso `ver_dg` lo impone la ruta; ver restrictedDetail().
     */
    public function dgDetail(Request $request, Period $period): JsonResponse
    {
        return $this->restrictedDetail($request, $period, EstadoResultadosRedactor::DG_SECTIONS, fn ($m) => EstadoResultadosRedactor::isDg($m));
    }

    // ── Exportes ───────────────────────────────────────────────────────

    /**
     * Exporta el Estado de Resultados del alcance/versión pedidos a Excel (.xlsx).
     *
     * Usa el mismo dataset que la web (resolver, ya redactado) e incluye comparativas de 6 meses
     * (o un aviso si no se pudieron calcular). Efectos: eleva memory_limit a 1 GB; escribe un
     * temporal en storage/app/tmp que se borra tras enviarse; registra la exportación en
     * estado_resultados_exports; si falla, log con referencia ER-XLSX-… y 500.
     * Encabezados X-Dataset-Hash / X-Report-Version / X-Report-Status / X-Report-Preliminary.
     *
     * @param  Request  $request  Query: alcance, version.
     * @return BinaryFileResponse|JsonResponse
     */
    public function exportExcel(Request $request, Period $period, EstadoResultadosExcelExporter $exporter)
    {
        @ini_set('memory_limit', '1024M');
        $this->ensureMonthly($period);
        $dataset = $this->exportDataset($request, $period);
        if ($dataset instanceof JsonResponse) {
            return $dataset;
        }
        $name = $this->fileName($dataset, 'xlsx');
        $path = storage_path('app/tmp/estado-resultados-'.Str::uuid().'.xlsx');
        [$comparisons, $comparisonsError] = $this->exportComparisons($request, $period, $dataset);
        try {
            $exporter->export($dataset, $path, $comparisons, $comparisonsError);
        } catch (\Throwable $e) {
            @unlink($path);
            $ref = 'ER-XLSX-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4));
            Log::error('estado_resultados.excel_failed', ['ref' => $ref, 'period_id' => $period->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo generar el Excel. Referencia: '.$ref], 500);
        }
        $this->logExport($request, $period, $dataset, 'xlsx', $name);

        return response()->download($path, $name, $this->exportHeaders($dataset, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->deleteFileAfterSend();
    }

    /**
     * Exporta el Estado de Resultados del alcance/versión pedidos a PDF.
     *
     * Igual que exportExcel() (mismo dataset, comparativas, bitácora de exportación y encabezados),
     * con tiempo máximo de 600 s. Si el render falla (PdfRenderException) registra el error con
     * referencia ER-PDF-… y responde 500 sin exponer rutas internas.
     *
     * @return BinaryFileResponse|JsonResponse
     */
    public function exportPdf(Request $request, Period $period, EstadoResultadosPdfExporter $exporter)
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(600);
        $this->ensureMonthly($period);
        $dataset = $this->exportDataset($request, $period);
        if ($dataset instanceof JsonResponse) {
            return $dataset;
        }
        $name = $this->fileName($dataset, 'pdf');
        $path = storage_path('app/tmp/estado-resultados-'.Str::uuid().'.pdf');
        [$comparisons, $comparisonsError] = $this->exportComparisons($request, $period, $dataset);

        try {
            $exporter->export($dataset, $path, $comparisons, $comparisonsError);
        } catch (PdfRenderException $e) {
            // El exportador ya eliminó el archivo parcial; al usuario sólo se le da una referencia (sin rutas internas).
            $ref = 'ER-PDF-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4));
            Log::error('estado_resultados.pdf_failed', ['ref' => $ref, 'period_id' => $period->id, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage().' Referencia: '.$ref], 500);
        }
        $this->logExport($request, $period, $dataset, 'pdf', $name);

        return response()->download($path, $name, $this->exportHeaders($dataset, 'application/pdf'))->deleteFileAfterSend();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Comparativas y series de gráficas para los exportes: el MISMO servicio y contrato que la web
     * (ComparisonService, er-comparativas/1), con el alcance del reporte y los permisos del usuario.
     * Si no se pueden calcular, el exporte sale igual con un aviso (nunca con cifras inventadas).
     *
     * @param  array  $dataset  Dataset ya resuelto (se usa su alcance).
     * @return array{0: ?array, 1: ?string} [comparativas o null, aviso para el exporte o null].
     */
    private function exportComparisons(Request $request, Period $period, array $dataset): array
    {
        @set_time_limit(600);
        try {
            return [app(ComparisonService::class)->build($period, (string) $dataset['meta']['scope']['key'], $request->user(), ['meses' => 6]), null];
        } catch (ComparisonAccessException|\InvalidArgumentException $e) {
            return [null, 'Comparativas no disponibles: '.$e->getMessage()];
        } catch (\Throwable $e) {
            Log::warning('estado_resultados.export_comparisons_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return [null, 'Las comparativas no se pudieron calcular en este momento; el Estado de Resultados del periodo sí está completo.'];
        }
    }

    /**
     * Dataset para exportar: valida el alcance del usuario y lo resuelve (redactado) para la
     * versión pedida.
     *
     * @return array|JsonResponse Dataset, o 422 si el resolver lo rechaza.
     */
    private function exportDataset(Request $request, Period $period): array|JsonResponse
    {
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);
        try {
            return $this->resolver->resolve($period, $scope, $request->user(), $this->versionParam($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Encabezados de la descarga: tipo de contenido más hash del dataset, versión, estado y si es
     * preliminar, para que quien recibe el archivo pueda verificar qué cifras se exportaron.
     *
     * @param  string  $contentType  MIME del archivo.
     * @return array<string, string>
     */
    private function exportHeaders(array $dataset, string $contentType): array
    {
        $report = $dataset['meta']['report'];

        return [
            'Content-Type' => $contentType,
            'X-Dataset-Hash' => $dataset['meta']['dataset_hash'],
            'X-Report-Version' => (string) $report['version'],
            'X-Report-Status' => $report['status'],
            'X-Report-Preliminary' => $report['preliminary'] ? '1' : '0',
        ];
    }

    /**
     * Convierte un error de importación en JSON: mensaje, detalles, errores por fila y el id del
     * intento registrado, con el código HTTP que indique la excepción.
     */
    private function importError(EstadoResultadosImportException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'details' => $e->details(),
            'errors' => $e->errors(),
            'attempt_id' => $e->attemptId(),
        ], $e->status());
    }

    /**
     * Arma el detalle restringido (nómina o DG): toma el dataset SIN redactar, vuelve a aplicar la
     * redacción de la OTRA categoría según los permisos del usuario, y devuelve las secciones
     * pedidas y los movimientos que pasan el filtro.
     *
     * Efecto: registra en el log (`estado_resultados.detalle_restringido`) quién consultó, qué
     * secciones, alcance, versión e IP (bitácora de consulta de información sensible).
     *
     * @param  array  $sections  Llaves de sección/categoría a devolver.
     * @param  callable  $filter  Filtro de movimientos (isPayroll / isDg).
     * @return JsonResponse { sections, movements, report }; 422 si el resolver lo rechaza.
     */
    private function restrictedDetail(Request $request, Period $period, array $sections, callable $filter): JsonResponse
    {
        $this->ensureMonthly($period);
        $scope = $this->scope($request);
        $this->authorizeScope($request, $scope);
        try {
            $d = $this->resolver->unredacted($period, $scope, $this->versionParam($request));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        // La otra categoría restringida se sigue respetando (p. ej. nómina de DG sin ver_dg).
        $d = app(EstadoResultadosRedactor::class)->apply(
            $d,
            $this->abilities->can($request->user(), 'ver_nomina'),
            $this->abilities->can($request->user(), 'ver_dg'),
        );

        // Bitácora de consulta de detalle sensible (nómina / Dirección General).
        Log::info('estado_resultados.detalle_restringido', [
            'period_id' => $period->id, 'scope' => $scope, 'sections' => $sections,
            'user_id' => $request->user()?->id, 'version' => $d['meta']['report']['version'] ?? null, 'ip' => $request->ip(),
        ]);

        return response()->json([
            'sections' => $this->restrictedSections($d['statement'], $sections),
            'movements' => array_values(array_filter($d['movements'], $filter)),
            'report' => $d['meta']['report'],
        ]);
    }

    /**
     * Secciones restringidas: en el modelo de EBITDA único nómina y DG son categorías de la
     * sección «opex» (se devuelven con la forma de una sección: key, title, lines, total).
     *
     * @param  array  $statement  Bloques del estado (dataset.statement).
     * @param  array  $keys  Llaves de sección o categoría buscadas.
     * @return array Secciones encontradas (las categorías llevan `parent` con la sección de origen).
     */
    private function restrictedSections(array $statement, array $keys): array
    {
        $out = [];
        foreach ($statement as $b) {
            if (($b['type'] ?? null) !== 'section') {
                continue;
            }
            if (in_array($b['key'], $keys, true)) {
                $out[] = $b;
            }
            foreach ((array) ($b['categories'] ?? []) as $c) {
                if (in_array($c['key'] ?? null, $keys, true)) {
                    $out[] = ['type' => 'section', 'title' => $c['label'], 'parent' => $b['key']] + $c;
                }
            }
        }

        return $out;
    }

    /**
     * Busca en el dataset CONSOLIDADO sin redactar los movimientos con las huellas indicadas,
     * verificando que el usuario pueda ver cada uno (403 si es nómina/DG sin permiso).
     *
     * @param  array  $fingerprints  Huellas pedidas.
     * @param  bool  $withSuggestion  Incluir el tratamiento sugerido de cada movimiento.
     * @return list<array{fingerprint:string,source:string,current:?string,suggested?:?string}>
     */
    private function itemsByFingerprint(Request $request, Period $period, array $fingerprints, bool $withSuggestion = false): array
    {
        $wanted = array_flip($fingerprints);
        $dataset = $this->resolver->unredacted($period, EstadoResultadosDatasetBuilder::CONSOLIDADO);
        $out = [];
        foreach ($dataset['movements'] as $m) {
            if (isset($wanted[$m['fingerprint']])) {
                $this->ensureCanSeeMovement($request, $m);
                $out[] = ['fingerprint' => $m['fingerprint'], 'source' => $m['source'], 'current' => $m['treatment'],
                    'considered' => $m['override']['considered_amount'] ?? null, 'considered_reason' => $m['override']['considered_reason'] ?? null,
                    'in_period' => (bool) ($m['in_period'] ?? true),
                    // Lo que se puede repartir: bruto − parte conciliada con ERP (la ya considerada se vuelve a capturar).
                    'classifiable_total' => Money::str2(Money::of($m['amounts']['gross'] ?? Money::of($m['cargo'])->plus(Money::of($m['abono'])))->minus(Money::of($m['amounts']['conciliated'] ?? '0')))]
                    + ($withSuggestion ? ['suggested' => $m['suggested_treatment']] : []);
            }
        }

        return $out;
    }

    /**
     * Un movimiento de nómina / DG sólo lo consulta o reclasifica quien tiene el permiso de detalle.
     *
     * @throws HttpException 403 si falta ver_nomina o ver_dg.
     */
    private function ensureCanSeeMovement(Request $request, array $movement): void
    {
        if (EstadoResultadosRedactor::isPayroll($movement) && ! $this->abilities->can($request->user(), 'ver_nomina')) {
            abort(403, 'Tu rol no permite consultar el detalle de nómina en el Estado de Resultados.');
        }
        if (EstadoResultadosRedactor::isDg($movement) && ! $this->abilities->can($request->user(), 'ver_dg')) {
            abort(403, 'Tu rol no permite consultar el detalle de Dirección General en el Estado de Resultados.');
        }
    }

    /**
     * Versión aprobada pedida (`version` en query o cuerpo). null = cálculo vigente.
     *
     * @throws HttpException 422 si no es un entero positivo.
     */
    private function versionParam(Request $request): ?int
    {
        $v = $request->query('version', $request->input('version'));
        if ($v === null || $v === '') {
            return null;
        }
        abort_unless(ctype_digit((string) $v) && (int) $v > 0, 422, 'Versión no válida.');

        return (int) $v;
    }

    /**
     * Alcance pedido (`alcance` en query o cuerpo), por defecto CONSOLIDADO, recortado a 80 caracteres.
     * No valida permisos (ver authorizeScope()).
     */
    private function scope(Request $request): string
    {
        $scope = trim((string) $request->query('alcance', $request->input('alcance', EstadoResultadosDatasetBuilder::CONSOLIDADO)));

        return $scope === '' ? EstadoResultadosDatasetBuilder::CONSOLIDADO : mb_substr($scope, 0, 80);
    }

    /**
     * Alcance por sucursal del usuario (DataScopeService): el CONSOLIDADO agrega todas las
     * sucursales → sólo alcance general; una sucursal → debe estar entre las autorizadas.
     *
     * @param  string  $scope  CONSOLIDADO o nombre de sucursal.
     *
     * @throws HttpException 403 sin sesión o sin acceso.
     */
    private function authorizeScope(Request $request, string $scope): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(403, 'Inicia sesión para consultar el Estado de Resultados.');
        }
        if ($scope === EstadoResultadosDatasetBuilder::CONSOLIDADO) {
            $this->dataScope->requireGeneral($user, 'El Estado de Resultados consolidado');

            return;
        }
        if (! $this->dataScope->allowsBranchName($user, $scope)) {
            abort(403, 'No tienes acceso al Estado de Resultados de la sucursal '.$scope.'. Tu alcance autorizado: '.$this->dataScope->label($user).'. '
                .'Si necesitas consultarla, pide a un administrador que amplíe tu alcance en Usuarios.');
        }
    }

    /**
     * Filtra los alcances del selector: un usuario general los ve todos; uno restringido sólo sus
     * sucursales autorizadas (nunca el CONSOLIDADO).
     *
     * @param  list<array{key:string,label:string}>  $scopes
     * @return list<array{key:string,label:string}>
     */
    private function visibleScopes(Request $request, array $scopes): array
    {
        $user = $request->user();
        if ($user === null || $this->dataScope->isGeneral($user)) {
            return $scopes;
        }

        return array_values(array_filter($scopes, fn ($s) => $s['key'] !== EstadoResultadosDatasetBuilder::CONSOLIDADO && $this->dataScope->allowsBranchName($user, $s['key'])));
    }

    /**
     * Alcance con el que abre la pantalla: el pedido en `?alcance=` si el usuario puede verlo;
     * si no, CONSOLIDADO para usuarios generales o la primera sucursal autorizada para restringidos.
     */
    private function initialScope(Request $request): string
    {
        $requested = trim((string) $request->string('alcance'));
        $user = $request->user();
        if ($user === null || $this->dataScope->isGeneral($user)) {
            return $requested !== '' ? $requested : EstadoResultadosDatasetBuilder::CONSOLIDADO;
        }
        if ($requested !== '' && $requested !== EstadoResultadosDatasetBuilder::CONSOLIDADO && $this->dataScope->allowsBranchName($user, $requested)) {
            return $requested;
        }

        return (string) ($this->dataScope->allowedBranchNames($user)[0] ?? EstadoResultadosDatasetBuilder::CONSOLIDADO);
    }

    /**
     * Verifica que el alcance exista entre los alcances del periodo (422 si no).
     */
    private function validScope(Period $period, string $scope): void
    {
        $keys = array_column($this->builder->scopes($period), 'key');
        abort_unless(in_array($scope, $keys, true), 422, 'Alcance no válido.');
    }

    /**
     * El Estado de Resultados sólo existe para periodos mensuales (422 si no).
     */
    private function ensureMonthly(Period $period): void
    {
        abort_unless($period->type === 'monthly', 422, 'El Estado de Resultados se genera sobre periodos mensuales.');
    }

    /**
     * Nombre del archivo exportado: MR_LANA_Estado_Resultados_{periodo}_{alcance}_v{N}[_PRELIMINAR|_APROBADO].{ext}
     *
     * @param  string  $ext  xlsx | pdf.
     */
    private function fileName(array $dataset, string $ext): string
    {
        $scope = Str::slug($dataset['meta']['scope']['key'] === EstadoResultadosDatasetBuilder::CONSOLIDADO ? 'consolidado' : $dataset['meta']['scope']['key']);
        $report = $dataset['meta']['report'];
        $suffix = '_v'.$report['version'].($report['preliminary'] ? '_PRELIMINAR' : ($report['status'] === ReportStatus::APROBADO ? '_APROBADO' : ''));

        return 'MR_LANA_Estado_Resultados_'.$dataset['meta']['period']['code'].'_'.$scope.$suffix.'.'.$ext;
    }

    /**
     * Registra la exportación en estado_resultados_exports (alcance, formato, hash, versión,
     * estado, si es preliminar, nombre de archivo y usuario). Escribe en BD.
     *
     * @param  string  $format  xlsx | pdf.
     * @param  string  $name  Nombre del archivo entregado.
     */
    private function logExport(Request $request, Period $period, array $dataset, string $format, string $name): void
    {
        EstadoResultadosExport::query()->create([
            'period_id' => $period->id,
            'scope_key' => $dataset['meta']['scope']['key'],
            'format' => $format,
            'dataset_hash' => $dataset['meta']['dataset_hash'],
            'version_number' => $dataset['meta']['report']['version'],
            'report_status' => $dataset['meta']['report']['status'],
            'preliminary' => (bool) $dataset['meta']['report']['preliminary'],
            'file_name' => $name,
            'user_id' => $request->user()?->id,
        ]);
    }
}
