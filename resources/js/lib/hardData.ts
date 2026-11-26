/** Utilidades del módulo «Datos duros»: normalización de búsqueda, etiquetas y colores. */

/** Minúsculas, sin acentos y con espacios colapsados (búsqueda "orizaba" = "ORIZABA" = "Orizabá"). */
export function normalizeText(value: string | null | undefined): string {
    return (value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

/** Palabras de búsqueda (todas deben coincidir). */
export function searchTokens(query: string): string[] {
    return normalizeText(query).split(' ').filter(Boolean);
}

/** Categorías EBITDA en orden de mayor a menor, con su estilo de chip. */
export const CATEGORY_META: Record<
    string,
    { label: string; chip: string; dot: string; min: string }
> = {
    DIAMANTE: {
        label: 'Diamante',
        chip: 'bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/30',
        dot: 'bg-sky-500',
        min: 'EBITDA ≥ $1M',
    },
    MASTER: {
        label: 'Master',
        chip: 'bg-violet-100 text-violet-800 ring-violet-200 dark:bg-violet-500/15 dark:text-violet-300 dark:ring-violet-500/30',
        dot: 'bg-violet-500',
        min: 'EBITDA ≥ $600K',
    },
    SENIOR: {
        label: 'Senior',
        chip: 'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30',
        dot: 'bg-emerald-500',
        min: 'EBITDA ≥ $300K',
    },
    JUNIOR: {
        label: 'Junior',
        chip: 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30',
        dot: 'bg-amber-500',
        min: 'EBITDA ≥ $100K',
    },
    MANTENIDO: {
        label: 'Mantenido',
        chip: 'bg-rose-100 text-rose-800 ring-rose-200 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/30',
        dot: 'bg-rose-500',
        min: 'EBITDA < $100K',
    },
};

export const CATEGORY_ORDER = [
    'DIAMANTE',
    'MASTER',
    'SENIOR',
    'JUNIOR',
    'MANTENIDO',
];

export function categoryMeta(category: string | null | undefined) {
    return (
        CATEGORY_META[(category ?? '').toUpperCase()] ?? CATEGORY_META.MANTENIDO
    );
}

/** Antigüedad de la cartera (mismas llaves que la API v1). */
export const BUCKET_LABELS: Record<string, string> = {
    al_corriente: 'Al corriente',
    mora_1_30: '1–30 días',
    mora_31_60: '31–60 días',
    mora_61_90: '61–90 días',
    mora_91_120: '91–120 días',
    mora_120_plus: '+120 días',
};

export const BUCKET_COLORS: Record<string, string> = {
    al_corriente: 'bg-emerald-500',
    mora_1_30: 'bg-lime-500',
    mora_31_60: 'bg-amber-400',
    mora_61_90: 'bg-orange-500',
    mora_91_120: 'bg-rose-500',
    mora_120_plus: 'bg-rose-700',
};

export const RECOVERY_LABELS: Record<string, string> = {
    capital: 'Capital',
    interest: 'Interés',
    tax: 'Impuesto',
    late_charges: 'Moratorios',
    additional_charges: 'Cargos adicionales',
    surplus: 'Excedente',
    initial_charges: 'Cargos de inicio',
    opening_fee: 'Comisión de apertura',
    crece_insurance_recognized: 'Seguro CRECE',
    other: 'Otros',
};

export const PAYROLL_LABELS: Record<string, string> = {
    sueldo: 'Sueldo',
    comisiones: 'Comisiones',
    bonos: 'Bonos',
    bonos_aceleradores: 'Bonos aceleradores',
    vacaciones: 'Vacaciones',
    prima_vacacional: 'Prima vacacional',
    otras_percepciones: 'Otras percepciones',
};

/** Tono de la mora: verde < 5 %, ámbar < 15 %, rojo ≥ 15 %. */
export function moraTone(
    pct: number | null | undefined,
): 'success' | 'warning' | 'danger' {
    const v = Number(pct ?? 0);

    if (v >= 15) {
        return 'danger';
    }

    return v >= 5 ? 'warning' : 'success';
}
