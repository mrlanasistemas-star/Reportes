<script setup lang="ts">
// Matriz visual módulo × (Ver/Crear/Editar/Eliminar/Exportar/Autorizar).
// Solo muestra operaciones que EXISTEN (catálogo del backend); una celda sin
// operación real se muestra como "—". En modo editable, cada acción es un
// interruptor (deshabilitado si quien edita no puede otorgarla).
import { Check, Minus } from 'lucide-vue-next'
import { MATRIX_COLUMNS, actionsIn } from './roles'
import type { ActionOption, Grantable, ModuleOption } from './roles'

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

function rowModules(): ModuleOption[] {
    return props.rows ? props.moduleCatalog.filter((m) => props.rows!.includes(m.key)) : props.moduleCatalog
}
</script>

<template>
    <div class="overflow-x-auto rounded-2xl border border-border">
        <table class="w-full min-w-[760px] text-xs">
            <thead class="bg-muted/50 text-[11px] uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th class="sticky left-0 bg-muted/50 px-3 py-2.5 text-left font-semibold">Módulo</th>
                    <th v-for="c in MATRIX_COLUMNS" :key="c.key" class="px-3 py-2.5 text-left font-semibold">{{ c.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in rowModules()" :key="m.key" class="border-t border-border align-top" :class="modules.includes(m.key) ? '' : 'opacity-60'">
                    <th class="sticky left-0 bg-card px-3 py-2.5 text-left text-sm font-semibold text-foreground">{{ m.label }}</th>
                    <td v-for="c in MATRIX_COLUMNS" :key="c.key" class="px-3 py-2.5">
                        <template v-if="c.key === 'ver'">
                            <span v-if="modules.includes(m.key)" class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 font-bold text-emerald-700 dark:text-emerald-300"><Check class="size-3" /> Sí</span>
                            <span v-else class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-semibold text-muted-foreground">No</span>
                        </template>
                        <span v-else-if="actionsIn(actionCatalog, m.key, c.key).length === 0" class="text-muted-foreground" title="Este módulo no tiene esta operación">—</span>
                        <ul v-else class="space-y-1">
                            <li v-for="a in actionsIn(actionCatalog, m.key, c.key)" :key="a.action">
                                <label v-if="editable" class="flex cursor-pointer items-start gap-1.5" :class="!modules.includes(m.key) || !canGrant(m.key, a.action) ? 'cursor-not-allowed opacity-50' : ''" :title="canGrant(m.key, a.action) ? a.description : 'Tu propio rol no tiene esta acción; no puedes otorgarla.'">
                                    <input type="checkbox" class="mt-0.5 accent-violet-600" :checked="has(m.key, a.action)" :disabled="!modules.includes(m.key) || !canGrant(m.key, a.action)" @change="emit('toggle', m.key, a.action)">
                                    <span class="font-semibold text-foreground">{{ a.label }}</span>
                                </label>
                                <span v-else class="inline-flex items-start gap-1" :title="a.description">
                                    <Check v-if="modules.includes(m.key) && has(m.key, a.action)" class="mt-0.5 size-3 shrink-0 text-emerald-600" />
                                    <Minus v-else class="mt-0.5 size-3 shrink-0 text-muted-foreground" />
                                    <span :class="modules.includes(m.key) && has(m.key, a.action) ? 'font-semibold text-foreground' : 'text-muted-foreground line-through decoration-muted-foreground/40'">{{ a.label }}</span>
                                </span>
                            </li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
