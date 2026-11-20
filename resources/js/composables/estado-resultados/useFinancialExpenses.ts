import { ref } from 'vue';
import type { Ref } from 'vue';
import type {
    AmortizationUpload,
    CreditPeriod,
    FinancialExpensesPayload,
} from '@/components/estado-resultados/financialExpenses';
import { apiJson, escapeHtml, mxn } from '@/components/estado-resultados/types';
import { useConfirm } from '@/composables/useConfirm';
import { downloadFile } from '@/lib/downloadOverlay';
import { busy, htmlList, showError, swal } from './erSwal';

interface Ctx {
    /** Base de la app (props.base de Inertia). */
    base: string;
    periodId: Ref<number>;
    /** Recarga el dataset del ER (las cifras del estado cambian con la tabla). */
    reloadDataset: () => Promise<void>;
}

/**
 * Pestaña «Gastos financieros»: carga del panel del periodo, subida / sustitución de la
 * tabla de amortización de cada crédito y descarga de la plantilla. Todo el feedback
 * pasa por SweetAlert2 (erSwal); nunca se anuncia éxito sin confirmación del servidor.
 */
export function useFinancialExpenses(ctx: Ctx) {
    const { confirm } = useConfirm();
    const data = ref<FinancialExpensesPayload | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const uploading = ref<string | null>(null);

    const root = `${ctx.base}/estado-resultados`;

    async function load(): Promise<void> {
        loading.value = true;
        error.value = null;

        try {
            data.value = await apiJson<FinancialExpensesPayload>(
                `${root}/${ctx.periodId.value}/gastos-financieros`,
            );
        } catch (e) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'No se pudo leer la información de gastos financieros.';
        } finally {
            loading.value = false;
        }
    }

    async function upload(credit: CreditPeriod, file: File): Promise<void> {
        const rules = data.value?.rules;
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (rules && !rules.extensions.includes(ext)) {
            await swal.fire({
                icon: 'warning',
                title: 'Formato no admitido',
                text: `"${file.name}" no es una tabla válida. Sube un archivo ${rules.extensions.map((x) => '.' + x).join(', ')}.`,
                confirmButtonText: 'Entendido',
            });

            return;
        }

        if (credit.upload) {
            const ok = await confirm({
                title: `¿Sustituir la tabla de ${credit.credit.name}?`,
                text: `La tabla vigente («${credit.upload.name}», ${credit.upload.rows} pagos) quedará como sustituida en la bitácora y los intereses de los periodos abiertos se recalcularán con la nueva. Los periodos aprobados conservan su versión.`,
                icon: 'question',
                confirmText: 'Sustituir tabla',
            });

            if (!ok.isConfirmed) {
                return;
            }
        }

        uploading.value = credit.credit.key;
        busy(
            'Validando tabla de amortización…',
            `Revisamos "${file.name}": encabezados, fechas, interés y capital de cada pago.`,
        );

        try {
            const form = new FormData();
            form.append('file', file);
            const res = await apiJson<{
                message: string;
                idempotent: boolean;
                upload: AmortizationUpload;
            }>(`${root}/creditos/${credit.credit.key}/amortizacion`, {
                method: 'POST',
                body: form,
            });
            await Promise.all([load(), ctx.reloadDataset()]);
            const warn = res.upload.warnings ?? [];
            const cols = Object.values(res.upload.columns ?? {});
            const mine = data.value?.credits.find(
                (c) => c.credit.key === credit.credit.key,
            );
            await swal.fire({
                icon: res.idempotent
                    ? 'info'
                    : warn.length
                      ? 'warning'
                      : 'success',
                title: res.idempotent
                    ? 'Esa tabla ya estaba cargada'
                    : warn.length
                      ? 'Tabla cargada con observaciones'
                      : 'Tabla de amortización cargada',
                html:
                    `<p>${escapeHtml(res.message)}</p>` +
                    (mine && mine.covered
                        ? `<p style="margin-top:8px;font-size:13px">En este periodo: interés <b>${escapeHtml(mxn(mine.totals.interest))}</b> (va entre EBIT y EBT) · capital excluido ${escapeHtml(mxn(mine.totals.principal))}.</p>`
                        : '') +
                    (cols.length
                        ? `<p style="margin-top:8px;font-size:12px">Columnas leídas: ${escapeHtml(cols.join(' · '))}</p>`
                        : '') +
                    htmlList(warn),
                confirmButtonText: 'Entendido',
            });
        } catch (e) {
            await showError(e, 'La tabla de amortización no se cargó');
        } finally {
            uploading.value = null;
        }
    }

    async function downloadTemplate(creditKey?: string): Promise<void> {
        const q = creditKey ? `?credito=${encodeURIComponent(creditKey)}` : '';
        // downloadFile muestra su propia pantalla de carga y explica cualquier error.
        await downloadFile(`${root}/creditos/plantilla-amortizacion${q}`);
    }

    return { data, loading, error, uploading, load, upload, downloadTemplate };
}
