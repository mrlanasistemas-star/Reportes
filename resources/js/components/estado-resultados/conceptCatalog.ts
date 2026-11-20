/** Catálogo de conceptos del Estado de Resultados (contrato del backend ConceptCatalogService). */

export type MatchMode = 'contiene' | 'empieza' | 'exacto';
export type RuleDirection = 'cualquiera' | 'cargo' | 'abono';

export interface ConceptRule {
    id: number;
    name: string;
    match_text: string;
    match_normalized: string;
    match_mode: MatchMode;
    match_mode_label: string;
    fields: string[];
    fields_label: string;
    account_slot: string | null;
    account_label: string;
    direction: RuleDirection;
    direction_label: string;
    treatment: string;
    treatment_label: string;
    line_label: string;
    priority: number;
    active: boolean;
    notes: string | null;
    created_by: string | null;
    updated_by: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface Option {
    key: string;
    label: string;
}

export interface CatalogOptions {
    modes: Option[];
    fields: Option[];
    default_fields: string[];
    directions: Option[];
    accounts: Option[];
    min_text: number;
}

/** Lo que se captura en el diálogo (y lo que se manda al guardar o a la vista previa). */
export interface RuleDraft {
    name: string;
    match_text: string;
    match_mode: MatchMode;
    fields: string[];
    account_slot: string;
    direction: RuleDirection;
    treatment: string;
    priority: number;
    active: boolean;
    notes: string;
}

export interface PreviewItem {
    fingerprint: string;
    date: string | null;
    account_slot: string;
    cargo: string;
    abono: string;
    concept: string | null;
    observations: string | null;
    beneficiary: string | null;
    current: string;
    applies: boolean;
    blocked_reason: string | null;
    redacted: boolean;
}

export interface PreviewResult {
    total: number;
    applies: number;
    blocked: number;
    amount: string;
    items: PreviewItem[];
    truncated: boolean;
}

export const DEFAULT_FIELDS = ['concept', 'observations'];

export function emptyDraft(over: Partial<RuleDraft> = {}): RuleDraft {
    return {
        name: '',
        match_text: '',
        match_mode: 'contiene',
        fields: [...DEFAULT_FIELDS],
        account_slot: '',
        direction: 'cualquiera',
        treatment: '',
        priority: 100,
        active: true,
        notes: '',
        ...over,
    };
}

export function draftFromRule(r: ConceptRule): RuleDraft {
    return {
        name: r.name,
        match_text: r.match_text,
        match_mode: r.match_mode,
        fields: [...r.fields],
        account_slot: r.account_slot ?? '',
        direction: r.direction,
        treatment: r.treatment,
        priority: r.priority,
        active: r.active,
        notes: r.notes ?? '',
    };
}

/** Misma normalización que el backend: mayúsculas, sin acentos, espacios simples. */
export function normalizeText(v: string): string {
    return v
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toUpperCase()
        .trim()
        .replace(/\s+/g, ' ');
}

/** Alta precargada desde el detalle de un movimiento («Crear regla de catálogo con este texto»). */
export function draftFromMovement(
    m: {
        concept: string | null;
        observations: string | null;
        cargo: string;
        treatment: string;
        suggested_treatment: string | null;
    },
    assignable: string[],
): Partial<RuleDraft> {
    const pick = [m.treatment, m.suggested_treatment].find(
        (t): t is string => !!t && t !== 'pendiente' && assignable.includes(t),
    );

    return {
        match_text: (m.concept || m.observations || '').trim(),
        direction: Number(m.cargo) > 0 ? 'cargo' : 'abono',
        treatment: pick ?? '',
    };
}
