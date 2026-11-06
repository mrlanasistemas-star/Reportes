import { ref } from 'vue';
import type { Ref } from 'vue';
import { apiJson, escapeHtml } from '@/components/estado-resultados/types';
import type {
    AdjustmentState,
    Permissions,
    UploadRules,
} from '@/components/estado-resultados/types';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';
import { downloadFile } from '@/lib/downloadOverlay';
import { busy, closeSwal, htmlList as list, showError, swal } from './erSwal';

export interface AdjustmentLog {
    label: string;
    old_state: string | null;
    old_amount: string | null;
    new_state: string;
    new_amount: string | null;
    notes: string | null;
    user: string | null;
    at: string;
}

interface Ctx {
    url: (path: string) => string;
    scope: Ref<string>;
    scopeQuery: () => string;
    loadDataset: () => Promise<void>;
    can: Permissions;
    toleranceDays: number;
    /** Lo que falta para que el reporte sea definitivo (vacío = completo). */
    blockers: () => string[];
    /** Motivo por el que no se admiten cambios (versión aprobada o consulta histórica). */
    lockReason: () => string | null;
    uploadRules?: UploadRules | null;
}

/**
 * Acciones del módulo (cargar, procesar, clasificar, ajustes, exportar). Toda la
 * retroalimentación pasa por SweetAlert2: confirmación, carga en curso, éxito
 * real o error explicado. Nunca se anuncia éxito si el servidor no lo confirmó.
 */
export function useErActions(ctx: Ctx) {
    const toast = useToast();
    const { confirm } = useConfirm();

    const processing = ref(false);
    const uploadingSlot = ref<string | null>(null);
    /** Sube con cada intento de carga (éxito o rechazo) para refrescar la bitácora. */
    const uploadSeq = ref(0);
    const classifying = ref(false);
    const accepting = ref(false);
    const savingAdjustment = ref<string | null>(null);
    const adjustmentLogs = ref<AdjustmentLog[]>([]);
    const logsLoading = ref(false);

    /** Ninguna escritura sale si la versión mostrada es de solo lectura. */
    async function guard(): Promise<boolean> {
        const reason = ctx.lockReason();

        if (!reason) {
            return true;
        }

        await swal.fire({
            icon: 'info',
            title: 'Esta versión es de solo lectura',
            text: reason,
            confirmButtonText: 'Entendido',
        });

        return false;
    }

    async function process(ask = true): Promise<void> {
        if (!(await guard())) {
            return;
        }

        if (ask) {
            const ok = await confirm({
                title: '¿Procesar la conciliación?',
                text: `Cruzaremos cada cargo del banco contra el ERP (tolerancia de ${ctx.toleranceDays} día(s)) y aplicaremos las reglas de clasificación. Puedes repetirlo sin miedo: no duplica movimientos ni borra lo que clasificaste a mano.`,
                icon: 'question',
                confirmText: 'Procesar ahora',
            });

            if (!ok.isConfirmed) {
                return;
            }
        }

        processing.value = true;
        busy(
            'Procesando conciliación…',
            'Cruzando banco contra ERP y aplicando reglas. Puede tardar unos segundos.',
        );

        try {
            const res = await apiJson<{ message: string }>(
                ctx.url('/procesar'),
                { method: 'POST' },
            );
            await ctx.loadDataset();
            closeSwal();
            toast.success(res.message);
        } catch (e) {
            await showError(e, 'No se pudo procesar la conciliación');
        } finally {
            processing.value = false;
        }
    }

    async function upload(slot: string, file: File): Promise<void> {
        if (!(await guard())) {
            return;
        }

        // Revisión previa en el navegador (el servidor vuelve a validar todo).
        const rules = ctx.uploadRules;
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (rules && !rules.extensions.includes(ext)) {
            await swal.fire({
                icon: 'warning',
                title: 'Formato no admitido',
                text: `"${file.name}" no es un Excel válido. Sube un archivo ${rules.extensions.map((x) => '.' + x).join(', ')}.`,
                confirmButtonText: 'Entendido',
            });

            return;
        }

        if (rules && file.size > rules.max_mb * 1024 * 1024) {
            await swal.fire({
                icon: 'warning',
                title: 'El archivo es demasiado grande',
                text: `"${file.name}" pesa ${(file.size / 1048576).toFixed(1)} MB; el máximo es ${rules.max_mb} MB.`,
                confirmButtonText: 'Entendido',
            });

            return;
        }

        uploadingSlot.value = slot;
        busy(
            'Validando archivo…',
            `Revisamos "${file.name}" completo antes de guardarlo: columnas, fechas y totales de control.`,
        );

        try {
            const form = new FormData();
            form.append('slot', slot);
            form.append('file', file);
            const res = await apiJson<{
                message: string;
                idempotent?: boolean;
                upload: { warnings: string[]; control_ok?: boolean | null };
            }>(ctx.url('/cargas'), { method: 'POST', body: form });
            await ctx.loadDataset();

            if (res.idempotent) {
                // Mismo archivo, misma cuenta: no se duplicó nada.
                await swal.fire({
                    icon: 'info',
                    title: 'Ese archivo ya estaba cargado',
                    html: `<p>${escapeHtml(res.message)}</p><p style="margin-top:8px;font-size:13px">No se duplicó ningún movimiento.</p>`,
                    confirmButtonText: 'Entendido',
                });

                return;
            }

            const warn = res.upload.warnings ?? [];
            const go = await swal.fire({
                icon: warn.length ? 'warning' : 'success',
                title: warn.length
                    ? 'Archivo cargado con observaciones'
                    : 'Archivo validado y cargado',
                html: `<p>${escapeHtml(res.message)}</p>${list(warn)}<p style="margin-top:10px;font-size:13px">Aún no cuenta en el reporte: falta procesar la conciliación.</p>`,
                showCancelButton: ctx.can.procesar,
                confirmButtonText: ctx.can.procesar
                    ? 'Procesar conciliación ahora'
                    : 'Entendido',
                cancelButtonText: 'Después',
            });

            if (go.isConfirmed && ctx.can.procesar) {
                await process(false);
            }
        } catch (e) {
            await showError(e, 'El archivo no se cargó');
        } finally {
            uploadingSlot.value = null;
            uploadSeq.value++;
        }
    }

    async function classify(
        fingerprints: string[],
        payload: { treatment: string; notes: string },
    ): Promise<boolean> {
        if (!(await guard())) {
            return false;
        }

        classifying.value = true;

        try {
            const res = await apiJson<{ message: string }>(
                ctx.url('/clasificar'),
                {
                    method: 'POST',
                    body: JSON.stringify({ fingerprints, ...payload }),
                },
            );
            await ctx.loadDataset();
            toast.success(res.message);

            return true;
        } catch (e) {
            await showError(e, 'No se guardó la clasificación');

            return false;
        } finally {
            classifying.value = false;
        }
    }

    /** `ask=false` sólo para la cola guiada (un movimiento, acción explícita). */
    async function acceptSuggestions(
        fingerprints: string[],
        ask = true,
    ): Promise<boolean> {
        if (!(await guard())) {
            return false;
        }

        if (!fingerprints.length) {
            await swal.fire({
                icon: 'info',
                title: 'No hay sugerencias que aceptar',
                text: 'Los movimientos elegidos no tienen un tratamiento sugerido. Clasifícalos manualmente.',
                confirmButtonText: 'Entendido',
            });

            return false;
        }

        if (ask) {
            const ok = await confirm({
                title: `¿Aceptar ${fingerprints.length} sugerencia(s)?`,
                text: 'Cada movimiento quedará clasificado con el tratamiento que propone el sistema. Se registra con tu usuario y puedes corregirlo después.',
                icon: 'question',
                confirmText: 'Aceptar sugerencias',
            });

            if (!ok.isConfirmed) {
                return false;
            }
        }

        accepting.value = true;

        if (fingerprints.length > 1) {
            busy(
                'Aplicando sugerencias…',
                `Clasificando ${fingerprints.length} movimientos y recalculando el reporte.`,
            );
        }

        try {
            const res = await apiJson<{ message: string }>(
                ctx.url('/aceptar-sugerencias'),
                { method: 'POST', body: JSON.stringify({ fingerprints }) },
            );
            await ctx.loadDataset();
            closeSwal();
            toast.success(res.message);

            return true;
        } catch (e) {
            await showError(e, 'No se pudieron aceptar las sugerencias');

            return false;
        } finally {
            accepting.value = false;
        }
    }

    async function saveAdjustment(p: {
        concept: string;
        state: AdjustmentState;
        amount: string | null;
        notes: string | null;
    }): Promise<void> {
        if (!(await guard())) {
            return;
        }

        savingAdjustment.value = p.concept;

        try {
            const res = await apiJson<{ message: string }>(
                ctx.url('/ajustes'),
                {
                    method: 'PUT',
                    body: JSON.stringify({ scope: ctx.scope.value, ...p }),
                },
            );
            await ctx.loadDataset();
            adjustmentLogs.value = [];
            toast.success(res.message);
        } catch (e) {
            await showError(e, 'No se guardó el ajuste');
        } finally {
            savingAdjustment.value = null;
        }
    }

    async function loadLogs(): Promise<void> {
        logsLoading.value = true;

        try {
            const res = await apiJson<{ logs: AdjustmentLog[] }>(
                ctx.url(`/ajustes/historial?${ctx.scopeQuery()}`),
            );
            adjustmentLogs.value = res.logs;
        } catch (e) {
            adjustmentLogs.value = [];
            await showError(e, 'No se pudo leer el historial de ajustes');
        } finally {
            logsLoading.value = false;
        }
    }

    /** Exportar un reporte preliminar exige confirmación explícita. */
    async function exportFile(kind: 'excel' | 'pdf'): Promise<void> {
        const blockers = ctx.blockers();

        if (blockers.length) {
            const ok = await swal.fire({
                icon: 'warning',
                title: 'El reporte todavía es preliminar',
                html: `<p>El ${kind === 'pdf' ? 'PDF' : 'Excel'} tendrá las cifras de hoy, que aún pueden cambiar porque falta:</p>${list(blockers)}`,
                showCancelButton: true,
                reverseButtons: true,
                confirmButtonText: 'Exportar como preliminar',
                cancelButtonText: 'Cancelar',
            });

            if (!ok.isConfirmed) {
                return;
            }
        }

        await downloadFile(ctx.url(`/exportar/${kind}?${ctx.scopeQuery()}`));
    }

    return {
        processing,
        uploadingSlot,
        uploadSeq,
        classifying,
        accepting,
        savingAdjustment,
        adjustmentLogs,
        logsLoading,
        showError,
        process,
        upload,
        classify,
        acceptSuggestions,
        saveAdjustment,
        loadLogs,
        exportFile,
    };
}
