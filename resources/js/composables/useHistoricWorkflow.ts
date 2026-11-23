import { computed, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Flujo guiado por pasos de la pantalla Histórico general (`pages/historico-general/index.vue`).
 *
 * Calcula, a partir del periodo seleccionado y de sus incidencias, el estado de cada paso del
 * asistente (Archivos → Cargar registros → Incidencias → Configurar → Revisar gastos (opcional)
 * → Generar → Vista previa → Exportación) y controla qué paso está abierto. No hace peticiones:
 * solo deriva estados de los datos que ya entrega el backend.
 *
 * Estados posibles de un paso: `pending`, `ready`, `running`, `completed`, `generated`,
 * `error` y `blocked` (un paso `blocked` no se puede abrir).
 *
 * @param period      Ref con el periodo seleccionado (campos `can_update_database`,
 *                    `database_updated`, `database_update_run_status`, `radiography_running`,
 *                    `preview_summary`, `can_export_radiography`, `missing_radiography_sources`).
 * @param incidents   Ref con la lista de incidencias del periodo (se cuentan las de severidad `high`).
 * @param configValid Ref opcional: `false` bloquea «Generar reporte» si la configuración es inválida.
 * @returns `currentStep` (clave del paso abierto), `steps` (lista con estado) y `selectStep`.
 */
export function useHistoricWorkflow(
    period: any,
    incidents: any,
    configValid?: Ref<boolean>,
) {
    /** Clave del paso abierto actualmente; inicia en «Archivos y periodo». */
    const currentStep = ref('files');

    /**
     * Pasos del asistente con su estado:
     * - Cargar registros: completado si la BD ya se actualizó; en curso/error según la corrida;
     *   listo si se puede actualizar; si no, bloqueado.
     * - Incidencias: bloqueado hasta cargar; error si hay incidencias críticas.
     * - Configurar y Generar: requieren carga completa sin incidencias críticas; Generar
     *   también requiere configuración válida.
     * - Revisar gastos: opcional, disponible en cuanto la carga está completa.
     * - Vista previa / Exportación: según exista vista previa o exportación disponible.
     */
    const steps = computed(() => {
        const selected = period.value;
        const dbReady = !!selected?.can_update_database;
        const dbDone = !!selected?.database_updated;
        const dbRunStatus = selected?.database_update_run_status ?? null;
        const dbRunning = ['queued', 'running'].includes(dbRunStatus);
        const dbFailed = dbRunStatus === 'failed';
        const critical = (incidents.value ?? []).filter(
            (item: any) => item.severity === 'high',
        ).length;
        const canConfig = dbDone && critical === 0;
        const radioRunning = !!selected?.radiography_running;
        const hasRealPreview = !!selected?.preview_summary;
        const canExport = !!selected?.can_export_radiography;
        const cfgValid = configValid?.value !== false; // true when not explicitly false

        const loadStatus = dbDone
            ? 'completed'
            : dbRunning
              ? 'running'
              : dbFailed
                ? 'error'
                : dbReady
                  ? 'ready'
                  : 'blocked';

        const incidentsStatus = !dbDone
            ? 'blocked'
            : critical > 0
              ? 'error'
              : 'completed';

        const configStatus = !canConfig
            ? 'blocked'
            : hasRealPreview
              ? 'completed'
              : 'ready';

        const generateStatus = !canConfig
            ? 'blocked'
            : !cfgValid
              ? 'blocked'
              : radioRunning
                ? 'running'
                : hasRealPreview
                  ? 'generated'
                  : 'ready';

        return [
            {
                key: 'files',
                label: 'Archivos y periodo',
                description:
                    'Selecciona el periodo operativo y carga los archivos requeridos.',
                status: selected
                    ? selected.missing_radiography_sources?.length
                        ? 'ready'
                        : 'completed'
                    : 'pending',
            },
            {
                key: 'load',
                label: 'Cargar registros',
                description:
                    'Lee los archivos y guarda los registros base necesarios para el reporte.',
                status: loadStatus,
            },
            {
                key: 'incidents',
                label: 'Incidencias',
                description:
                    'Resuelve incidencias críticas antes de generar el reporte.',
                status: incidentsStatus,
            },
            {
                key: 'config',
                label: 'Configurar reporte',
                description:
                    'Define si el reporte será general, por sucursal, por gestor o comparativo.',
                status: configStatus,
            },
            {
                // Revisión de gastos: OPCIONAL — nunca bloquea «Generar reporte».
                key: 'expenses',
                label: 'Revisar gastos (opcional)',
                description:
                    'Detalla a qué colaborador o sucursal pertenece cada gasto, o márcalo como corporativo. Puedes saltarlo.',
                status: dbDone ? 'ready' : 'blocked',
            },
            {
                key: 'generate',
                label: 'Generar reporte',
                description:
                    'Calcula las métricas del reporte según la configuración seleccionada.',
                status: generateStatus,
            },
            {
                key: 'preview',
                label: 'Vista previa',
                description:
                    'Revisa KPIs, tablas y gráficas antes de exportar.',
                status: hasRealPreview
                    ? 'completed'
                    : radioRunning
                      ? 'running'
                      : 'blocked',
            },
            {
                key: 'exports',
                label: 'Exportación',
                description:
                    'Descarga Excel y PDF generados desde el mismo resultado.',
                status: canExport
                    ? 'completed'
                    : radioRunning
                      ? 'running'
                      : 'blocked',
            },
        ] as any[];
    });

    /**
     * Abre un paso si existe y no está bloqueado; si está bloqueado no hace nada.
     *
     * @param key Clave del paso (`files`, `load`, `incidents`, `config`, `expenses`, `generate`, `preview`, `exports`).
     */
    const selectStep = (key: string) => {
        const step = steps.value.find((item: any) => item.key === key);

        if (step && step.status !== 'blocked') {
            currentStep.value = key;
        }
    };

    return { currentStep, steps, selectStep };
}
