/**
 * Módulo: gobierno (versiones y aprobación) del Estado de Resultados.
 *
 * Controla qué versión del reporte se consulta, el historial de versiones
 * (GET `/versiones`), la aprobación que congela las cifras (POST `/aprobar`,
 * en cola vía runErTask) y la apertura de una nueva versión tras aprobar
 * (POST `/nueva-version`). Calcula además el motivo de solo lectura
 * (`lockReason`) que bloquea todas las escrituras de la pantalla.
 */
import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    ApiError,
    apiJson,
    escapeHtml,
} from '@/components/estado-resultados/types';
import type {
    Dataset,
    Permissions,
    ReportBlocker,
    VersionHistory,
    VersionSummary,
} from '@/components/estado-resultados/types';
import { busy, closeSwal, showError, swal, updateBusy } from './erSwal';
import { runErTask } from './erTask';

/** Contexto que la pantalla del ER inyecta al gobierno del reporte. */
interface Ctx {
    /** Arma la URL de una ruta del periodo mostrado. */
    url: (path: string) => string;
    /** Dataset del periodo mostrado. */
    dataset: Ref<Dataset | null>;
    /** Versión consultada (null = vigente); escribirla dispara la recarga del dataset. */
    viewVersion: Ref<number | null>;
    /** Periodo mostrado (al cambiar se descarta el historial). */
    periodId: Ref<number>;
    /** Recarga el dataset desde el servidor. */
    loadDataset: () => Promise<void>;
    /** Permisos del usuario. */
    can: Permissions;
}

/**
 * Títulos en lenguaje llano para cada tipo de bloqueo del backend, con la
 * acción sugerida y la pestaña a la que lleva.
 */
export const BLOCKER_GROUPS: Record<
    string,
    { title: string; action: string; tab: string; filter?: string }
> = {
    pendientes: {
        title: 'Hay movimientos sin clasificar',
        action: 'Revisar pendientes',
        tab: 'revision',
    },
    procesamiento: {
        title: 'La conciliación no está al día',
        action: 'Ver procesamiento',
        tab: 'control',
    },
    validaciones: {
        title: 'Alguna validación no cuadra',
        action: 'Abrir validación',
        tab: 'validacion',
    },
    diferencias: {
        title: 'Hay diferencias de conciliación abiertas',
        action: 'Abrir validación',
        tab: 'validacion',
    },
    fuentes: {
        title: 'Alguna fuente tiene observaciones',
        action: 'Ver fuentes',
        tab: 'fuentes',
    },
    datos_faltantes: {
        title: 'Faltan datos obligatorios',
        action: 'Ver fuentes y ajustes',
        tab: 'fuentes',
    },
};

/**
 * Lista HTML (escapada) de bloqueos del reporte, anteponiendo el alcance en negritas si lo trae.
 * @returns HTML de la lista o cadena vacía.
 */
const blockerList = (blockers: ReportBlocker[]) =>
    blockers.length
        ? `<ul style="text-align:left;margin:10px auto 0;font-size:13px;max-height:260px;overflow:auto;max-width:480px">${blockers
              .map(
                  (b) =>
                      `<li style="margin:5px 0">• ${b.scope ? `<b>${escapeHtml(b.scope)}:</b> ` : ''}${escapeHtml(b.message)}</li>`,
              )
              .join('')}</ul>`
        : '';

/**
 * Resumen de lo que se aprueba: versión, huella, fuentes con observaciones y diferencias abiertas.
 * Se muestra en el diálogo de confirmación de la aprobación.
 * @param d Dataset que se va a aprobar.
 * @returns HTML del resumen.
 */
function approvalSummary(d: Dataset): string {
    const r = d.meta.report;
    const accounts = d.sources.cuentas;
    const info = accounts.reduce(
        (n, c) =>
            n +
            (c.upload?.warnings_detail ?? []).filter(
                (w) => w.severity !== 'bloqueante',
            ).length,
        0,
    );
    const open = (d.validation?.unresolved ?? []).filter(
        (u) => (u.open_count ?? u.count) > 0,
    );
    const rows = [
        `Versión <b>${r?.version ?? '—'}</b> · ${escapeHtml(d.meta.scope.label)} · huella <code>${escapeHtml(d.meta.dataset_hash.slice(0, 12))}</code>`,
        `Estados de cuenta: ${accounts.filter((c) => c.upload).length} de ${accounts.length} cargados${info ? ` · ${info} aviso(s) informativo(s)` : ''}`,
        open.length
            ? `Diferencias abiertas: ${open.map((u) => escapeHtml(u.label)).join('; ')}`
            : 'Sin diferencias abiertas',
    ];

    return `<ul style="text-align:left;margin:12px auto 0;font-size:13px;max-width:480px">${rows.map((x) => `<li style="margin:4px 0">• ${x}</li>`).join('')}</ul>`;
}

/**
 * Gobierno del reporte: versión que se consulta, historial, aprobación y apertura
 * de una nueva versión. Toda la retroalimentación pasa por SweetAlert2 y nunca se
 * anuncia una aprobación que el servidor no confirmó.
 * @param ctx Dataset, versión consultada y helpers del periodo.
 * @returns Estado reactivo (historial, banderas, reporte, bloqueo) y acciones.
 */
export function useErGovernance(ctx: Ctx) {
    /** Historial de versiones del periodo (se carga bajo demanda). */
    const history = ref<VersionHistory | null>(null);
    const historyLoading = ref(false);
    /** Aprobación en curso. */
    const approving = ref(false);
    /** Apertura de nueva versión en curso. */
    const reopening = ref(false);

    /** Metadatos del reporte (versión, estado, bloqueos) del dataset mostrado. */
    const report = computed(() => ctx.dataset.value?.meta.report ?? null);
    /** Se consulta una versión distinta de la vigente (aprobada vieja o sustituida). */
    const viewingHistoric = computed(
        () =>
            ctx.viewVersion.value !== null &&
            !!report.value &&
            !report.value.current,
    );
    /**
     * Motivo de solo lectura (null = se puede editar): versión histórica
     * (snapshot no vigente) o versión aprobada (congelada).
     */
    const lockReason = computed<string | null>(() => {
        const r = report.value;

        if (!r) {
            return null;
        }

        if (r.source === 'snapshot' && !r.current) {
            return `Estás viendo la versión ${r.version} (${r.status_label.toLowerCase()}). Es un registro histórico de solo lectura: vuelve a la versión vigente para trabajar.`;
        }

        if (r.locked) {
            return `La versión ${r.version} está aprobada y sus cifras quedaron congeladas. Para corregir algo, quien tenga permiso de aprobar debe abrir una nueva versión indicando el motivo.`;
        }

        return null;
    });

    /** Lee el historial de versiones del periodo (GET `/versiones`). */
    async function loadHistory(): Promise<void> {
        historyLoading.value = true;

        try {
            history.value = await apiJson<VersionHistory>(
                ctx.url('/versiones'),
            );
        } catch (e) {
            history.value = null;
            await showError(e, 'No se pudo leer el historial de versiones');
        } finally {
            historyLoading.value = false;
        }
    }

    /** El historial es por periodo: al cambiar de periodo se descarta. */
    watch(ctx.periodId, () => {
        history.value = null;
    });

    /**
     * Cambia la versión consultada (null = vigente); el dataset se recarga solo.
     * @param v Número de versión o null.
     */
    function viewVersion(v: number | null): void {
        ctx.viewVersion.value = v;
    }

    /**
     * Aprueba la versión actual: si no es aprobable muestra los bloqueos; si lo es,
     * pide confirmación con notas opcionales y envía la huella del dataset
     * (`expected_hash`) para que el servidor rechace (409) si las cifras cambiaron.
     * Un 422 con bloqueos se muestra como lista; al terminar vuelve a la versión vigente.
     */
    async function approve(): Promise<void> {
        const d = ctx.dataset.value;
        const r = report.value;

        if (!d || !r) {
            return;
        }

        if (!r.approvable) {
            await swal.fire({
                icon: 'warning',
                title: 'Todavía no se puede aprobar',
                html: `<p>La versión ${r.version} está en «${escapeHtml(r.status_label)}». Resuelve esto primero:</p>${blockerList(r.blockers)}`,
                confirmButtonText: 'Entendido',
            });

            return;
        }

        const ok = await swal.fire({
            icon: 'question',
            title: `¿Aprobar la versión ${r.version}?`,
            html: `<p>Las cifras de <b>${escapeHtml(d.meta.period.label)}</b> quedarán congeladas para todos los alcances (consolidado y sucursales). Web, Excel y PDF mostrarán esta versión y nadie podrá cargar, procesar, clasificar ni ajustar hasta abrir una nueva versión.</p>${approvalSummary(d)}`,
            input: 'textarea',
            inputLabel: 'Notas de aprobación (opcional)',
            inputPlaceholder:
                'Ej. Revisado con Dirección; conciliación bancaria al 100 %.',
            inputAttributes: { maxlength: '2000' },
            showCancelButton: true,
            confirmButtonText: `Aprobar versión ${r.version}`,
            cancelButtonText: 'Cancelar',
        });

        if (!ok.isConfirmed) {
            return;
        }

        approving.value = true;
        busy(
            'Aprobando y congelando cifras…',
            'Guardamos una copia sellada de cada alcance con sus fuentes. Puede tardar unos segundos.',
        );

        try {
            // La aprobación corre en cola: mientras esté «en proceso» se consulta la tarea
            // cada 3 s; al terminar se recarga el reporte con la versión congelada.
            const res = await runErTask<{
                message: string;
                version: VersionSummary;
            }>(
                ctx.url('/aprobar'),
                ctx.url('/tareas'),
                {
                    method: 'POST',
                    body: JSON.stringify({
                        notes: (ok.value as string)?.trim() || null,
                        expected_hash: d.meta.dataset_hash,
                    }),
                },
                (t) =>
                    updateBusy(
                        'Aprobando en segundo plano…',
                        `${t.current_step ?? 'En cola'} La pantalla se actualizará sola al terminar.`,
                    ),
            );
            ctx.viewVersion.value = null;
            await Promise.all([ctx.loadDataset(), loadHistory()]);
            closeSwal();
            await swal.fire({
                icon: 'success',
                title: `Versión ${res.version.version} aprobada`,
                html: `<p>${escapeHtml(res.message)}</p><p style="margin-top:8px;font-size:12px">Sello: <code>${escapeHtml((res.version.snapshot_hash ?? '').slice(0, 16))}</code></p>`,
                confirmButtonText: 'Entendido',
            });
        } catch (e) {
            if (
                e instanceof ApiError &&
                e.status === 422 &&
                e.blockers.length
            ) {
                await swal.fire({
                    icon: 'warning',
                    title: 'No se pudo aprobar',
                    html: `<p>${escapeHtml(e.message)}</p>${blockerList(e.blockers)}`,
                    confirmButtonText: 'Entendido',
                });
            } else if (e instanceof ApiError && e.status === 409) {
                // El backend distingue el conflicto en `conflict` (o `processing`):
                // aprobacion_en_curso / candado_expirado / procesamiento en curso
                // = otra operación ocupa el periodo (reintentar); hash_revisado,
                // datos_cambiaron, version_cambio, version_duplicada = cambiaron
                // las cifras o la versión (recargar y revisar).
                const conflict = String(e.body.conflict ?? '');
                const busy =
                    e.body.processing === true ||
                    conflict === 'aprobacion_en_curso' ||
                    conflict === 'candado_expirado';

                if (busy) {
                    await swal.fire({
                        icon: 'info',
                        title: 'Hay otra operación en curso',
                        text: e.message,
                        confirmButtonText: 'Entendido',
                    });
                } else {
                    await swal.fire({
                        icon: 'warning',
                        title: 'Las cifras cambiaron',
                        text: e.message,
                        confirmButtonText: 'Recargar el reporte',
                    });
                    await ctx.loadDataset();
                }
            } else {
                await showError(e, 'No se pudo aprobar');
            }
        } finally {
            approving.value = false;
        }
    }

    /**
     * Abre una nueva versión de trabajo sobre un periodo aprobado. Exige un motivo
     * de al menos 10 caracteres (queda en la bitácora); la versión aprobada sigue
     * siendo la oficial hasta que se apruebe la nueva.
     */
    async function openNewVersion(): Promise<void> {
        const r = report.value;

        if (!r) {
            return;
        }

        const res = await swal.fire({
            icon: 'warning',
            title: `¿Abrir la versión ${r.version + 1}?`,
            html: `<p>La versión ${r.version} sigue siendo la oficial hasta que se apruebe la nueva. Mientras tanto se podrá volver a cargar, procesar, clasificar y ajustar. El motivo queda en la bitácora.</p>`,
            input: 'textarea',
            inputLabel: 'Motivo (obligatorio, mínimo 10 caracteres)',
            inputPlaceholder:
                'Ej. Llegó la factura de CAPEX de septiembre que faltaba reclasificar.',
            inputAttributes: { maxlength: '2000' },
            showCancelButton: true,
            confirmButtonText: 'Abrir nueva versión',
            cancelButtonText: 'Cancelar',
            inputValidator: (v: string) =>
                (v ?? '').trim().length < 10
                    ? 'Escribe el motivo con al menos 10 caracteres.'
                    : null,
        });

        if (!res.isConfirmed) {
            return;
        }

        reopening.value = true;
        busy(
            'Abriendo nueva versión…',
            'Registrando el motivo en la bitácora.',
        );

        try {
            const out = await apiJson<{
                message: string;
                draft_version: number;
            }>(ctx.url('/nueva-version'), {
                method: 'POST',
                body: JSON.stringify({ reason: (res.value as string).trim() }),
            });
            ctx.viewVersion.value = null;
            await Promise.all([ctx.loadDataset(), loadHistory()]);
            closeSwal();
            await swal.fire({
                icon: 'success',
                title: `Versión ${out.draft_version} abierta`,
                text: out.message,
                confirmButtonText: 'Entendido',
            });
        } catch (e) {
            await showError(e, 'No se pudo abrir la nueva versión');
        } finally {
            reopening.value = false;
        }
    }

    return {
        history,
        historyLoading,
        approving,
        reopening,
        report,
        viewingHistoric,
        lockReason,
        loadHistory,
        viewVersion,
        approve,
        openNewVersion,
    };
}
