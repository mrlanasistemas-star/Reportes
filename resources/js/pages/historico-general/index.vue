<script setup lang="ts">
/**
 * Pantalla «Histórico general · Carga de archivos» (ruta /historico-general).
 *
 * Es el flujo guiado del cierre mensual de la radiografía financiera:
 *   1. Elegir periodo → 2. Subir fuentes (archivos) → 3. Cargar registros a BD (job en cola)
 *   → 4. Resolver incidencias → 5. Configurar reporte → 6. Revisar gastos
 *   → 7. Generar reporte (Excel/PDF en cola) → 8. Vista previa → 9. Descargas.
 * Los periodos derivados (bimestre, trimestre…) muestran una vista simplificada
 * (AutomaticPeriodDetail) sin stepper porque no reciben archivos directos.
 *
 * Props (Inertia, desde ReportUploadController::index()):
 *  - periods: periodos con su estado de flujo (can_update_database, can_generate_radiography,
 *    database_update_run_status, radiography_run_status, blocking_reasons…).
 *  - sources: catálogo de fuentes de datos (nombre, código, si es requerida para BD).
 *  - groupedUploads: cargas agrupadas por periodo (uploads, pending_count, failed_count).
 *  - currentPeriodId: periodo preseleccionado.
 *  - preview: datos de la vista previa de la radiografía.
 *  - branches / employees: catálogos para configuración e incidencias.
 *
 * Rutas que consume (Inertia router / fetch):
 *  - POST /historico-general (subir archivo) · DELETE /historico-general/{id}
 *  - POST /historico-general/{periodo}/actualizar-bd, .../actualizacion-bd/{cancelar|procesar-ahora|reencolar|limpiar}
 *  - POST .../procesar-fuentes-pendientes, .../reprocesar-fuentes-con-error
 *  - GET  .../incidencias, .../colaboradores
 *  - POST .../incidencias/{id}/resolver, .../incidencias/ubicacion-pendiente/resolver
 *  - POST .../personas-sin-sucursal/confirmar-coincidencia, .../coincidencias/descartar
 *  - POST /empleados/batch-asignar-sucursal
 *  - POST .../generar-radiografia, .../generar-reporte/{cancelar|procesar-ahora}
 * Mientras hay un job en cola/ejecución hace polling cada 7 s de `periods` y `groupedUploads`.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { toast } from '@/components/feedback/Toast';
import AutomaticPeriodDetail from '@/components/historico-general/AutomaticPeriodDetail.vue';
import DatabaseUpdateStep from '@/components/historico-general/DatabaseUpdateStep.vue';
import ExpenseReviewStep from '@/components/historico-general/expense-review/ExpenseReviewStep.vue';
import GeneratedReportActions from '@/components/historico-general/GeneratedReportActions.vue';
import GenerateReportStep from '@/components/historico-general/GenerateReportStep.vue';
import IncidentsStep from '@/components/historico-general/IncidentsStep.vue';
import PeriodSelector from '@/components/historico-general/PeriodSelector.vue';
import ReportConfigurationStep from '@/components/historico-general/ReportConfigurationStep.vue';
import ReportPreview from '@/components/historico-general/ReportPreview.vue';
import UploadSourcesStep from '@/components/historico-general/UploadSourcesStep.vue';
import WorkflowStepper from '@/components/historico-general/WorkflowStepper.vue';
import { useHistoricWorkflow } from '@/composables/useHistoricWorkflow';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

/** Props que entrega el controlador por Inertia (ver comentario de cabecera). */
const props = defineProps<{
    periods: any[];
    sources: any[];
    groupedUploads: any[];
    currentPeriodId: number | null;
    preview: any | null;
    branches: Array<{ id: number; name: string }>;
    employees: Array<{ id: number; full_name: string; branch_name?: string }>;
}>();

/**
 * Las 13 sucursales operativas oficiales. Se usan solo para preseleccionar
 * `included_branch_ids` en la configuración inicial del reporte general.
 */
const OPERATIVE_BRANCH_NAMES_INIT = new Set([
    'ATLACOMULCO',
    'ATLIXCO',
    'CORDOBA',
    'CUERNAVACA',
    'HUAMANTLA',
    'IXTLAHUACA',
    'MIACATLAN',
    'ORIZABA',
    'SAN JUAN DEL RÍO',
    'SAN LUIS POTOSI',
    'TENANGO DEL VALLE',
    'TLAXCALA',
    'TULA',
]);

/** Periodo seleccionado en el selector (null = ninguno; muestra el estado vacío). */
const selectedPeriodId = ref<number | null>(props.currentPeriodId ?? null);
/** Roster canónico de colaboradores del periodo actual (se recarga al cambiar de periodo). */
const periodEmployees = ref<
    Array<{ id: number; full_name: string; branch_name?: string }>
>(props.employees ?? []);
/** Incidencias del periodo (GET /historico-general/{periodo}/incidencias). */
const incidents = ref<any[]>([]);
/** Si el periodo tiene datos cargados para evaluar incidencias; null = aún no consultado. */
const incidentsHasData = ref<boolean | null>(null); // null = aún no se carga
/** Mensaje del backend cuando no hay datos para incidencias. */
const incidentsNoDataMsg = ref<string>('');
/** Contador que se incrementa para forzar a IncidentsStep a recargar sus paneles internos. */
const incidentsReloadKey = ref(0);
/** Referencia al componente IncidentsStep (expone clearPairLoading/onPairConfirmed/onPairRejected). */
const incidentsStepRef = ref<any>(null);
/**
 * Configuración del reporte (Etapa «Configurar»): tipo (simple/comparativo), alcance
 * (general/sucursal/gestor), periodo a comparar, ajuste manual efímero de gasto por
 * colaborador y sucursales incluidas en el general.
 */
const reportConfig = ref({
    report_type: 'simple',
    scope: 'general',
    branch_id: null as number | null,
    employee_id: null as number | null,
    compare_period_id: null as number | null,
    extra_employee_expense_amount: 0,
    extra_employee_expense_notes: '',
    included_branch_ids: props.branches
        .filter((b) =>
            OPERATIVE_BRANCH_NAMES_INIT.has(b.name.trim().toUpperCase()),
        )
        .map((b) => b.id),
});

// PROBLEMA 2/4/5 (auditoría 27-ago-2026): period.radiography_ready/can_export_radiography
// SIEMPRE describen la identidad simple/general del periodo (ver ReportUploadController::
// index()) — para cualquier otro alcance (sucursal/gestor/comparativo), esos campos
// pertenecen a un run distinto. GenerateReportStep ya resuelve el estado por identidad
// exacta vía generationProgress() (pollProgress()); este ref reenvía ese resultado a
// Etapa 6 (ReportPreview)/Etapa 7 (GeneratedReportActions) para que dejen de mezclar
// alcances. Solo se usa cuando su identidad coincide con reportConfig actual — si el
// usuario cambia la configuración sin volver a pasar por Etapa 5, no se reutiliza un
// estado de una identidad distinta (ver coincide() en cada componente consumidor).
const identityState = ref<any>(null);
/**
 * Recibe el evento `identity-state` de GenerateReportStep y guarda el estado del run
 * correspondiente a la identidad exacta de la configuración.
 * @param payload Estado devuelto por el polling de progreso de generación.
 */
const handleIdentityState = (payload: any) => {
    identityState.value = payload;
};

/** Objeto del periodo seleccionado (con su estado de flujo) o null. */
const period = computed(
    () => props.periods.find((p) => p.id === selectedPeriodId.value) ?? null,
);
/** Grupo de cargas del periodo seleccionado (uploads + contadores pendientes/con error). */
const grouped = computed(
    () =>
        props.groupedUploads.find(
            (p) => p.period_id === selectedPeriodId.value,
        ) ?? null,
);
/** Mapa código de fuente → carga vigente del periodo, para pintar cada tarjeta de fuente. */
const uploadsBySource = computed(() => {
    const map: Record<string, any> = {};

    for (const u of grouped.value?.uploads ?? []) {
        map[u.source_code] = u;
    }

    return map;
});

// Fase 8.6 (presentación): qué fuentes obligatorias faltan, para explicar por qué
// "Siguiente etapa" está deshabilitado. No cambia ninguna regla: la habilitación
// sigue siendo period.can_update_database del backend.
const missingRequiredSources = computed(() =>
    props.sources
        .filter(
            (s: any) =>
                s.is_required_for_bd &&
                !['rotacion', 'imss'].includes(s.code) &&
                !uploadsBySource.value[s.code],
        )
        .map((s: any) => s.name as string),
);

/**
 * Verdadero cuando la configuración del reporte está completa para avanzar:
 * tipo elegido, periodo comparable si no es simple, sucursal/gestor según alcance
 * y al menos una sucursal incluida en el alcance general.
 */
const configValid = computed(() => {
    const cfg = reportConfig.value;

    if (!cfg.report_type) {
        return false;
    }

    if (cfg.report_type !== 'simple' && !cfg.compare_period_id) {
        return false;
    }

    if (cfg.scope === 'branch') {
        return cfg.branch_id !== null;
    }

    if (cfg.scope === 'employee') {
        return cfg.employee_id !== null;
    }

    return cfg.included_branch_ids.length > 0;
});

/** Estado del stepper: etapa actual, lista de etapas con su estatus y selector de etapa. */
const { currentStep, steps, selectStep } = useHistoricWorkflow(
    period,
    incidents,
    configValid,
);

// Enlace directo «Revisar gastos» desde Periodos (?periodo=ID&paso=gastos).
if (
    typeof window !== 'undefined' &&
    new URLSearchParams(window.location.search).get('paso') === 'gastos'
) {
    selectStep('expenses');
}

/** Formulario Inertia compartido para subir un archivo de fuente (POST /historico-general). */
const form = useForm({
    period_id: '',
    data_source_id: '',
    file: null as File | null,
    notes: '',
    covered_period_ids: [] as number[],
});

// ── Carga de incidencias ──────────────────────────────────────────────
/**
 * Consulta las incidencias del periodo seleccionado y actualiza `incidents`,
 * `incidentsHasData` e `incidentsNoDataMsg`. Si el periodo estaba bloqueado para
 * generar y ya no quedan críticas, recarga la prop `periods` para desbloquear el flujo
 * sin recargar toda la página.
 * @returns Promesa que resuelve al terminar la consulta.
 */
async function loadIncidents() {
    if (!selectedPeriodId.value) {
        return;
    }

    const wasBlocked = !period.value?.can_generate_radiography;
    const res = await fetch(
        `/historico-general/${selectedPeriodId.value}/incidencias`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
    );
    const data = await res.json();
    incidents.value = data.items ?? [];
    incidentsHasData.value = data.has_data !== false; // undefined = true (compatibilidad con API anterior)
    incidentsNoDataMsg.value = data.message ?? '';

    // Si el periodo estaba bloqueado y ya se resolvieron todas las críticas, se recarga la
    // prop `periods` para que can_generate_radiography se actualice sin recargar la página.
    if (wasBlocked && data.has_data !== false && data.has_critical === false) {
        router.reload({ only: ['periods'] });
    }
}

// El colaborador debe seleccionarse SIEMPRE del roster canónico del periodo en
// curso (deduplicado, sucursal resuelta) — nunca de la lista estática que llegó
// en el primer render de la página, que corresponde a otro periodo en cuanto el
// usuario cambia la selección en el stepper.
/**
 * Carga el roster canónico del periodo (GET /historico-general/{periodo}/colaboradores).
 * Ante error deja la lista vacía.
 */
async function loadPeriodEmployees() {
    if (!selectedPeriodId.value) {
        periodEmployees.value = [];

        return;
    }

    try {
        const res = await fetch(
            `/historico-general/${selectedPeriodId.value}/colaboradores`,
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
        );
        const data = await res.json();
        periodEmployees.value = data.employees ?? [];
    } catch {
        periodEmployees.value = [];
    }
}

/**
 * Al cambiar de periodo: limpia incidencias, regresa a la etapa «Archivos», recarga el
 * roster del periodo y, si ya se cargaron registros, consulta sus incidencias.
 * Se ejecuta también al montar (immediate).
 */
watch(
    selectedPeriodId,
    () => {
        incidents.value = [];
        incidentsHasData.value = null;
        incidentsNoDataMsg.value = '';
        currentStep.value = 'files';
        loadPeriodEmployees();

        // Solo cargar incidencias si los registros ya fueron cargados
        if (period.value?.database_updated) {
            loadIncidents();
        }
    },
    { immediate: true },
);

// ── Polling ──────────────────────────────────────────────────────────
/** Hay una carga de registros a BD en cola o ejecutándose. */
const isDbUpdating = computed(() =>
    ['queued', 'running'].includes(period.value?.database_update_run_status),
);
/** Hay una generación de reporte (radiografía) en cola o ejecutándose. */
const isRadioGenerating = computed(() =>
    ['queued', 'running'].includes(period.value?.radiography_run_status),
);

/** Identificador del intervalo de polling activo (null = sin polling). */
let pollInterval: ReturnType<typeof setInterval> | null = null;

/** Inicia (si no existe) el polling cada 7 s que recarga `periods` y `groupedUploads`. */
function startPolling() {
    if (pollInterval) {
        return;
    }

    pollInterval = setInterval(() => {
        router.reload({ only: ['periods', 'groupedUploads'] });
    }, 7000);
}

/** Detiene el polling si está activo. También se llama al desmontar la página. */
function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

/** Enciende el polling mientras haya algún proceso en cola/ejecución y lo apaga al terminar. */
watch(
    [isDbUpdating, isRadioGenerating],
    ([db, radio]) => {
        if (db || radio) {
            startPolling();
        } else {
            stopPolling();
        }
    },
    { immediate: true },
);

/**
 * Notifica cuando la carga de registros termina (queued/running → success/failed/cancelled):
 * en éxito recarga incidencias y ofrece ir a ellas; en fallo muestra el error y lleva a «Cargar registros».
 */
watch(
    () => period.value?.database_update_run_status,
    (newStatus, oldStatus) => {
        const wasRunning = ['queued', 'running'].includes(
            String(oldStatus ?? ''),
        );

        if (!wasRunning) {
            return;
        }

        if (newStatus === 'success') {
            loadIncidents().then(() => {
                Swal.fire({
                    title: 'Registros cargados',
                    html: `Los registros del periodo <strong>${period.value?.label ?? ''}</strong> se cargaron correctamente.<br><br>Puedes revisar las incidencias y continuar.`,
                    icon: 'success',
                    confirmButtonText: 'Revisar incidencias',
                    showCancelButton: true,
                    cancelButtonText: 'Continuar después',
                    reverseButtons: true,
                }).then((r) => {
                    if (r.isConfirmed) {
                        selectStep('incidents');
                    }
                });
            });
        } else if (newStatus === 'failed') {
            Swal.fire({
                title: 'La carga de registros falló',
                text:
                    period.value?.database_update_run_error ??
                    'El proceso terminó con error. Revisa el detalle en la etapa de Cargar registros.',
                icon: 'error',
                confirmButtonText: 'Ver detalle',
            }).then(() => selectStep('load'));
        } else if (newStatus === 'cancelled') {
            Swal.fire({
                title: 'Carga cancelada',
                text: 'El proceso fue cancelado. Puedes reiniciarlo cuando estés listo.',
                icon: 'info',
                confirmButtonText: 'Entendido',
            });
        }
    },
);

/**
 * Notifica cuando la generación del reporte termina (queued/running → success/failed/cancelled).
 * Un run 'failed' con radiography_ready=true se trata como éxito (ver comentario interno).
 */
watch(
    () => period.value?.radiography_run_status,
    (newStatus, oldStatus) => {
        const wasRunning = ['queued', 'running'].includes(
            String(oldStatus ?? ''),
        );

        if (!wasRunning) {
            return;
        }

        if (
            newStatus === 'success' ||
            (newStatus === 'failed' && period.value?.radiography_ready)
        ) {
            // El run más reciente puede marcar 'failed' (ej. un paso posterior a la
            // consolidación, como el resolver de gastos) mientras el reporte en sí ya
            // quedó generado correctamente (radiography_ready=true). En ese caso el
            // resultado real es éxito — mostrar error aquí sería un falso negativo.
            Swal.fire({
                title: '¡Reporte generado!',
                html: `El reporte del periodo <strong>${period.value?.label ?? ''}</strong> ya está listo.<br><br>Descarga Excel y PDF desde el paso de reporte o en Reportes mensuales.`,
                icon: 'success',
                confirmButtonText: 'Ver resultado',
            }).then(() => {
                if (!period.value?.is_derived) {
                    selectStep('generate');
                }
            });
        } else if (newStatus === 'failed') {
            Swal.fire({
                title: 'La generación falló',
                text:
                    period.value?.radiography_run_error ??
                    'El proceso terminó con error. Revisa el detalle en la etapa de generar reporte.',
                icon: 'error',
                confirmButtonText: 'Ver detalle',
            }).then(() => selectStep('generate'));
        } else if (newStatus === 'cancelled') {
            Swal.fire({
                title: 'Generación cancelada',
                text: 'El proceso fue cancelado. Puedes reiniciarlo cuando estés listo.',
                icon: 'info',
                confirmButtonText: 'Entendido',
            });
        }
    },
);

onUnmounted(stopPolling);

// ── Helpers ───────────────────────────────────────────────────────────
/**
 * Muestra un aviso de advertencia (SweetAlert) con un solo botón «Entendido».
 * @param title Título del aviso.
 * @param text Explicación para el usuario.
 */
const toastError = (title: string, text: string) =>
    Swal.fire({ title, text, icon: 'warning', confirmButtonText: 'Entendido' });

// ── Acciones ──────────────────────────────────────────────────────────
/** Bloquea cargas concurrentes mientras una subida está en curso. */
const isUploadingSource = ref(false);

/**
 * Sube el archivo de una fuente al periodo seleccionado (POST /historico-general, multipart).
 * Valida antes que el periodo sea un mes operativo configurado (no semana base ni derivado)
 * y que no haya otra subida en curso. Muestra progreso real de transferencia y, al terminar,
 * los motivos reales de rechazo que devuelva el servidor.
 * @param payload.sourceId Id de la fuente de datos (data_source_id).
 * @param payload.file Archivo seleccionado por el usuario.
 */
const uploadFile = async ({
    sourceId,
    file,
}: {
    sourceId: number;
    file: File;
}) => {
    if (!period.value) {
        return toastError(
            'Selecciona periodo',
            'Elige un periodo antes de cargar archivos.',
        );
    }

    if (period.value.type === 'weekly') {
        return toastError(
            'Semana base',
            'Los archivos se suben al mes operativo que agrupa esta semana. Selecciona el mes operativo correspondiente.',
        );
    }

    if (period.value.is_derived) {
        return toastError(
            'Periodo automático',
            'Este periodo es automático (bimestral/trimestral/etc.) y no recibe archivos directos.',
        );
    }

    if (!period.value.can_receive_uploads) {
        return toastError(
            'Mes sin configurar',
            'Este mes operativo no tiene semanas asociadas. Ve a Periodos para configurarlo primero.',
        );
    }

    // Nunca dos cargas a la vez con el mismo form compartido: evita que un doble
    // clic (u otra tarjeta) dispare una segunda visita de Inertia mientras la
    // primera sigue en curso, que es justo lo que puede desincronizar el overlay
    // y duplicar la fuente guardada.
    if (isUploadingSource.value || form.processing) {
        void toast.fire({
            icon: 'info',
            title: 'Ya hay una carga en curso. Espera a que termine.',
        });

        return;
    }

    form.period_id = String(selectedPeriodId.value);
    form.data_source_id = String(sourceId);
    form.file = file;
    form.covered_period_ids = period.value?.component_period_ids?.length
        ? (period.value.component_period_ids as number[])
        : [selectedPeriodId.value as number];

    isUploadingSource.value = true;
    let settledByCallback = false;

    Swal.fire({
        title: 'Subiendo archivo',
        html: 'Validando formato y guardando fuente… <strong>0%</strong>',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
    });

    form.post('/historico-general', {
        forceFormData: true,
        preserveScroll: true,
        // Porcentaje REAL de transferencia (Inertia lo calcula del propio XHR de
        // subida) — nunca un valor inventado; si el navegador no lo reporta, el
        // texto simplemente no cambia de "0%" hasta que termine.
        onProgress: (event) => {
            if (typeof event?.percentage === 'number' && Swal.isVisible()) {
                Swal.update({
                    html: `Subiendo archivo… <strong>${event.percentage}%</strong>`,
                });
            }
        },
        onSuccess: () => {
            settledByCallback = true;
            // Fase 8.6: cargado ≠ procesado — el mensaje lo dice explícitamente.
            Swal.fire({
                icon: 'success',
                title: 'Archivo cargado',
                html: 'Quedó guardado para este periodo. Su tarjeta indica si ya está <strong>Procesado</strong> o si sigue <strong>Sin procesar</strong>; en ese caso usa «Procesar fuentes pendientes».',
                confirmButtonText: 'Entendido',
            });
        },
        onError: (errors) => {
            settledByCallback = true;
            // Fase 8.6: el motivo REAL que devolvió el servidor (columna faltante,
            // periodo que no coincide, archivo de otra fuente…), no uno genérico.
            const reasons = Object.values(errors ?? {}).filter(
                (m): m is string => typeof m === 'string' && m.trim() !== '',
            );
            const escapeHtml = (text: string) =>
                text.replace(
                    /[&<>"']/g,
                    (c) =>
                        ({
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#39;',
                        })[c] as string,
                );
            const list = reasons
                .map((m) => `<li>${escapeHtml(m)}</li>`)
                .join('');
            Swal.fire({
                icon: 'error',
                title: 'No se pudo cargar el archivo',
                html: reasons.length
                    ? `<ul style="text-align:left;margin:0;padding-left:1.1rem">${list}</ul><p style="margin-top:.75rem">Corrige el archivo y vuelve a subirlo.</p>`
                    : 'Revisa que sea el archivo correcto de esta fuente, del periodo seleccionado y en el formato indicado.',
                confirmButtonText: 'Entendido',
            });
        },
        onCancel: () => {
            settledByCallback = true;
            Swal.fire(
                'Carga cancelada',
                'La subida se interrumpió antes de terminar.',
                'info',
            );
        },
        onFinish: () => {
            isUploadingSource.value = false;

            // Red de seguridad: si por una excepción de red u otra causa no prevista
            // ninguno de los callbacks anteriores se ejecutó, el diálogo de "Subiendo…"
            // nunca debe quedarse abierto para siempre — se cierra con un aviso real,
            // nunca fingiendo que terminó bien.
            if (!settledByCallback && Swal.isVisible()) {
                Swal.fire(
                    'No se pudo confirmar la carga',
                    'La conexión se interrumpió. Verifica si el archivo quedó registrado antes de volver a intentarlo.',
                    'warning',
                );
            }
        },
    });
};

/**
 * Pide confirmación y elimina una carga de fuente (DELETE /historico-general/{id}).
 * @param id Id de la carga (report upload).
 */
const deleteUpload = async (id: number) => {
    const result = await Swal.fire({
        title: '¿Eliminar archivo?',
        text: 'Se quitará esta fuente del periodo.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.delete(`/historico-general/${id}`, {
        preserveScroll: true,
        onSuccess: () =>
            Swal.fire(
                'Archivo eliminado',
                'La fuente fue retirada correctamente.',
                'success',
            ),
        onError: () =>
            Swal.fire('No se pudo eliminar', 'Intenta nuevamente.', 'error'),
    });
};

/**
 * Encola la carga de registros a BD del periodo (POST .../actualizar-bd), previa confirmación.
 * El usuario recibe correo al terminar.
 */
const updateDatabase = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const result = await Swal.fire({
        title: 'Cargar registros',
        text: 'Se enviará el proceso a cola. Recibirás un correo cuando termine.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Enviar a cola',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/actualizar-bd`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Proceso en cola',
                    text: 'Puedes cerrar esta ventana. Te avisaremos por correo cuando los registros terminen de cargarse.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo iniciar',
                    'Faltan fuentes o ya hay un proceso en ejecución.',
                    'error',
                ),
        },
    );
};

// ── Incidencias críticas — bloquean Paso 4 ───────────────────────────
/** Hay incidencias de severidad alta/crítica: bloquean la etapa de configuración del reporte. */
const hasCriticalIncidents = computed(() =>
    incidents.value.some(
        (i) =>
            ['high', 'critical'].includes(i.severity) &&
            i.severity !== 'resolved',
    ),
);

/** Cancela la carga de registros en curso (POST .../actualizacion-bd/cancelar), previa confirmación. */
const cancelDatabaseUpdate = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const result = await Swal.fire({
        title: '¿Cancelar la carga de registros?',
        text: 'El proceso se marcará como cancelado. Podrás reiniciarlo desde la etapa de Cargar registros.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar proceso',
        cancelButtonText: 'No, dejar corriendo',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/actualizacion-bd/cancelar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Carga cancelada',
                    text: 'Puedes reiniciar la carga cuando estés listo.',
                    icon: 'info',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo cancelar',
                    'El proceso ya terminó o no existe.',
                    'error',
                ),
        },
    );
};

/**
 * Ejecuta la carga de registros de forma síncrona sin worker (POST .../actualizacion-bd/procesar-ahora).
 * Pensado para entorno local; la petición espera hasta que el job termine.
 */
const processNow = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const result = await Swal.fire({
        title: 'Procesar ahora (local)',
        text: 'Ejecuta el job directamente sin worker. La página esperará hasta que termine (puede tardar varios minutos).',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Procesar ahora',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    Swal.fire({
        title: 'Procesando…',
        text: 'Ejecutando carga de registros directamente. No cierres esta ventana.',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
    });
    router.post(
        `/historico-general/${selectedPeriodId.value}/actualizacion-bd/procesar-ahora`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Listo',
                    text: 'El proceso terminó. Revisa el resultado.',
                    icon: 'success',
                    confirmButtonText: 'Ver resultado',
                }),
            onError: () =>
                Swal.fire({
                    title: 'El proceso terminó con error',
                    text: 'Revisa el detalle en la etapa de carga.',
                    icon: 'error',
                    confirmButtonText: 'Ver detalle',
                }).then(() => selectStep('load')),
        },
    );
};

/**
 * Envía a procesamiento las cargas pendientes (IMSS, Rotación, etc.) sin volver a cargar
 * los registros base (POST .../procesar-fuentes-pendientes).
 */
const processPendingSources = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const pendingCount = grouped.value?.pending_count ?? 0;

    if (!pendingCount) {
        return Swal.fire({
            title: 'Sin fuentes pendientes',
            text: 'No hay archivos pendientes de procesar en este periodo.',
            icon: 'info',
            confirmButtonText: 'Entendido',
        });
    }

    const result = await Swal.fire({
        title: 'Procesar fuentes pendientes',
        html: `Se enviarán a procesamiento <strong>${pendingCount}</strong> archivo(s) pendiente(s) (IMSS, Rotación, etc.), sin volver a cargar los registros base.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Procesar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/procesar-fuentes-pendientes`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Procesamiento enviado',
                    text: 'Las fuentes están en cola. Recibirás correo cuando terminen.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo iniciar',
                    'Verifica que haya archivos pendientes y vuelve a intentarlo.',
                    'error',
                ),
        },
    );
};

/**
 * Reprocesa solo las fuentes vigentes en error (NOI Nómina/Fiscal, Rotación, IMSS)
 * (POST .../reprocesar-fuentes-con-error); no toca Cobranza, Cartera, Colocación ni Gastos.
 */
const reprocessFailedSources = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const failedCount = grouped.value?.failed_count ?? 0;

    if (!failedCount) {
        return Swal.fire({
            title: 'Sin fuentes con error',
            text: 'No hay archivos en error en este periodo.',
            icon: 'info',
            confirmButtonText: 'Entendido',
        });
    }

    const result = await Swal.fire({
        title: 'Reprocesar fuentes con error',
        html: `Se reprocesarán únicamente las fuentes vigentes que estén en error (NOI Nómina, NOI Fiscal, Rotación e IMSS si su auditoría falla). No se tocará Cobranza, Cartera, Colocación ni Gastos.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Reprocesar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/reprocesar-fuentes-con-error`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Reprocesamiento enviado',
                    text: 'Las fuentes con error están en cola. Recibirás correo cuando terminen.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo iniciar',
                    'Verifica que haya fuentes en error y vuelve a intentarlo.',
                    'error',
                ),
        },
    );
};

/** Vuelve a encolar el job de carga de registros cuyo run quedó en cola sin job (POST .../actualizacion-bd/reencolar). */
const requeueRun = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/actualizacion-bd/reencolar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Job reencolado',
                    text: 'Inicia el worker para procesarlo: php artisan queue:work --tries=1 --timeout=0 -vvv',
                    icon: 'info',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo reencolar',
                    'El run ya no está en cola o el job ya existe.',
                    'error',
                ),
        },
    );
};

/** Marca como cancelado un proceso de carga atascado para poder reiniciarlo (POST .../actualizacion-bd/limpiar). */
const clearStuckDatabaseUpdate = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const result = await Swal.fire({
        title: 'Limpiar estado atascado',
        text: 'Se marcará el proceso como cancelado para que puedas volver a iniciar la carga.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Limpiar estado',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/actualizacion-bd/limpiar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Estado limpiado',
                    text: 'Ya puedes reiniciar la carga de registros.',
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo limpiar',
                    'No hay proceso atascado o ya fue limpiado.',
                    'error',
                ),
        },
    );
};

/**
 * Solicita una nota de resolución y marca la incidencia como resuelta
 * (POST .../incidencias/{id}/resolver); después recarga incidencias.
 * @param id Id de la incidencia.
 */
const resolveIncident = async (id: number) => {
    const result = await Swal.fire({
        title: 'Resolver incidencia',
        input: 'textarea',
        inputLabel: 'Nota de resolución',
        inputPlaceholder: 'Describe cómo quedó resuelta…',
        showCancelButton: true,
        confirmButtonText: 'Guardar resolución',
        cancelButtonText: 'Cancelar',
        inputValidator: (value) =>
            !value ? 'Captura una nota de resolución.' : undefined,
    });

    if (!result.isConfirmed || !selectedPeriodId.value) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/incidencias/${id}/resolver`,
        { resolution_note: result.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                Swal.fire(
                    'Incidencia resuelta',
                    'El estado del flujo se actualizó.',
                    'success',
                );
                loadIncidents();
            },
            onError: () =>
                Swal.fire(
                    'No se pudo resolver',
                    'Intenta nuevamente.',
                    'error',
                ),
        },
    );
};

/**
 * Asigna sucursal a una o varias personas sin sucursal desde Incidencias
 * (POST /empleados/batch-asignar-sucursal). Después recarga incidencias, fuerza la recarga
 * de los paneles internos y refresca `periods`.
 * @param payload.employee_ids Ids a asignar en lote (si viene vacío se usa employee_id).
 * @param payload.employee_id Id individual de respaldo.
 * @param payload.branch_id Sucursal destino.
 * @param payload.period_id Periodo de vigencia de la asignación.
 */
const assignBranchFromIncident = async ({
    employee_ids,
    employee_id,
    branch_id,
    period_id,
}: {
    employee_ids?: number[];
    employee_id: number;
    branch_id: number;
    period_id: number;
}) => {
    if (!selectedPeriodId.value) {
        return;
    }

    const ids =
        employee_ids && employee_ids.length > 0 ? employee_ids : [employee_id];
    const csrf =
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
            ?.content ?? '';

    try {
        const res = await fetch('/empleados/batch-asignar-sucursal', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                employee_ids: ids,
                branch_id,
                period_id,
                notes: 'Asignado desde incidencias.',
            }),
        });

        if (!res.ok) {
            throw new Error();
        }

        // loadIncidents ya dispara los refrescos ligeros (sin-sucursal + coincidencias)
        await loadIncidents();
        incidentsReloadKey.value++;
        // Refresca `periods` para que can_generate_radiography refleje lo resuelto
        router.reload({ only: ['periods'] });
        Swal.fire({
            title: 'Sucursal asignada',
            text: 'La persona fue asignada correctamente.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false,
        });
    } catch {
        Swal.fire('No se pudo asignar', 'Intenta nuevamente.', 'error');
    }
};

/**
 * Confirma que una persona sin sucursal es la misma que otra ya existente y las unifica
 * (POST .../personas-sin-sucursal/confirmar-coincidencia). Si la destino tiene sucursal, se hereda.
 * @param payload.employee_id Persona origen (sin sucursal).
 * @param payload.target_employee_id Persona destino con la que se unifica.
 * @param payload.branch_id Sucursal opcional a asignar.
 * @param payload.canonical_name Nombre definitivo opcional.
 */
const confirmMatchFromIncident = async ({
    employee_id,
    target_employee_id,
    branch_id,
    canonical_name,
}: {
    employee_id: number;
    target_employee_id: number;
    branch_id?: number;
    canonical_name?: string;
}) => {
    if (!selectedPeriodId.value) {
        return;
    }

    try {
        const csrf =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement
            )?.content ?? '';
        const body: Record<string, any> = { employee_id, target_employee_id };

        if (branch_id != null) {
            body.branch_id = branch_id;
        }

        if (canonical_name?.trim()) {
            body.canonical_name = canonical_name.trim();
        }

        const res = await fetch(
            `/historico-general/${selectedPeriodId.value}/personas-sin-sucursal/confirmar-coincidencia`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            },
        );

        if (!res.ok) {
            const body = await res.json().catch(() => ({}));

            throw new Error((body as any).message || `Error ${res.status}`);
        }

        const data = await res.json();
        await loadIncidents();
        incidentsReloadKey.value++;
        const text = data.branch_assigned
            ? 'La persona fue unificada y se heredó la sucursal correspondiente.'
            : 'La persona fue unificada. Aún requiere asignar sucursal.';
        Swal.fire({
            title: 'Coincidencia confirmada',
            text,
            icon: 'success',
            timer: 3000,
            showConfirmButton: false,
        });
    } catch (e: any) {
        Swal.fire(
            'No se pudo confirmar',
            e?.message || 'Intenta nuevamente.',
            'error',
        );
    }
};

// ── Coincidencias desde panel de posibles duplicados ─────────────────
/**
 * Unifica un par de posibles duplicados desde el panel de coincidencias de IncidentsStep.
 * Paso 1: elegir el nombre definitivo (A, B o personalizado); paso 2 opcional: capturarlo.
 * Luego llama a .../personas-sin-sucursal/confirmar-coincidencia y notifica al panel
 * (onPairConfirmed / clearPairLoading) para administrar el estado de carga del par.
 * @param payload.a_id Id de la persona A.
 * @param payload.b_id Id de la persona B (destino).
 * @param payload.a_name Nombre mostrado de A.
 * @param payload.b_name Nombre mostrado de B.
 * @param payload.pair_key Clave del par en el panel.
 */
/** Escapa texto que viene de datos (nombres) antes de meterlo en el `html` de SweetAlert. */
const escapeHtml = (value: string): string =>
    String(value ?? '').replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c] as string,
    );

const confirmCoincidenciaFromDuplicates = async ({
    a_id,
    b_id,
    a_name,
    b_name,
    pair_key,
}: {
    a_id: number;
    b_id: number;
    a_name: string;
    b_name: string;
    pair_key: string;
}) => {
    // Paso 1: elegir nombre canónico
    const step1 = await Swal.fire({
        title: 'Confirmar coincidencia de persona',
        html: `
            <p class="text-sm text-gray-600 dark:text-slate-400 mb-4">Estas dos entradas serán unificadas como <strong>una sola persona</strong>. Elige qué nombre queda como definitivo:</p>
            <div class="flex flex-col gap-3 text-left">
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <input type="radio" name="swal_canon" value="a" class="mt-0.5 accent-primary">
                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100">${escapeHtml(a_name)}</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <input type="radio" name="swal_canon" value="b" class="mt-0.5 accent-primary">
                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100">${escapeHtml(b_name)}</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    <input type="radio" name="swal_canon" value="custom" class="mt-0.5 accent-primary">
                    <span class="text-sm text-slate-600 dark:text-slate-400">Escribir nombre personalizado…</span>
                </label>
            </div>
        `,
        preConfirm: () => {
            const checked = document.querySelector<HTMLInputElement>(
                'input[name="swal_canon"]:checked',
            );

            if (!checked) {
                Swal.showValidationMessage(
                    'Selecciona una opción para el nombre definitivo.',
                );

                return false;
            }

            return checked.value;
        },
        showCancelButton: true,
        confirmButtonText: 'Confirmar unificación',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
        focusConfirm: false,
    });

    if (!step1.isConfirmed) {
        incidentsStepRef.value?.clearPairLoading(pair_key);

        return;
    }

    let canonicalName = '';

    if (step1.value === 'a') {
        canonicalName = a_name;
    } else if (step1.value === 'b') {
        canonicalName = b_name;
    } else {
        // Paso 2: capturar nombre personalizado
        const step2 = await Swal.fire({
            title: 'Nombre canónico personalizado',
            input: 'text',
            inputLabel: 'Escribe el nombre definitivo para esta persona',
            inputPlaceholder: 'Ej: GARCIA LOPEZ JUAN PABLO',
            inputAttributes: {
                autocomplete: 'off',
                style: 'text-transform: uppercase',
            },
            showCancelButton: true,
            confirmButtonText: 'Guardar',
            cancelButtonText: 'Volver',
            reverseButtons: true,
            inputValidator: (value) =>
                !value?.trim() ? 'Ingresa el nombre definitivo.' : undefined,
        });

        if (!step2.isConfirmed) {
            incidentsStepRef.value?.clearPairLoading(pair_key);

            return;
        }

        canonicalName = (step2.value as string).trim();
    }

    // Protección: los IDs deben venir (incidencias antiguas pueden no traerlos)
    if (!a_id || !b_id) {
        incidentsStepRef.value?.clearPairLoading(pair_key);
        Swal.fire(
            'Sin IDs válidos',
            'Esta coincidencia no tiene IDs. Pulsa Refrescar en la cabecera y vuelve a intentarlo.',
            'warning',
        );

        return;
    }

    // Unificación directa (para controlar el estado del par en éxito/error)
    if (!selectedPeriodId.value) {
        return;
    }

    try {
        const csrf =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement
            )?.content ?? '';
        const res = await fetch(
            `/historico-general/${selectedPeriodId.value}/personas-sin-sucursal/confirmar-coincidencia`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    employee_id: a_id,
                    target_employee_id: b_id,
                    canonical_name: canonicalName,
                }),
            },
        );

        if (!res.ok) {
            const body = await res.json().catch(() => ({}));

            throw new Error((body as any).message || `Error ${res.status}`);
        }

        const data = await res.json();
        // loadIncidents dispara los refrescos ligeros de ambos contadores
        await loadIncidents();
        incidentsReloadKey.value++;
        incidentsStepRef.value?.onPairConfirmed(pair_key);
        const text = data.branch_assigned
            ? 'La persona fue unificada y se heredó la sucursal correspondiente.'
            : 'La persona fue unificada. Aún requiere asignar sucursal.';
        Swal.fire({
            title: 'Coincidencia confirmada',
            text,
            icon: 'success',
            timer: 3000,
            showConfirmButton: false,
        });
    } catch (e: any) {
        incidentsStepRef.value?.clearPairLoading(pair_key);
        Swal.fire(
            'No se pudo confirmar',
            e?.message || 'Intenta nuevamente.',
            'error',
        );
    }
};

/**
 * Marca un par sugerido como «personas diferentes» (POST .../coincidencias/descartar) para que
 * no vuelva a sugerirse. No elimina datos. Notifica al panel con onPairRejected/clearPairLoading.
 * @param payload.a_id Id de la persona A.
 * @param payload.b_id Id de la persona B.
 * @param payload.pair_key Clave del par en el panel.
 */
const discardCoincidenciaFromDuplicates = async ({
    a_id,
    b_id,
    pair_key,
}: {
    a_id: number;
    b_id: number;
    pair_key: string;
}) => {
    if (!selectedPeriodId.value) {
        return;
    }

    // Confirmar antes de guardar el rechazo
    const confirm = await Swal.fire({
        title: '¿Son personas diferentes?',
        text: 'No se eliminará nada. Solo se guardará que este par no debe volver a sugerirse como posible coincidencia.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, son diferentes',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!confirm.isConfirmed) {
        incidentsStepRef.value?.clearPairLoading(pair_key);

        return;
    }

    try {
        const csrf =
            (
                document.querySelector(
                    'meta[name="csrf-token"]',
                ) as HTMLMetaElement
            )?.content ?? '';
        const res = await fetch(
            `/historico-general/${selectedPeriodId.value}/coincidencias/descartar`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    employee_id_a: a_id,
                    employee_id_b: b_id,
                }),
            },
        );

        if (!res.ok) {
            const body = await res.json().catch(() => ({}));

            throw new Error((body as any).message || `Error ${res.status}`);
        }

        await loadIncidents();
        incidentsStepRef.value?.onPairRejected(pair_key);
    } catch (e: any) {
        incidentsStepRef.value?.clearPairLoading(pair_key);
        Swal.fire(
            'No se pudo descartar',
            e?.message || 'Intenta nuevamente.',
            'error',
        );
    }
};

/**
 * Resuelve una incidencia de ubicación pendiente (POST .../incidencias/ubicacion-pendiente/resolver).
 * @param payload.incident_id Id de la incidencia.
 * @param payload.action Acción elegida (p. ej. asignar sucursal o clasificar).
 * @param payload.branch_id Sucursal elegida, o null si la acción no la requiere.
 */
const resolveLocationFromIncident = ({
    incident_id,
    action,
    branch_id,
}: {
    incident_id: number;
    action: string;
    branch_id: number | null;
}) => {
    if (!selectedPeriodId.value) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/incidencias/ubicacion-pendiente/resolver`,
        { incident_id, action, branch_id },
        {
            preserveScroll: true,
            onSuccess: () => {
                Swal.fire(
                    'Ubicación resuelta',
                    'La ubicación fue clasificada correctamente.',
                    'success',
                );
                loadIncidents();
            },
            onError: () =>
                Swal.fire(
                    'No se pudo resolver',
                    'Intenta nuevamente.',
                    'error',
                ),
        },
    );
};

/** Evita doble envío mientras la solicitud de generación está en curso. */
const isGeneratingReport = ref(false);

/**
 * Encola la generación del reporte (Excel/PDF) con la configuración actual
 * (POST .../generar-radiografia { config }). Bloquea si el periodo no puede generar
 * o si falta el periodo comparable en reportes no simples.
 */
const generateReport = () => {
    if (isGeneratingReport.value) {
        return;
    }

    if (!period.value?.can_generate_radiography) {
        return toastError(
            'Generación bloqueada',
            period.value?.blocking_reasons?.join(' ') ||
                'Completa las etapas previas.',
        );
    }

    if (
        reportConfig.value.report_type !== 'simple' &&
        !reportConfig.value.compare_period_id
    ) {
        return toastError(
            'Falta periodo comparable',
            'Selecciona explícitamente el periodo a comparar.',
        );
    }

    isGeneratingReport.value = true;
    router.post(
        `/historico-general/${selectedPeriodId.value}/generar-radiografia`,
        { config: reportConfig.value },
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Generación en cola',
                    text: 'El reporte se genera en segundo plano. Puedes cerrar esta ventana; te avisaremos por correo cuando Excel y PDF estén listos.',
                    icon: 'info',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo iniciar',
                    'Ya hay una generación en proceso o faltan pasos previos.',
                    'error',
                ),
            onFinish: () => {
                isGeneratingReport.value = false;
            },
        },
    );
};

/** Cancela la generación del reporte en curso (POST .../generar-reporte/cancelar), previa confirmación. */
const cancelGeneration = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const result = await Swal.fire({
        title: '¿Cancelar la generación?',
        text: 'El proceso se marcará como cancelado. Podrás reiniciarlo desde esta misma etapa.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar',
        cancelButtonText: 'No, dejar corriendo',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    router.post(
        `/historico-general/${selectedPeriodId.value}/generar-reporte/cancelar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Generación cancelada',
                    text: 'Puedes reiniciarla cuando estés listo.',
                    icon: 'info',
                    confirmButtonText: 'Entendido',
                }),
            onError: () =>
                Swal.fire(
                    'No se pudo cancelar',
                    'El proceso ya terminó o no existe.',
                    'error',
                ),
        },
    );
};

/** Ejecuta la generación del reporte de forma síncrona sin worker (POST .../generar-reporte/procesar-ahora). */
const processGenerationNow = async () => {
    if (!selectedPeriodId.value) {
        return;
    }

    const result = await Swal.fire({
        title: 'Procesar ahora (local)',
        text: 'Ejecuta el job directamente sin worker. La página esperará hasta que termine (puede tardar varios minutos).',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Procesar ahora',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    });

    if (!result.isConfirmed) {
        return;
    }

    Swal.fire({
        title: 'Generando reporte…',
        text: 'Ejecutando directamente. No cierres esta ventana.',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading(),
    });
    router.post(
        `/historico-general/${selectedPeriodId.value}/generar-reporte/procesar-ahora`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                Swal.fire({
                    title: 'Listo',
                    text: 'El reporte fue generado. Descarga Excel y PDF.',
                    icon: 'success',
                    confirmButtonText: 'Ver resultado',
                }),
            onError: () =>
                Swal.fire({
                    title: 'Terminó con error',
                    text: 'Revisa el detalle en la etapa de generar reporte.',
                    icon: 'error',
                    confirmButtonText: 'Ver detalle',
                }),
        },
    );
};
</script>

<template>
    <Head title="Histórico general" />
    <main class="p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-screen-2xl space-y-6">
            <!-- Fase 8.6: encabezado de página (antes un bloque oscuro gigante). -->
            <header
                class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
            >
                <div>
                    <p class="app-kicker">Operación · Histórico general</p>
                    <h1 class="app-title mt-1">Carga de archivos</h1>
                    <p class="app-subtitle mt-1 max-w-3xl">
                        Flujo del cierre mensual: elige el periodo, sube las
                        fuentes, carga los registros, resuelve incidencias y
                        genera la radiografía financiera en Excel y PDF.
                    </p>
                </div>
                <ol
                    class="flex flex-wrap gap-1.5 text-xs text-muted-foreground"
                    aria-label="Pasos del flujo"
                >
                    <li class="app-pill app-pill-muted">1 · Periodo</li>
                    <li class="app-pill app-pill-muted">2 · Archivos</li>
                    <li class="app-pill app-pill-muted">3 · Registros</li>
                    <li class="app-pill app-pill-muted">4 · Incidencias</li>
                    <li class="app-pill app-pill-muted">5 · Reporte</li>
                </ol>
            </header>

            <!-- ① Selección de periodo — siempre visible y primero -->
            <PeriodSelector
                v-model="selectedPeriodId"
                :periods="periods"
                data-tour="carga-period"
            />

            <!-- Sin periodo: empty state guiado -->
            <div
                v-if="!selectedPeriodId"
                class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-card py-16 text-center"
            >
                <div
                    class="flex size-14 items-center justify-center rounded-xl border bg-accent text-primary"
                >
                    <CalendarDays class="size-7" />
                </div>
                <h2 class="mt-5 text-lg font-semibold text-foreground">
                    Selecciona un periodo para iniciar
                </h2>
                <p
                    class="mt-2 max-w-md text-sm leading-6 text-muted-foreground"
                >
                    Elige un mes operativo para cargar archivos y generar
                    reportes, o un periodo compuesto (bimestre, etc.) para
                    reportes agregados.<br />
                    El flujo guiado se desplegará aquí.
                </p>
            </div>

            <!-- Periodo automático: vista simplificada sin stepper -->
            <AutomaticPeriodDetail
                v-else-if="period?.is_derived"
                :period="period"
                @generate="generateReport"
            />

            <!-- Periodo mensual: stepper + contenido de la etapa -->
            <template v-else>
                <!-- ② Stepper del flujo -->
                <WorkflowStepper
                    :steps="steps"
                    :current="currentStep"
                    @select="selectStep"
                />

                <!-- ③ Contenido de la etapa actual -->
                <transition name="fade" mode="out-in">
                    <!-- Etapa «Archivos»: tarjetas de fuentes + barra fija para avanzar -->
                    <div
                        v-if="currentStep === 'files'"
                        :key="`files-${selectedPeriodId}`"
                        data-tour="carga-upload-zone"
                        class="space-y-4"
                    >
                        <UploadSourcesStep
                            :period="period"
                            :sources="sources"
                            :uploads-by-source="uploadsBySource"
                            :selected-period-id="selectedPeriodId"
                            :uploading="isUploadingSource"
                            @upload="uploadFile"
                            @delete="deleteUpload"
                        />

                        <!-- Avance a Etapa 2 -->
                        <div
                            class="sticky bottom-20 z-10 flex flex-col gap-4 rounded-2xl border bg-card/95 px-5 py-4 shadow-soft-md backdrop-blur sm:flex-row sm:items-center sm:justify-between md:bottom-4"
                        >
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <template v-if="!period?.can_update_database">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Aún no puedes pasar a «Cargar
                                        registros».
                                    </p>
                                    <p
                                        v-if="missingRequiredSources.length"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Falta subir:
                                        <span
                                            class="font-medium text-foreground"
                                            >{{
                                                missingRequiredSources.join(
                                                    ', ',
                                                )
                                            }}</span
                                        >.
                                    </p>
                                    <p
                                        v-else
                                        class="text-sm text-muted-foreground"
                                    >
                                        Revisa que las fuentes «Necesaria» estén
                                        procesadas y sin error.
                                    </p>
                                </template>
                                <p
                                    v-else
                                    class="flex items-center gap-1.5 text-sm font-semibold text-success"
                                >
                                    <span
                                        class="size-2 rounded-full bg-current"
                                        aria-hidden="true"
                                    />
                                    Todas las fuentes requeridas están cargadas.
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-if="(grouped?.pending_count ?? 0) > 0"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-info-border bg-info-soft px-3 py-1.5 text-xs font-semibold text-info transition hover:opacity-90"
                                        @click="processPendingSources"
                                    >
                                        <svg
                                            class="size-3.5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke-width="2"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"
                                            />
                                        </svg>
                                        Procesar fuentes pendientes ({{
                                            grouped?.pending_count
                                        }})
                                    </button>
                                    <button
                                        v-if="(grouped?.failed_count ?? 0) > 0"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-danger-border bg-danger-soft px-3 py-1.5 text-xs font-semibold text-danger transition hover:opacity-90"
                                        @click="reprocessFailedSources"
                                    >
                                        <svg
                                            class="size-3.5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke-width="2"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
                                            />
                                        </svg>
                                        Reprocesar fuentes con error ({{
                                            grouped?.failed_count
                                        }})
                                    </button>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg px-5 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/25 disabled:cursor-not-allowed"
                                :class="
                                    period?.can_update_database
                                        ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                                        : 'bg-muted text-muted-foreground'
                                "
                                :disabled="!period?.can_update_database"
                                @click="selectStep('load')"
                            >
                                Siguiente etapa
                                <svg
                                    class="size-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2.5"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <!-- Etapa «Cargar registros» -->
                    <DatabaseUpdateStep
                        v-else-if="currentStep === 'load'"
                        :period="period"
                        :can-update="Boolean(period?.can_update_database)"
                        @update="updateDatabase"
                        @cancel="cancelDatabaseUpdate"
                        @clear-stuck="clearStuckDatabaseUpdate"
                        @refresh="
                            () =>
                                router.reload({
                                    only: ['periods', 'groupedUploads'],
                                })
                        "
                        @process-now="processNow"
                        @requeue="requeueRun"
                    />
                    <!-- Etapa «Incidencias» -->
                    <IncidentsStep
                        v-else-if="currentStep === 'incidents'"
                        ref="incidentsStepRef"
                        :period="period"
                        :incidents="incidents"
                        :incidents-has-data="incidentsHasData"
                        :incidents-no-data-message="incidentsNoDataMsg"
                        :branches="branches"
                        :reload-key="incidentsReloadKey"
                        @resolve="resolveIncident"
                        @refresh="loadIncidents"
                        @assign-branch="assignBranchFromIncident"
                        @confirm-match="confirmMatchFromIncident"
                        @resolve-location="resolveLocationFromIncident"
                        @confirm-coincidencia-from-duplicates="
                            confirmCoincidenciaFromDuplicates
                        "
                        @discard-coincidencia="
                            discardCoincidenciaFromDuplicates
                        "
                    />
                    <!-- Etapa «Configurar reporte» (bloqueada si hay incidencias críticas) -->
                    <div v-else-if="currentStep === 'config'" class="space-y-5">
                        <!-- Bloqueo por incidencias críticas -->
                        <div
                            v-if="hasCriticalIncidents"
                            class="flex flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-danger-border bg-danger-soft p-10 text-center"
                        >
                            <div
                                class="flex size-14 items-center justify-center rounded-2xl bg-red-100 shadow dark:bg-red-500/15"
                            >
                                <svg
                                    class="size-7 text-red-600"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
                                    />
                                </svg>
                            </div>
                            <div>
                                <p
                                    class="text-lg font-black text-red-800 dark:text-red-300"
                                >
                                    Configuración bloqueada
                                </p>
                                <p
                                    class="mt-1.5 max-w-sm text-sm leading-6 text-red-700 dark:text-red-400"
                                >
                                    Resuelve las incidencias críticas antes de
                                    configurar el reporte.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-2xl bg-red-600 px-5 text-sm font-black text-white shadow-lg shadow-red-200 transition hover:bg-red-700"
                                @click="selectStep('incidents')"
                            >
                                Ir a incidencias
                                <svg
                                    class="size-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2.5"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"
                                    />
                                </svg>
                            </button>
                        </div>
                        <ReportConfigurationStep
                            v-else
                            v-model="reportConfig"
                            :period="period"
                            :periods="periods"
                            :branches="branches"
                            :employees="periodEmployees"
                            :can-generate="
                                Boolean(period?.can_generate_radiography)
                            "
                            @next="selectStep('expenses')"
                        />
                    </div>
                    <!-- Etapa «Revisar gastos» -->
                    <ExpenseReviewStep
                        v-else-if="currentStep === 'expenses'"
                        :key="`expenses-${selectedPeriodId}`"
                        :period="period"
                        @continue="
                            selectStep(
                                steps.find((s: any) => s.key === 'generate')
                                    ?.status === 'blocked'
                                    ? 'config'
                                    : 'generate',
                            )
                        "
                        @changed="() => router.reload({ only: ['periods'] })"
                    />
                    <!-- Etapa «Generar reporte» -->
                    <GenerateReportStep
                        v-else-if="currentStep === 'generate'"
                        :period="period"
                        :report-config="reportConfig"
                        :can-generate="
                            Boolean(period?.can_generate_radiography)
                        "
                        :is-submitting="isGeneratingReport"
                        @generate="generateReport"
                        @cancel="cancelGeneration"
                        @refresh="
                            () =>
                                router.reload({
                                    only: ['periods', 'groupedUploads'],
                                })
                        "
                        @process-now="processGenerationNow"
                        @identity-state="handleIdentityState"
                    />
                    <!-- Etapa «Vista previa» y, por defecto, «Descargas» -->
                    <ReportPreview
                        v-else-if="currentStep === 'preview'"
                        :period="period"
                        :preview="preview"
                        :config="reportConfig"
                        :identity-state="identityState"
                    />
                    <GeneratedReportActions
                        v-else
                        :period="period"
                        :config="reportConfig"
                        :identity-state="identityState"
                    />
                </transition>
            </template>
        </div>
    </main>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: all 220ms ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translateY(8px);
}
</style>
