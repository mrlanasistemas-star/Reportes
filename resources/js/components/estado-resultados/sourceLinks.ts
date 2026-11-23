import { index as erIndex } from '@/routes/estado-resultados';
import { index as cargaIndex } from '@/routes/historico-general';

/**
 * Enlaces compartidos hacia las pantallas donde se suben o revisan las fuentes
 * del Estado de Resultados (Histórico general y la pestaña «Fuentes»).
 */
/** Carga de archivos abierta directamente en el periodo indicado. */
/**
 * @param periodId id del periodo; si es nulo se abre la carga sin periodo preseleccionado.
 * @returns URL de Histórico general con `?periodo=`.
 */
export function cargaUrl(periodId: number | null | undefined): string {
    return cargaIndex.url(
        periodId ? { query: { periodo: periodId } } : undefined,
    );
}

/** Estado de Resultados → Fuentes del periodo indicado. */
/**
 * @param periodId id del periodo; si es nulo sólo se fija `vista=fuentes`.
 * @returns URL de Estado de Resultados con la pestaña Fuentes abierta.
 */
export function fuentesUrl(periodId: number | null | undefined): string {
    return erIndex.url({
        query: periodId
            ? { periodo: periodId, vista: 'fuentes' }
            : { vista: 'fuentes' },
    });
}
