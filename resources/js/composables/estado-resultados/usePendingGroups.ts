/**
 * Módulo: pendientes agrupados por concepto (pestaña Validación → Control de movimientos).
 *
 * GET  `/control-movimientos/grupos`               grupos (mismo dataset que la lista)
 * POST `/control-movimientos/grupos/vista-previa`  qué cambia / qué se excluye y por qué
 * POST `/control-movimientos/grupos/clasificar`    clasificación manual masiva (bitácora)
 * POST `/control-movimientos/deshacer`             deshacer clasificación manual
 *
 * Todas atendidas por EstadoResultadosControlController; el backend recalcula exclusiones y
 * permisos: el front sólo muestra.
 */
import { ref } from 'vue';
import type {
    BulkPreview,
    PendingGroupsResponse,
} from '@/components/estado-resultados/movementControl';
import { apiJson, isAbortError } from '@/components/estado-resultados/types';

/** Contexto que inyecta la pantalla del ER. */
interface Ctx {
    /** `/estado-resultados/{periodo}` + path */
    url: (path: string) => string;
    /** Query string con el alcance (la versión se quita antes de llamar). */
    scopeQuery: () => string;
}

/**
 * Estado y llamadas de los pendientes agrupados.
 * @param ctx helpers de URL y alcance.
 */
export function usePendingGroups(ctx: Ctx) {
    const data = ref<PendingGroupsResponse | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    /** Operación masiva en curso (evita doble envío). */
    const busy = ref(false);
    let abort: AbortController | null = null;

    /** Pide los grupos (cancela la petición anterior). */
    async function load(): Promise<void> {
        abort?.abort();
        const controller = new AbortController();
        abort = controller;
        loading.value = true;
        error.value = null;
        const params = new URLSearchParams(ctx.scopeQuery());
        params.delete('version');
        params.set('limite', '200');

        try {
            const res = await apiJson<PendingGroupsResponse>(
                ctx.url(`/control-movimientos/grupos?${params.toString()}`),
                { signal: controller.signal },
            );

            if (controller === abort) {
                data.value = res;
            }
        } catch (e) {
            if (!isAbortError(e) && controller === abort) {
                error.value =
                    e instanceof Error
                        ? e.message
                        : 'No se pudieron agrupar los pendientes.';
            }
        } finally {
            if (controller === abort) {
                loading.value = false;
            }
        }
    }

    /**
     * Vista previa de la clasificación manual masiva (sólo lectura).
     * @param fingerprints huellas del grupo o selección.
     * @param treatment tratamiento destino.
     */
    function preview(
        fingerprints: string[],
        treatment: string,
    ): Promise<BulkPreview> {
        return apiJson<BulkPreview>(
            ctx.url('/control-movimientos/grupos/vista-previa'),
            {
                method: 'POST',
                body: JSON.stringify({ fingerprints, treatment }),
            },
        );
    }

    /**
     * Aplica la clasificación manual masiva (el servidor vuelve a excluir lo protegido).
     * @returns mensaje y conteos del servidor.
     */
    async function apply(
        fingerprints: string[],
        treatment: string,
        notes: string,
    ): Promise<{ message: string; count: number; excluded: number }> {
        busy.value = true;

        try {
            return await apiJson(
                ctx.url('/control-movimientos/grupos/clasificar'),
                {
                    method: 'POST',
                    body: JSON.stringify({ fingerprints, treatment, notes }),
                },
            );
        } finally {
            busy.value = false;
        }
    }

    /** Deshace la clasificación manual de esas huellas (vuelve a la regla automática). */
    async function revert(
        fingerprints: string[],
        notes: string,
    ): Promise<{ message: string; count: number }> {
        busy.value = true;

        try {
            return await apiJson(ctx.url('/control-movimientos/deshacer'), {
                method: 'POST',
                body: JSON.stringify({ fingerprints, notes }),
            });
        } finally {
            busy.value = false;
        }
    }

    return { data, loading, error, busy, load, preview, apply, revert };
}

/** Tipo del objeto que devuelve `usePendingGroups`. */
export type PendingGroups = ReturnType<typeof usePendingGroups>;
