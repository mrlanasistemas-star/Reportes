<script setup lang="ts">
// Permisos de un rol por módulo — Fase 8.11 (15-oct-2026): antes era una
// tabla módulo × columna con casillas y textos tachados (pared de checkboxes
// difícil de leer, sobre todo en móvil). Ahora cada módulo es una tarjeta con
// su acceso ("Ver") y sus acciones agrupadas por tipo (Crear / Editar /
// Eliminar / Exportar / Autorizar), cada una con su explicación breve. Solo se
// muestran operaciones que EXISTEN en el catálogo del backend. En modo
// editable cada acción es una casilla; si no se puede marcar se explica por qué.
import { Check, Eye, EyeOff, Info, Lock, Minus } from 'lucide-vue-next'
import { computed } from 'vue'
import { MATRIX_COLUMNS, MODULE_NOTES, actionLabel, actionsIn, dependentsOf, requiredBy } from './roles'
import type { ActionColumn, ActionOption, Grantable, ModuleOption } from './roles'

const props = defineProps<{
    modules: string[]
    actions: Record<string, string[]>
    moduleCatalog: ModuleOption[]
    actionCatalog: ActionOption[]
    /** Solo estos módulos como filas (p. ej. los elegidos en el paso 2). Omitido = todos. */
    rows?: string[]
    editable?: boolean
    grantable?: Grantable | null
}>()
const emit = defineEmits<{ toggle: [module: string, action: string] }>()

function has(module: string, action: string): boolean {
    return props.actions[module]?.includes(action) ?? false
}

function canGrant(module: string, action: string): boolean {
    return !props.grantable || (props.grantable.actions[module]?.includes(action) ?? false) || has(module, action)
}

const ACTION_COLUMNS = MATRIX_COLUMNS.filter((c) => c.key !== 'ver') as { key: ActionColumn; label: string }[]

const cards = computed(() => {
    const list = props.rows ? props.moduleCatalog.filter((m) => props.rows!.includes(m.key)) : props.moduleCatalog

    return list.map((m) => {
        const enabled = props.modules.includes(m.key)
        const groups = ACTION_COLUMNS.map((c) => ({ ...c, items: actionsIn(props.actionCatalog, m.key, c.key) })).filter((g) => g.items.length > 0)
        const total = groups.reduce((n, g) => n + g.items.length, 0)
        const granted = enabled ? groups.reduce((n, g) => n + g.items.filter((a) => has(m.key, a.action)).length, 0) : 0

        return { module: m, enabled, groups, total, granted }
    })
})

/** "Incluye también …" / "Necesaria para …" — dependencias entre acciones (ActionPermissionService::REQUIRES). */
function dependencyText(module: string, action: string): string | null {
    const quote = (list: string[]) => list.map((a) => `«${actionLabel(props.actionCatalog, module, a)}»`).join(', ')
    const req = requiredBy(props.actionCatalog, module, action)
    const deps = dependentsOf(props.actionCatalog, module, action)
    const parts: string[] = []

    if (req.length) {
        parts.push(`Incluye también ${quote(req)} (se marca sola).`)
    }

    if (deps.length) {
        parts.push(`Necesaria para ${quote(deps)}: si la quitas, se quitan también.`)
    }

    return parts.length ? parts.join(' ') : null
}

function disabledReason(module: string, action: string, enabled: boolean): string | null {
    if (!enabled) {
        return 'Primero da acceso a este módulo.'
    }

    if (!canGrant(module, action)) {
        return 'Tu propio rol no tiene esta acción; no puedes otorgarla.'
    }

    return null
}
</script>

<template>
    <div class="@container space-y-3">
        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span><strong class="font-semibold text-foreground">Ver</strong> da acceso al módulo (aparece en el menú). Las acciones solo aplican a los módulos con acceso; el servidor vuelve a validar cada una.</span>
        </p>

        <div class="grid gap-3 @4xl:grid-cols-2">
            <article
                v-for="card in cards"
                :key="card.module.key"
                class="flex flex-col rounded-xl border bg-card"
                :class="card.enabled ? 'border-border' : 'border-dashed border-border bg-muted/30'"
            >
                <header class="flex items-start gap-3 border-b px-4 py-3">
                    <span
                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg border"
                        :class="card.enabled ? 'border-success-border bg-success-soft text-success' : 'bg-muted text-muted-foreground'"
                        aria-hidden="true"
                    >
                        <Eye v-if="card.enabled" class="size-4" /><EyeOff v-else class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-sm font-semibold text-foreground">{{ card.module.label }}</h3>
                        <p v-if="card.module.description" class="mt-0.5 text-xs leading-relaxed text-muted-foreground">{{ card.module.description }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <span :class="['app-pill', card.enabled ? 'app-pill-success' : 'app-pill-muted']">
                            {{ card.enabled ? 'Con acceso' : 'Sin acceso' }}
                        </span>
                        <span v-if="card.enabled && card.total > 0" class="text-[11px] text-muted-foreground tabular-nums">{{ card.granted }} de {{ card.total }} acciones</span>
                    </div>
                </header>

                <div class="flex-1 px-4 py-3">
                    <p v-if="MODULE_NOTES[card.module.key] && (card.enabled || editable)" class="mb-3 flex items-start gap-1.5 rounded-lg bg-info-soft px-2.5 py-2 text-[11px] leading-relaxed text-foreground">
                        <Info class="mt-0.5 size-3 shrink-0 text-info" aria-hidden="true" />
                        <span>{{ MODULE_NOTES[card.module.key] }}</span>
                    </p>
                    <p v-if="card.groups.length === 0" class="text-xs text-muted-foreground">
                        Solo consulta: este módulo no tiene acciones adicionales.
                    </p>

                    <p v-else-if="!card.enabled && !editable" class="text-xs text-muted-foreground">
                        Sin acceso al módulo, sus {{ card.total }} acciones no aplican.
                    </p>

                    <dl v-else class="space-y-3">
                        <div v-for="group in card.groups" :key="group.key" class="grid gap-1.5 sm:grid-cols-[84px_minmax(0,1fr)] sm:gap-3">
                            <dt class="pt-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{{ group.label }}</dt>
                            <dd>
                                <ul class="flex flex-wrap gap-1.5" :class="editable ? 'flex-col' : ''">
                                    <li v-for="a in group.items" :key="a.action">
                                        <!-- Edición: casilla con su explicación y, si no se puede, el motivo -->
                                        <label
                                            v-if="editable"
                                            class="flex items-start gap-2.5 rounded-lg border px-3 py-2 transition-colors"
                                            :class="disabledReason(card.module.key, a.action, card.enabled)
                                                ? 'cursor-not-allowed bg-muted/40 opacity-70'
                                                : has(card.module.key, a.action) ? 'cursor-pointer border-primary/40 bg-accent/60' : 'cursor-pointer hover:bg-muted/50'"
                                        >
                                            <input
                                                type="checkbox"
                                                class="mt-0.5 size-4 shrink-0 accent-[var(--primary)]"
                                                :checked="has(card.module.key, a.action)"
                                                :disabled="!!disabledReason(card.module.key, a.action, card.enabled)"
                                                @change="emit('toggle', card.module.key, a.action)"
                                            >
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium text-foreground">{{ a.label }}</span>
                                                <span v-if="a.description" class="block text-xs leading-relaxed text-muted-foreground">{{ a.description }}</span>
                                                <span v-if="dependencyText(card.module.key, a.action)" class="mt-0.5 block text-[11px] leading-relaxed font-medium text-info">{{ dependencyText(card.module.key, a.action) }}</span>
                                                <span v-if="disabledReason(card.module.key, a.action, card.enabled)" class="mt-0.5 flex items-center gap-1 text-[11px] font-medium text-warning">
                                                    <Lock class="size-3" aria-hidden="true" /> {{ disabledReason(card.module.key, a.action, card.enabled) }}
                                                </span>
                                            </span>
                                        </label>

                                        <!-- Consulta: chip permitido / no permitido (sin tachados) -->
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 rounded-md border px-2 py-1 text-xs"
                                            :class="has(card.module.key, a.action) ? 'border-success-border bg-success-soft font-medium text-foreground' : 'border-border text-muted-foreground'"
                                            :title="[a.description, dependencyText(card.module.key, a.action)].filter(Boolean).join(' ')"
                                        >
                                            <Check v-if="has(card.module.key, a.action)" class="size-3 shrink-0 text-success" aria-hidden="true" />
                                            <Minus v-else class="size-3 shrink-0" aria-hidden="true" />
                                            {{ a.label }}
                                            <span class="sr-only">{{ has(card.module.key, a.action) ? '(permitido)' : '(no permitido)' }}. {{ a.description }}</span>
                                        </span>
                                    </li>
                                </ul>
                            </dd>
                        </div>
                    </dl>
                </div>
            </article>
        </div>
    </div>
</template>
