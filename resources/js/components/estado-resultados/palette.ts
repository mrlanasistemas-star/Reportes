/**
 * Colores con significado en todo el módulo: el OPEX (dinero que sale) en tonos
 * rosa, de más oscuro (rubro mayor) a más claro; el EBITDA en cian; resultados en verde.
 */
export const OPEX_TONES = [
    'bg-rose-600 dark:bg-rose-500',
    'bg-rose-500 dark:bg-rose-400',
    'bg-rose-400 dark:bg-rose-300',
    'bg-rose-300 dark:bg-rose-200',
    'bg-rose-200 dark:bg-rose-100',
];

/** Tono de una categoría según su lugar por importe (0 = la mayor). */
export function opexTone(rank: number): string {
    return OPEX_TONES[Math.min(Math.max(rank, 0), OPEX_TONES.length - 1)];
}
