import { index as erIndex } from '@/routes/estado-resultados';
import { index as cargaIndex } from '@/routes/historico-general';

/** Carga de archivos abierta directamente en el periodo indicado. */
export function cargaUrl(periodId: number | null | undefined): string {
    return cargaIndex.url(
        periodId ? { query: { periodo: periodId } } : undefined,
    );
}

/** Estado de Resultados → Fuentes del periodo indicado. */
export function fuentesUrl(periodId: number | null | undefined): string {
    return erIndex.url({
        query: periodId
            ? { periodo: periodId, vista: 'fuentes' }
            : { vista: 'fuentes' },
    });
}
