<script setup lang="ts">
/**
 * FacetBar — filtros por chips (sin selects): sucursales con conteo, puesto
 * (de People), categoría EBITDA y alertas rápidas. Cada chip se activa/desactiva con un toque y los
 * conteos se recalculan según el resto de filtros activos.
 */
import {
    AlertTriangle,
    BriefcaseBusiness,
    Building2,
    Check,
    Gem,
    RotateCcw,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { categoryMeta } from '@/lib/hardData';
import type { HardDataFlag } from '@/types/hardData';

defineProps<{
    branchFacets: { name: string; count: number }[];
    categoryFacets: { key: string; count: number }[];
    positionFacets: { name: string; count: number }[];
    positions: Set<string>;
    flagFacets: { key: HardDataFlag; label: string; count: number }[];
    branches: Set<string>;
    categories: Set<string>;
    flags: Set<HardDataFlag>;
    activeCount: number;
}>();

const emit = defineEmits<{
    'toggle-branch': [name: string];
    'toggle-category': [key: string];
    'toggle-position': [name: string];
    'toggle-flag': [key: HardDataFlag];
    clear: [];
}>();

const showAllBranches = ref(false);
const BRANCH_PREVIEW = 8;
const showAllPositions = ref(false);
const POSITION_PREVIEW = 6;
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
            <span
                class="flex shrink-0 items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase sm:mt-1.5 sm:w-24"
            >
                <Building2 class="size-3.5" aria-hidden="true" /> Sucursal
            </span>
            <div
                class="-mx-1 flex flex-1 gap-1.5 overflow-x-auto px-1 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 [&::-webkit-scrollbar]:hidden [&>*]:shrink-0"
            >
                <button
                    v-for="b in showAllBranches
                        ? branchFacets
                        : branchFacets.slice(0, BRANCH_PREVIEW)"
                    :key="b.name"
                    type="button"
                    :aria-pressed="branches.has(b.name)"
                    :disabled="b.count === 0 && !branches.has(b.name)"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-40',
                        branches.has(b.name)
                            ? 'border-primary bg-primary text-primary-foreground shadow-soft-xs'
                            : 'border-border bg-card text-foreground hover:border-primary/40 hover:bg-primary/5',
                    ]"
                    @click="emit('toggle-branch', b.name)"
                >
                    <Check
                        v-if="branches.has(b.name)"
                        class="size-3"
                        aria-hidden="true"
                    />
                    {{ b.name }}
                    <span
                        :class="[
                            'tabular-nums',
                            branches.has(b.name)
                                ? 'text-primary-foreground/80'
                                : 'text-muted-foreground',
                        ]"
                        >{{ b.count }}</span
                    >
                </button>
                <button
                    v-if="branchFacets.length > BRANCH_PREVIEW"
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold text-primary transition hover:bg-primary/10"
                    @click="showAllBranches = !showAllBranches"
                >
                    {{
                        showAllBranches
                            ? 'Ver menos'
                            : `+${branchFacets.length - BRANCH_PREVIEW} más`
                    }}
                </button>
            </div>
        </div>

        <div
            v-if="positionFacets.length > 1"
            class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3"
        >
            <span
                class="flex shrink-0 items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase sm:mt-1.5 sm:w-24"
            >
                <BriefcaseBusiness class="size-3.5" aria-hidden="true" /> Puesto
            </span>
            <div
                class="-mx-1 flex flex-1 gap-1.5 overflow-x-auto px-1 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 [&::-webkit-scrollbar]:hidden [&>*]:shrink-0"
            >
                <button
                    v-for="p in showAllPositions
                        ? positionFacets
                        : positionFacets.slice(0, POSITION_PREVIEW)"
                    :key="p.name"
                    type="button"
                    :aria-pressed="positions.has(p.name)"
                    :disabled="p.count === 0 && !positions.has(p.name)"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition-all duration-150 disabled:cursor-not-allowed disabled:opacity-40',
                        positions.has(p.name)
                            ? 'border-info bg-info text-white shadow-soft-xs'
                            : 'border-border bg-card text-foreground hover:border-info/40 hover:bg-info-soft',
                    ]"
                    @click="emit('toggle-position', p.name)"
                >
                    <Check
                        v-if="positions.has(p.name)"
                        class="size-3"
                        aria-hidden="true"
                    />
                    {{ p.name }}
                    <span
                        :class="[
                            'tabular-nums',
                            positions.has(p.name)
                                ? 'text-white/80'
                                : 'text-muted-foreground',
                        ]"
                        >{{ p.count }}</span
                    >
                </button>
                <button
                    v-if="positionFacets.length > POSITION_PREVIEW"
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold text-info transition hover:bg-info-soft"
                    @click="showAllPositions = !showAllPositions"
                >
                    {{
                        showAllPositions
                            ? 'Ver menos'
                            : `+${positionFacets.length - POSITION_PREVIEW} más`
                    }}
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
            <span
                class="flex shrink-0 items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase sm:mt-1.5 sm:w-24"
            >
                <Gem class="size-3.5" aria-hidden="true" /> Categoría
            </span>
            <div
                class="-mx-1 flex flex-1 gap-1.5 overflow-x-auto px-1 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 [&::-webkit-scrollbar]:hidden [&>*]:shrink-0"
            >
                <button
                    v-for="c in categoryFacets"
                    :key="c.key"
                    type="button"
                    :aria-pressed="categories.has(c.key)"
                    :title="categoryMeta(c.key).min"
                    :disabled="c.count === 0 && !categories.has(c.key)"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 transition-all duration-150 ring-inset disabled:cursor-not-allowed disabled:opacity-40',
                        categories.has(c.key)
                            ? `${categoryMeta(c.key).chip} shadow-soft-xs ring-2`
                            : 'bg-card text-foreground ring-border hover:bg-muted/60',
                    ]"
                    @click="emit('toggle-category', c.key)"
                >
                    <span
                        :class="[
                            'size-2 rounded-full',
                            categoryMeta(c.key).dot,
                        ]"
                        aria-hidden="true"
                    />
                    {{ categoryMeta(c.key).label }}
                    <span class="text-muted-foreground tabular-nums">{{
                        c.count
                    }}</span>
                </button>
            </div>
        </div>

        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
            <span
                class="flex shrink-0 items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase sm:mt-1.5 sm:w-24"
            >
                <AlertTriangle class="size-3.5" aria-hidden="true" /> Alertas
            </span>
            <div
                class="-mx-1 flex flex-1 items-center gap-1.5 overflow-x-auto px-1 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0 [&::-webkit-scrollbar]:hidden [&>*]:shrink-0"
            >
                <button
                    v-for="f in flagFacets"
                    :key="f.key"
                    type="button"
                    :aria-pressed="flags.has(f.key)"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition-all duration-150',
                        flags.has(f.key)
                            ? 'border-warning-border bg-warning-soft text-warning'
                            : 'border-dashed border-border bg-card text-muted-foreground hover:border-warning-border hover:text-foreground',
                    ]"
                    @click="emit('toggle-flag', f.key)"
                >
                    {{ f.label }}
                    <span class="tabular-nums opacity-70">{{ f.count }}</span>
                </button>

                <button
                    v-if="activeCount > 0"
                    type="button"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    @click="emit('clear')"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" /> Limpiar
                    filtros ({{ activeCount }})
                </button>
            </div>
        </div>
    </div>
</template>
