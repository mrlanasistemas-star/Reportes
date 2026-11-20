import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ref } from 'vue';
import type {
    CatalogOptions,
    ConceptRule,
    PreviewResult,
    RuleDraft,
} from '@/components/estado-resultados/conceptCatalog';
import { apiJson, escapeHtml } from '@/components/estado-resultados/types';
import { useConfirm } from '@/composables/useConfirm';
import { showError, swal } from './erSwal';

interface Ctx {
    periodId: Ref<number>;
    /** Puede procesar la conciliación del periodo mostrado (permiso + periodo no aprobado). */
    canProcess: () => boolean;
    /** Motivo por el que el periodo mostrado no admite cambios (aprobado / histórico). */
    lockReason: () => string | null;
    /** Reprocesa el periodo (sin volver a preguntar). */
    process: () => Promise<void>;
}

/**
 * Catálogo de conceptos: lista, alta/edición, activar/desactivar, borrar y vista previa.
 * Toda la retroalimentación pasa por SweetAlert2; tras guardar se ofrece procesar la
 * conciliación (el catálogo sólo se aplica al procesar).
 */
export function useConceptCatalog(ctx: Ctx) {
    const { confirm } = useConfirm();
    const base = (usePage().props as { base?: string }).base ?? '';
    const root = `${base}/estado-resultados/catalogo-conceptos`;

    const rules = ref<ConceptRule[]>([]);
    const options = ref<CatalogOptions | null>(null);
    const loading = ref(false);
    const loadError = ref<string | null>(null);
    const saving = ref(false);
    const busyId = ref<number | null>(null);
    const preview = ref<PreviewResult | null>(null);
    const previewLoading = ref(false);
    const previewError = ref<string | null>(null);
    let previewSeq = 0;

    async function load(): Promise<void> {
        loading.value = true;
        loadError.value = null;

        try {
            const res = await apiJson<{
                rules: ConceptRule[];
                options: CatalogOptions;
            }>(root);
            rules.value = res.rules;
            options.value = res.options;
        } catch (e) {
            loadError.value =
                e instanceof Error
                    ? e.message
                    : 'No se pudo leer el catálogo de conceptos.';
        } finally {
            loading.value = false;
        }
    }

    function payload(d: RuleDraft) {
        return {
            ...d,
            name: d.name.trim(),
            match_text: d.match_text.trim(),
            account_slot: d.account_slot || null,
            priority: Math.max(
                0,
                Math.min(1000, Math.round(Number(d.priority) || 0)),
            ),
            notes: d.notes.trim() || null,
        };
    }

    /** Ofrece reprocesar el periodo para que la regla se aplique. */
    async function offerProcess(title: string): Promise<void> {
        const locked = ctx.lockReason();

        if (locked) {
            await swal.fire({
                icon: 'success',
                title,
                html: `<p>El periodo que estás viendo no admite cambios (${escapeHtml(locked)}), así que aquí no se aplica.</p><p style="margin-top:8px">La regla se aplicará en los periodos abiertos cuando se procese su conciliación.</p>`,
                confirmButtonText: 'Entendido',
            });

            return;
        }

        if (!ctx.canProcess()) {
            await swal.fire({
                icon: 'success',
                title,
                text: 'La regla se aplicará la próxima vez que alguien con permiso procese la conciliación del periodo.',
                confirmButtonText: 'Entendido',
            });

            return;
        }

        const ok = await confirm({
            title,
            text: 'El catálogo se aplica al procesar la conciliación. ¿Quieres procesarla ahora para ver el cambio en el reporte?',
            icon: 'success',
            confirmText: 'Procesar conciliación',
        });

        if (ok.isConfirmed) {
            await ctx.process();
        }
    }

    async function save(draft: RuleDraft, id: number | null): Promise<boolean> {
        saving.value = true;

        try {
            const res = await apiJson<{ message: string; rule: ConceptRule }>(
                id ? `${root}/${id}` : root,
                {
                    method: id ? 'PUT' : 'POST',
                    body: JSON.stringify(payload(draft)),
                },
            );
            await load();
            saving.value = false;
            await offerProcess(res.message);

            return true;
        } catch (e) {
            await showError(e, 'No se guardó la regla');

            return false;
        } finally {
            saving.value = false;
        }
    }

    async function toggle(rule: ConceptRule): Promise<void> {
        busyId.value = rule.id;

        try {
            const res = await apiJson<{ message: string }>(
                `${root}/${rule.id}/estado`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({ active: !rule.active }),
                },
            );
            await load();
            busyId.value = null;
            await offerProcess(res.message);
        } catch (e) {
            await showError(e, 'No se cambió la regla');
        } finally {
            busyId.value = null;
        }
    }

    async function remove(rule: ConceptRule): Promise<void> {
        const ok = await confirm({
            title: `¿Borrar la regla «${rule.name}»?`,
            text: 'Dejará de aplicarse al procesar la conciliación. El cambio queda registrado en la bitácora del catálogo. Si sólo quieres pausarla, mejor desactívala.',
            icon: 'warning',
            confirmText: 'Sí, borrar',
        });

        if (!ok.isConfirmed) {
            return;
        }

        busyId.value = rule.id;

        try {
            const res = await apiJson<{ message: string }>(
                `${root}/${rule.id}`,
                { method: 'DELETE' },
            );
            await load();
            busyId.value = null;
            await offerProcess(res.message);
        } catch (e) {
            await showError(e, 'No se borró la regla');
        } finally {
            busyId.value = null;
        }
    }

    /** Vista previa contra el periodo mostrado; una respuesta vieja se descarta. */
    async function runPreview(
        draft: RuleDraft,
        id: number | null,
    ): Promise<void> {
        const seq = ++previewSeq;
        previewLoading.value = true;
        previewError.value = null;

        try {
            const res = await apiJson<PreviewResult>(
                `${base}/estado-resultados/${ctx.periodId.value}/catalogo-conceptos/vista-previa`,
                {
                    method: 'POST',
                    body: JSON.stringify({ ...payload(draft), rule_id: id }),
                },
            );

            if (seq === previewSeq) {
                preview.value = res;
            }
        } catch (e) {
            if (seq === previewSeq) {
                preview.value = null;
                previewError.value =
                    e instanceof Error
                        ? e.message
                        : 'No se pudo calcular la vista previa.';
            }
        } finally {
            if (seq === previewSeq) {
                previewLoading.value = false;
            }
        }
    }

    function clearPreview(): void {
        previewSeq++;
        preview.value = null;
        previewError.value = null;
        previewLoading.value = false;
    }

    return {
        rules,
        options,
        loading,
        loadError,
        saving,
        busyId,
        preview,
        previewLoading,
        previewError,
        load,
        save,
        toggle,
        remove,
        runPreview,
        clearPreview,
    };
}
