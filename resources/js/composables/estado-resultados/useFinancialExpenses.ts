/**
 * Módulo: pestaña «Gastos financieros» del Estado de Resultados.
 *
 * Rutas usadas (EstadoResultadosFinancialExpensesController, routes/web.php):
 * - GET  `{base}/estado-resultados/{periodo}/gastos-financieros` (panel del periodo)
 * - POST `{base}/estado-resultados/creditos/{credito}/amortizacion` (sube/sustituye tabla)
 * - GET  `{base}/estado-resultados/creditos/plantilla-amortizacion[?credito=…]` (plantilla)
 * Los intereses de la tabla de amortización van entre EBIT y EBT; el capital se excluye.
 */
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

/** Contexto que la pantalla del ER inyecta a la pestaña. */
interface Ctx {
    /** Base de la app (props.base de Inertia). */
    base: string;
    /** Periodo mostrado. */
    periodId: Ref<number>;
    /** Recarga el dataset del ER (las cifras del estado cambian con la tabla). */
    reloadDataset: () => Promise<void>;
}

/**
 * Pestaña «Gastos financieros»: carga del panel del periodo, subida / sustitución de la
 * tabla de amortización de cada crédito y descarga de la plantilla. Todo el feedback
 * pasa por SweetAlert2 (erSwal); nunca se anuncia éxito sin confirmación del servidor.
 * @param ctx Base de la app, periodo mostrado y recarga del dataset.
 * @returns Estado reactivo del panel y acciones (`load`, `upload`, `downloadTemplate`).
 */
export function useFinancialExpenses(ctx: Ctx) {
    const { confirm } = useConfirm();
    /** Panel del periodo: créditos, tablas cargadas, totales y reglas de carga. */
    const data = ref<FinancialExpensesPayload | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    /** Clave del crédito cuya tabla se está subiendo (null si ninguna). */
    const uploading = ref<string | null>(null);

    const root = `${ctx.base}/estado-resultados`;

    /** Número de la última lectura del panel (descarta respuestas viejas). */
    let loadRequest = 0;

    /** Lee el panel de gastos financieros del periodo mostrado. */
    async function load(): Promise<void> {
        // Guardia de respuestas viejas: si se cambia de periodo mientras carga,
        // sólo la petición más reciente escribe datos, error y `loading`.
        const id = ++loadRequest;
        loading.value = true;
        error.value = null;

        try {
            const res = await apiJson<FinancialExpensesPayload>(
                `${root}/${ctx.periodId.value}/gastos-financieros`,
            );

            if (id === loadRequest) {
                data.value = res;
            }
        } catch (e) {
            if (id === loadRequest) {
                error.value =
                    e instanceof Error
                        ? e.message
                        : 'No se pudo leer la información de gastos financieros.';
            }
        } finally {
            if (id === loadRequest) {
                loading.value = false;
            }
        }
    }

    /**
     * Sube (o sustituye, previa confirmación) la tabla de amortización de un crédito.
     * Valida la extensión en el navegador; al terminar recarga panel y dataset y
     * muestra interés/capital del periodo y las columnas leídas.
     * @param credit Crédito del periodo al que pertenece la tabla.
     * @param file Archivo elegido.
     */
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

    /**
     * Descarga la plantilla de tabla de amortización.
     * @param creditKey Crédito para el que se genera (opcional).
     */
    async function downloadTemplate(creditKey?: string): Promise<void> {
        const q = creditKey ? `?credito=${encodeURIComponent(creditKey)}` : '';
        // downloadFile muestra su propia pantalla de carga y explica cualquier error.
        await downloadFile(`${root}/creditos/plantilla-amortizacion${q}`);
    }

    return { data, loading, error, uploading, load, upload, downloadTemplate };
}
