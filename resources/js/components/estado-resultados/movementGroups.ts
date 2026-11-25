/**
 * Agrupación visual de la lista «Control de movimientos» (pestaña Validación).
 *
 * En vez de repetir en cada fila el mismo párrafo («No suma ni se excluye hasta que alguien
 * lo clasifique…»), los movimientos se agrupan por MOTIVO (estado + regla/destino): el motivo
 * se explica una sola vez en el encabezado del grupo, con su conteo y su suma. Dentro de cada
 * motivo, los movimientos del mismo concepto (p. ej. 30 «DEPOSITO LEROM») se juntan en un
 * grupo plegable con su total. Dirección General y socios (concepto con «DG» como palabra, regla
 * de retiro DG/socios o ERP de DG, o tratamiento «personal_socios») van al final, en su propio grupo. Funciones puras: no cambian ni recalculan importes del backend,
 * sólo suman lo que ya viene en cada fila para los encabezados.
 */
import { controlOf } from './movementControl';
import type {
    ControlFlag,
    ControlStatus,
    ControlledMovement,
    OutsideRow,
} from './movementControl';

/** Fila de la lista, ya lista para pintarse (movimiento del periodo o fila remota). */
export interface ControlRowView {
    /** Clave única de la fila (para `key`). */
    id: string;
    fingerprint: string | null;
    date: string | null;
    concept: string | null;
    /** Cuenta y fila (o «ERP de gastos»). */
    account: string;
    file: string | null;
    cargo: number;
    abono: number;
    status: ControlStatus;
    /** Chip corto de la fila (destino o estado). */
    chip: string;
    reasonKey: string;
    reasonTitle: string;
    explain: string;
    flags: ControlFlag[];
    redacted: boolean;
    /** Se puede marcar para clasificar. */
    selectable: boolean;
    /** Se puede abrir el detalle. */
    openable: boolean;
    /** Es de Dirección General o socios por regla o tratamiento (además del concepto con «DG»). */
    dgPartner?: boolean;
}

/** Movimientos del mismo concepto (y sentido) dentro de un motivo. */
export interface ConceptBundle {
    key: string;
    label: string;
    direction: 'cargo' | 'abono';
    rows: ControlRowView[];
    total: number;
    /** Cuántas redacciones distintas del concepto hay en el grupo. */
    variants: number;
}

/** Grupo por motivo: encabezado con explicación única, conteo y suma. */
export interface ReasonGroup {
    key: string;
    /** Estado común (null en Dirección General si mezcla estados). */
    status: ControlStatus | null;
    title: string;
    explain: string;
    dg: boolean;
    rows: ControlRowView[];
    cargos: number;
    abonos: number;
    bundles: ConceptBundle[];
}

/** Explicación corta de cada estado (se muestra una vez por grupo). */
export const STATUS_HINT: Record<ControlStatus, string> = {
    completado:
        'Ya tiene destino: conciliado con ERP, reconocido en el ER o excluido con justificación.',
    pendiente: 'No suma ni se excluye hasta que alguien lo clasifique.',
    duplicado: 'Otro movimiento ya cuenta por éste: se suma una sola vez.',
    fuera: 'Su fecha no cae en este periodo: se conserva aquí sin sumar.',
    no_guardado:
        'Se leyó del archivo pero no quedó guardado: vuelve a cargar el archivo para recuperarlo.',
};

/** Texto corto del chip de estado. */
export const STATUS_SHORT: Record<ControlStatus, string> = {
    completado: 'Completado',
    pendiente: 'Pendiente',
    duplicado: 'Repetido',
    fuera: 'Fuera del periodo',
    no_guardado: 'No guardado',
};

/** Orden de los grupos: primero lo que requiere acción. */
const STATUS_ORDER: ControlStatus[] = [
    'pendiente',
    'no_guardado',
    'duplicado',
    'fuera',
    'completado',
];

/** Subestado de un completado → frase. */
const SUB_LABEL: Record<string, string> = {
    conciliado: 'Conciliado con ERP',
    reconocido: 'Reconocido en el ER',
    excluido: 'Excluido con justificación',
};

/** Mínimo de movimientos del mismo concepto para juntarlos en un grupo plegable. */
export const BUNDLE_MIN = 3;

/**
 * ¿El concepto es de Dirección General? («DG» como palabra suelta, sin importar mayúsculas).
 * @param concept concepto del movimiento.
 */
export function isDgConcept(concept: string | null | undefined): boolean {
    return (
        !!concept &&
        /(?:^|[^\p{L}\p{N}])DG(?:[^\p{L}\p{N}]|$)/u.test(concept.toUpperCase())
    );
}

/** Reglas de clasificación de retiros de Dirección General y socios. */
const DG_PARTNER_RULES = new Set(['retiro_dg', 'retiro_socios', 'erp_dg']);

/** Tratamiento de retiro de DG / socios. */
const PARTNER_TREATMENT = 'personal_socios';

/**
 * ¿La fila va al grupo «Dirección General y socios»? Concepto con «DG», regla de retiro
 * DG/socios (o ERP de DG) o tratamiento automático/vigente «personal_socios».
 * @param r fila de la lista.
 */
export function isDgPartnerRow(r: ControlRowView): boolean {
    return !!r.dgPartner || isDgConcept(r.concept);
}

/**
 * Clave normalizada de un concepto: sin acentos, en mayúsculas y sin las palabras que
 * llevan dígitos (referencias, folios, fechas), para juntar «DEPOSITO LEROM 0012» y
 * «DEPOSITO LEROM 0034».
 * @param concept concepto del movimiento.
 */
export function conceptKey(concept: string | null | undefined): string {
    const key = (concept ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toUpperCase()
        .split(/[^A-Z0-9]+/)
        .filter((t) => t && !/\d/.test(t))
        .join(' ');

    return key || '—';
}

/** Número de un importe en texto (vacío o inválido = 0). */
const n = (v: string | number | null | undefined) => Number(v) || 0;

/**
 * Convierte un movimiento del periodo en fila de la lista.
 * @param m movimiento con control.
 * @param canAct el usuario puede clasificar (permiso y periodo abierto).
 */
export function rowFromMovement(
    m: ControlledMovement,
    canAct: boolean,
): ControlRowView {
    const c = controlOf(m);
    let reasonKey: string;
    let reasonTitle: string;

    switch (c.status) {
        case 'pendiente':
            reasonKey = `pendiente|${m.rule_code ?? ''}`;
            reasonTitle =
                (m.rule_reason ?? '').trim() || 'Sin regla que lo reconozca';
            break;
        case 'completado': {
            const sub = c.sub ?? '';
            reasonKey = `completado|${sub}|${m.treatment}`;
            reasonTitle = [SUB_LABEL[sub], m.treatment_label]
                .filter(Boolean)
                .join(' · ');
            break;
        }
        case 'duplicado':
            reasonKey = `duplicado|${c.sub ?? ''}`;
            reasonTitle =
                c.sub === 'duplicado_uuid'
                    ? 'Mismo UUID, importe y fecha que otro movimiento'
                    : 'Otro archivo ya trae este movimiento';
            break;
        default:
            reasonKey = `${c.status}|${m.placement ?? ''}`;
            reasonTitle =
                m.placement_label ?? 'La fecha no cae dentro del periodo';
    }

    return {
        id: `${m.fingerprint}|${m.upload_id ?? ''}|${m.source_row}`,
        fingerprint: m.fingerprint || null,
        date: m.date ?? null,
        concept: m.concept ?? null,
        account:
            m.source === 'erp'
                ? 'ERP de gastos'
                : m.source === 'noi_corp'
                  ? 'NOI de corporativo'
                  : `${m.account_label ?? m.account_slot ?? '—'} · fila ${m.source_row}`,
        file:
            m.source === 'erp' || m.source === 'noi_corp'
                ? null
                : (m.file ?? null),
        cargo: n(m.cargo),
        abono: n(m.abono),
        status: c.status,
        chip:
            c.status === 'completado'
                ? (m.treatment_label ?? STATUS_SHORT.completado)
                : STATUS_SHORT[c.status],
        reasonKey,
        reasonTitle,
        explain: c.explain,
        flags: c.flags ?? [],
        redacted: !!m.redacted,
        selectable:
            canAct && !m.redacted && m.source !== 'noi_corp' && !!m.fingerprint,
        openable: !m.redacted && !!m.fingerprint,
        dgPartner:
            DG_PARTNER_RULES.has(m.rule_code ?? '') ||
            m.treatment === PARTNER_TREATMENT ||
            m.auto_treatment === PARTNER_TREATMENT,
    };
}

/**
 * Convierte una fila remota (otro periodo / no guardada) en fila de la lista.
 * @param r fila del endpoint de control.
 */
export function rowFromOutside(r: OutsideRow): ControlRowView {
    const where = r.period_label ? ` · ${r.period_label}` : '';

    return {
        id: `${r.id ?? 'ng'}-${r.upload_id}-${r.source_row}`,
        fingerprint: r.fingerprint,
        date: r.date,
        concept: r.concept,
        account: `${r.account_label ?? r.account_slot ?? '—'} · fila ${r.source_row}`,
        file: r.file,
        cargo: n(r.cargo),
        abono: n(r.abono),
        status: r.status,
        chip:
            r.treatment_label && r.part === 'otro_periodo'
                ? `Allá: ${r.treatment_label}`
                : STATUS_SHORT[r.status],
        reasonKey: `${r.status}|${r.part ?? r.placement ?? ''}|${r.period_label ?? ''}`,
        reasonTitle:
            r.status === 'no_guardado'
                ? 'Leído del archivo y no guardado'
                : `${r.status_label}${where}`,
        explain: r.explain,
        flags: [],
        redacted: !!r.redacted,
        selectable: false,
        openable: false,
        dgPartner: DG_PARTNER_RULES.has(r.rule_code ?? ''),
    };
}

/**
 * Agrupa las filas por motivo y, dentro, por concepto. Dirección General y socios van al final.
 * @param rows filas ya filtradas (en el orden del dataset).
 */
export function groupRows(rows: ControlRowView[]): ReasonGroup[] {
    const groups = new Map<string, ReasonGroup>();
    const dg: ReasonGroup = {
        key: 'dg',
        status: null,
        title: 'Dirección General y socios',
        explain:
            'Movimientos de Dirección General (DG) y retiros de socios: se muestran aparte, al final de la lista. Cada fila indica su estado.',
        dg: true,
        rows: [],
        cargos: 0,
        abonos: 0,
        bundles: [],
    };

    for (const r of rows) {
        let g: ReasonGroup | undefined = isDgPartnerRow(r)
            ? dg
            : groups.get(r.reasonKey);

        if (!g) {
            g = {
                key: r.reasonKey,
                status: r.status,
                title: r.reasonTitle,
                explain: '',
                dg: false,
                rows: [],
                cargos: 0,
                abonos: 0,
                bundles: [],
            };
            groups.set(r.reasonKey, g);
        }

        g.rows.push(r);
        g.cargos += r.cargo;
        g.abonos += r.abono;
    }

    const list = [...groups.values()].sort(
        (a, b) =>
            STATUS_ORDER.indexOf(a.status as ControlStatus) -
                STATUS_ORDER.indexOf(b.status as ControlStatus) ||
            b.cargos + b.abonos - (a.cargos + a.abonos),
    );

    if (dg.rows.length) {
        const statuses = new Set(dg.rows.map((r) => r.status));
        dg.status = statuses.size === 1 ? dg.rows[0].status : null;
        list.push(dg);
    }

    for (const g of list) {
        if (!g.dg) {
            const first = g.rows[0].explain;
            g.explain =
                first && g.rows.every((r) => r.explain === first)
                    ? first
                    : STATUS_HINT[g.status as ControlStatus];
        }

        g.bundles = bundle(g.rows);
    }

    return list;
}

/**
 * Junta las filas del mismo concepto y sentido. Los conceptos con más filas van primero;
 * a igual conteo, en el orden en que aparecen.
 * @param rows filas de un motivo.
 */
function bundle(rows: ControlRowView[]): ConceptBundle[] {
    const map = new Map<string, ConceptBundle & { raw: Set<string> }>();

    for (const r of rows) {
        const direction = r.cargo ? 'cargo' : 'abono';
        const key = `${direction}|${conceptKey(r.concept)}`;
        let b = map.get(key);

        if (!b) {
            b = {
                key,
                label: r.concept ?? '—',
                direction,
                rows: [],
                total: 0,
                variants: 0,
                raw: new Set(),
            };
            map.set(key, b);
        }

        b.rows.push(r);
        b.total += r.cargo + r.abono;
        b.raw.add(r.concept ?? '');
    }

    return [...map.values()]
        .map(({ raw, ...b }) => ({
            ...b,
            label: raw.size > 1 ? conceptKey(b.label) : b.label,
            variants: raw.size,
        }))
        .sort(
            (a, b) =>
                (b.rows.length >= BUNDLE_MIN ? b.rows.length : 0) -
                (a.rows.length >= BUNDLE_MIN ? a.rows.length : 0),
        );
}

/** Entrada pintable de un grupo: una fila suelta o un grupo plegable de concepto. */
export type LayoutEntry =
    | { kind: 'row'; row: ControlRowView }
    | { kind: 'bundle'; bundle: ConceptBundle };

/** Grupo por motivo con las entradas que caben en el límite de filas. */
export interface LayoutSection {
    group: ReasonGroup;
    entries: LayoutEntry[];
    /** Filas del grupo que no se alcanzaron a pintar. */
    hidden: number;
}

/**
 * Reparte el límite de filas («Mostrar más») entre los grupos: cada fila suelta o grupo
 * plegable cuenta como una. Devuelve también las filas representadas (para «seleccionar
 * visibles») y cuántas faltan.
 * @param groups grupos por motivo.
 * @param limit máximo de entradas a pintar.
 */
export function layoutGroups(
    groups: ReasonGroup[],
    limit: number,
): { sections: LayoutSection[]; covered: ControlRowView[]; remaining: number } {
    let budget = limit;
    const covered: ControlRowView[] = [];
    let total = 0;
    const sections: LayoutSection[] = [];

    for (const g of groups) {
        total += g.rows.length;

        if (budget <= 0) {
            continue;
        }

        const entries: LayoutEntry[] = [];
        let shown = 0;

        for (const b of g.bundles) {
            if (budget <= 0) {
                break;
            }

            if (b.rows.length >= BUNDLE_MIN) {
                entries.push({ kind: 'bundle', bundle: b });
                covered.push(...b.rows);
                shown += b.rows.length;
                budget--;
                continue;
            }

            for (const r of b.rows) {
                if (budget <= 0) {
                    break;
                }

                entries.push({ kind: 'row', row: r });
                covered.push(r);
                shown++;
                budget--;
            }
        }

        sections.push({ group: g, entries, hidden: g.rows.length - shown });
    }

    return { sections, covered, remaining: total - covered.length };
}
