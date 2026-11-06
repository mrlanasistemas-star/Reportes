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
import { busy, closeSwal, showError, swal } from './erSwal';

interface Ctx {
    url: (path: string) => string;
    dataset: Ref<Dataset | null>;
    viewVersion: Ref<number | null>;
    periodId: Ref<number>;
    loadDataset: () => Promise<void>;
    can: Permissions;
}

/** Títulos en lenguaje llano para cada tipo de bloqueo del backend. */
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
    datos_faltantes: {
        title: 'Faltan datos obligatorios',
        action: 'Ver fuentes y ajustes',
        tab: 'fuentes',
    },
};

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
 * Gobierno del reporte: versión que se consulta, historial, aprobación y apertura
 * de una nueva versión. Toda la retroalimentación pasa por SweetAlert2 y nunca se
 * anuncia una aprobación que el servidor no confirmó.
 */
export function useErGovernance(ctx: Ctx) {
    const history = ref<VersionHistory | null>(null);
    const historyLoading = ref(false);
    const approving = ref(false);
    const reopening = ref(false);

    const report = computed(() => ctx.dataset.value?.meta.report ?? null);
    /** Se consulta una versión distinta de la vigente (aprobada vieja o sustituida). */
    const viewingHistoric = computed(
        () =>
            ctx.viewVersion.value !== null &&
            !!report.value &&
            !report.value.current,
    );
    /** Motivo de solo lectura (null = se puede editar). */
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

    watch(ctx.periodId, () => {
        history.value = null;
    });

    function viewVersion(v: number | null): void {
        ctx.viewVersion.value = v;
    }

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
            html: `<p>Las cifras de <b>${escapeHtml(d.meta.period.label)}</b> quedarán congeladas para todos los alcances (consolidado y sucursales). Web, Excel y PDF mostrarán esta versión y nadie podrá cargar, procesar, clasificar ni ajustar hasta abrir una nueva versión.</p>`,
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
            const res = await apiJson<{
                message: string;
                version: VersionSummary;
            }>(ctx.url('/aprobar'), {
                method: 'POST',
                body: JSON.stringify({
                    notes: (ok.value as string)?.trim() || null,
                    expected_hash: d.meta.dataset_hash,
                }),
            });
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
                await swal.fire({
                    icon: 'warning',
                    title: 'Las cifras cambiaron',
                    text: e.message,
                    confirmButtonText: 'Recargar el reporte',
                });
                await ctx.loadDataset();
            } else {
                await showError(e, 'No se pudo aprobar');
            }
        } finally {
            approving.value = false;
        }
    }

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
