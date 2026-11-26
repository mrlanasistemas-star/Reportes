<script setup lang="ts">
/**
 * Pantalla: DATOS DUROS (/datos-duros).
 *
 * Resumen de las cifras finales por colaborador (colocación, recuperación,
 * cartera, mora, nómina, OPEX y EBITDA) por periodo, sin abrir la radiografía
 * completa. Se busca escribiendo (sucursal, ruta, nombre o una letra) y se
 * filtra con chips; todo filtra en el navegador al instante.
 *
 * Origen: HardDataController@index (periodos) + GET /datos-duros/periodos/{id}
 * (filas, mismas fuentes que el Excel de colaboradores) + GET
 * /datos-duros/colaboradores/{id}/historial (evolución en la ficha).
 *
 * Orquestador puro: la lógica vive en useHardDataset / useHardDataFilters y la
 * UI en components/datos-duros/.
 */
import { Head } from '@inertiajs/vue3';
import {
    AlertCircle,
    CalendarX2,
    Clock,
    Database,
    RefreshCw,
    SearchX,
    ShieldCheck,
    UserX,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CollaboratorCard from '@/components/datos-duros/CollaboratorCard.vue';
import CollaboratorSheet from '@/components/datos-duros/CollaboratorSheet.vue';
import CollaboratorTable from '@/components/datos-duros/CollaboratorTable.vue';
import FacetBar from '@/components/datos-duros/FacetBar.vue';
import PeriodRail from '@/components/datos-duros/PeriodRail.vue';
import ResultsToolbar from '@/components/datos-duros/ResultsToolbar.vue';
import SmartSearch from '@/components/datos-duros/SmartSearch.vue';
import TotalsStrip from '@/components/datos-duros/TotalsStrip.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { HardDataSuggestion } from '@/composables/useHardDataFilters';
import { useHardDataFilters } from '@/composables/useHardDataFilters';
import { useHardDataset } from '@/composables/useHardDataset';
import type {
    HardDataFlag,
    HardDataPeriod,
    HardDataRow,
    HardDataSortKey,
} from '@/types/hardData';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Datos duros', href: '/datos-duros' }] },
});

const props = defineProps<{
    periods: HardDataPeriod[];
    initialPeriodId: number | null;
}>();

const { dataset, loading, revalidating, error, load } = useHardDataset();
const rows = computed<HardDataRow[]>(() => dataset.value?.rows ?? []);
const f = useHardDataFilters(rows);

const periodId = ref<number | null>(props.initialPeriodId);
const currentPeriod = computed(
    () => props.periods.find((p) => p.id === periodId.value) ?? null,
);

const VIEW_KEY = 'datos-duros:vista';
const isBrowser = typeof window !== 'undefined';
const view = ref<'cards' | 'table'>(
    (isBrowser && (localStorage.getItem(VIEW_KEY) as 'cards' | 'table')) ||
        'cards',
);
watch(view, (v) => isBrowser && localStorage.setItem(VIEW_KEY, v));

// ── Estado en la URL (compartible y sobrevive a F5) ──────────────────────────
const SORT_KEYS: HardDataSortKey[] = [
    'name',
    'placement',
    'recovery',
    'portfolio',
    'mora_pct',
    'ebitda',
    'payroll',
];
const FLAG_KEYS: HardDataFlag[] = [
    'mora_alta',
    'ebitda_negativo',
    'sin_colocacion',
    'baja',
];

function readUrl(): void {
    if (!isBrowser) {
        return;
    }

    const q = new URLSearchParams(window.location.search);
    const pid = Number(q.get('periodo'));

    if (pid && props.periods.some((p) => p.id === pid)) {
        periodId.value = pid;
    }

    f.query.value = q.get('q') ?? '';
    f.branches.value = new Set((q.get('suc') ?? '').split('|').filter(Boolean));
    f.categories.value = new Set(
        (q.get('cat') ?? '').split('|').filter(Boolean),
    );
    f.flags.value = new Set(
        (q.get('alerta') ?? '')
            .split('|')
            .filter((x): x is HardDataFlag =>
                FLAG_KEYS.includes(x as HardDataFlag),
            ),
    );

    const orden = q.get('orden');

    if (orden) {
        const [key, dir] = orden.split(':');

        if (SORT_KEYS.includes(key as HardDataSortKey)) {
            f.sortKey.value = key as HardDataSortKey;
            f.sortDir.value = dir === 'asc' ? 'asc' : 'desc';
        }
    }
}

let urlTimer: ReturnType<typeof setTimeout> | null = null;

function writeUrl(): void {
    if (urlTimer) {
        clearTimeout(urlTimer);
    }

    urlTimer = setTimeout(() => {
        const q = new URLSearchParams();

        if (periodId.value) {
            q.set('periodo', String(periodId.value));
        }

        if (f.query.value.trim()) {
            q.set('q', f.query.value.trim());
        }

        if (f.branches.value.size) {
            q.set('suc', [...f.branches.value].join('|'));
        }

        if (f.categories.value.size) {
            q.set('cat', [...f.categories.value].join('|'));
        }

        if (f.flags.value.size) {
            q.set('alerta', [...f.flags.value].join('|'));
        }

        if (f.sortKey.value !== 'ebitda' || f.sortDir.value !== 'desc') {
            q.set('orden', `${f.sortKey.value}:${f.sortDir.value}`);
        }

        const qs = q.toString();
        // Inertia guarda su estado en history.state: se conserva al reemplazar la URL.
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${qs ? `?${qs}` : ''}`,
        );
    }, 250);
}

readUrl();
watch(
    [
        periodId,
        f.query,
        f.branches,
        f.categories,
        f.flags,
        f.sortKey,
        f.sortDir,
    ],
    writeUrl,
);

// ── Carga por periodo ─────────────────────────────────────────────────────────
watch(
    periodId,
    (id) => {
        if (id) {
            load(id);
        }
    },
    { immediate: true },
);

// ── Paginación progresiva (auto al hacer scroll) ─────────────────────────────
const PAGE = 48;
const limit = ref(PAGE);
const visible = computed(() => f.filtered.value.slice(0, limit.value));
watch([f.filtered], () => (limit.value = PAGE));

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            if (
                entries.some((e) => e.isIntersecting) &&
                limit.value < f.filtered.value.length
            ) {
                limit.value += PAGE;
            }
        },
        { rootMargin: '600px' },
    );
    watch(
        sentinel,
        (el, old) => {
            if (old) {
                observer?.unobserve(old);
            }

            if (el) {
                observer?.observe(el);
            }
        },
        { immediate: true },
    );
});

onBeforeUnmount(() => {
    observer?.disconnect();

    if (urlTimer) {
        clearTimeout(urlTimer);
    }
});

// ── Ficha ────────────────────────────────────────────────────────────────────
const selected = ref<HardDataRow | null>(null);
const sheetOpen = ref(false);

function openRow(row: HardDataRow): void {
    selected.value = row;
    sheetOpen.value = true;
}

// Al cambiar de periodo con la ficha abierta, se muestra la misma persona en el nuevo periodo.
watch(rows, (list) => {
    if (selected.value) {
        const same = list.find((r) => r.id === selected.value!.id);

        if (same) {
            selected.value = same;
        } else {
            sheetOpen.value = false;
        }
    }
});

function onPick(s: HardDataSuggestion): void {
    if (s.kind === 'person') {
        openRow(s.row);

        return;
    }

    f.query.value = s.kind === 'route' ? s.value : '';

    if (s.kind === 'branch') {
        f.toggleBranch(s.value);
    } else if (s.kind === 'category') {
        f.toggleCategory(s.value);
    }
}

const calculatedAt = computed(() => {
    const iso = dataset.value?.calculated_at;

    if (!iso) {
        return null;
    }

    return new Intl.DateTimeFormat('es-MX', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
});

const firstLoad = computed(() => loading.value && !dataset.value);
const notAvailable = computed(() => dataset.value?.status === 'not_available');
</script>

<template>
    <Head title="Datos duros" />

    <div
        class="mx-auto flex w-full max-w-screen-2xl flex-col gap-5 p-4 pb-24 sm:p-6 lg:pb-10"
    >
        <!-- Encabezado -->
        <header
            class="app-enter relative overflow-hidden rounded-3xl border border-border bg-card p-5 shadow-soft-sm sm:p-7"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-20 size-72 rounded-full bg-primary/15 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-28 left-1/3 size-72 rounded-full bg-primary/10 blur-3xl"
                aria-hidden="true"
            />

            <div
                class="relative flex flex-wrap items-start justify-between gap-4"
            >
                <div class="flex items-start gap-3.5">
                    <span
                        class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-soft-md"
                    >
                        <Database class="size-6" aria-hidden="true" />
                    </span>
                    <div>
                        <h1
                            class="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl"
                        >
                            Datos duros
                        </h1>
                        <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                            Las cifras finales de cada colaborador por periodo —
                            colocación, recuperación, cartera, mora, nómina y
                            EBITDA — sin abrir la radiografía completa.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span
                        v-if="dataset?.scope"
                        class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1.5 font-semibold text-muted-foreground"
                    >
                        <ShieldCheck class="size-3.5" aria-hidden="true" />
                        {{ dataset.scope.label }}
                    </span>
                    <span
                        v-if="calculatedAt"
                        class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1.5 font-semibold text-muted-foreground"
                    >
                        <Clock class="size-3.5" aria-hidden="true" /> Calculado
                        {{ calculatedAt }}
                    </span>
                    <span
                        v-if="revalidating"
                        class="inline-flex items-center gap-1.5 rounded-full bg-info-soft px-3 py-1.5 font-semibold text-info"
                    >
                        <RefreshCw
                            class="size-3.5 animate-spin"
                            aria-hidden="true"
                        />
                        Actualizando…
                    </span>
                </div>
            </div>

            <div v-if="periods.length" class="relative mt-5">
                <PeriodRail
                    v-model="periodId"
                    :periods="periods"
                    :loading="loading || revalidating"
                />
            </div>
        </header>

        <!-- Sin periodos -->
        <section
            v-if="!periods.length"
            class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-border bg-card px-6 py-16 text-center"
        >
            <CalendarX2
                class="size-10 text-muted-foreground"
                aria-hidden="true"
            />
            <h2 class="mt-3 text-lg font-bold text-foreground">
                Aún no hay periodos con radiografía
            </h2>
            <p class="mt-1 max-w-md text-sm text-muted-foreground">
                Los datos duros aparecen en cuanto se genera la radiografía de
                un periodo desde Reportes mensuales.
            </p>
        </section>

        <template v-else>
            <!-- Error -->
            <div
                v-if="error"
                class="flex flex-wrap items-center gap-3 rounded-2xl border border-danger-border bg-danger-soft px-4 py-3 text-sm text-danger"
                role="alert"
            >
                <AlertCircle class="size-4 shrink-0" aria-hidden="true" />
                <span class="flex-1"
                    >{{ error
                    }}<template v-if="dataset">
                        Se muestran los últimos datos cargados.</template
                    ></span
                >
                <button
                    type="button"
                    class="rounded-full bg-danger px-3 py-1 text-xs font-bold text-white transition hover:opacity-90"
                    @click="periodId && load(periodId)"
                >
                    Reintentar
                </button>
            </div>

            <!-- Periodo sin radiografía vigente -->
            <section
                v-if="notAvailable"
                class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-border bg-card px-6 py-14 text-center"
            >
                <CalendarX2
                    class="size-10 text-muted-foreground"
                    aria-hidden="true"
                />
                <h2 class="mt-3 text-lg font-bold text-foreground">
                    Este periodo no tiene radiografía vigente
                </h2>
                <p class="mt-1 max-w-md text-sm text-muted-foreground">
                    {{ dataset?.message }}
                </p>
            </section>

            <template v-else>
                <!-- Buscador + filtros -->
                <section
                    class="app-enter relative z-20 space-y-4 rounded-3xl border border-border bg-card/60 p-4 shadow-soft-xs backdrop-blur sm:p-5"
                    style="--app-delay: 60ms"
                >
                    <SmartSearch
                        v-model="f.query.value"
                        :suggestions="f.suggestions.value"
                        :tokens="f.tokens.value"
                        :result-count="f.filtered.value.length"
                        @pick="onPick"
                    />
                    <FacetBar
                        v-if="!firstLoad"
                        :branch-facets="f.branchFacets.value"
                        :category-facets="f.categoryFacets.value"
                        :flag-facets="f.flagFacets.value"
                        :branches="f.branches.value"
                        :categories="f.categories.value"
                        :flags="f.flags.value"
                        :active-count="f.activeCount.value"
                        @toggle-branch="f.toggleBranch"
                        @toggle-category="f.toggleCategory"
                        @toggle-flag="f.toggleFlag"
                        @clear="f.clearAll"
                    />
                    <div v-else class="flex flex-wrap gap-2">
                        <Skeleton
                            v-for="i in 9"
                            :key="i"
                            class="h-7 w-24 rounded-full"
                        />
                    </div>
                </section>

                <p
                    v-if="!firstLoad && dataset?.hidden"
                    class="-mt-1 flex items-center gap-2 px-1 text-xs text-muted-foreground"
                >
                    <UserX class="size-3.5 shrink-0" aria-hidden="true" />
                    No se muestran {{ dataset.hidden }} colaborador{{
                        dataset.hidden === 1 ? '' : 'es'
                    }}
                    sin ingresos o sin nómina pagada en este periodo (bajas y
                    vacantes); tampoco suman en los totales.
                </p>

                <TotalsStrip :totals="f.totals.value" :loading="firstLoad" />

                <ResultsToolbar
                    v-model:view="view"
                    :count="f.filtered.value.length"
                    :total="rows.length"
                    :sort-key="f.sortKey.value"
                    :sort-dir="f.sortDir.value"
                    @sort="f.setSort"
                />

                <!-- Esqueleto de primera carga -->
                <div
                    v-if="firstLoad"
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                >
                    <div
                        v-for="i in 8"
                        :key="i"
                        class="rounded-2xl border border-border bg-card p-4"
                    >
                        <div class="flex items-center gap-3">
                            <Skeleton class="size-11 rounded-2xl" />
                            <div class="flex-1 space-y-2">
                                <Skeleton class="h-4 w-3/4" /><Skeleton
                                    class="h-3 w-1/2"
                                />
                            </div>
                        </div>
                        <Skeleton class="mt-4 h-14 w-full rounded-xl" />
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <Skeleton v-for="j in 4" :key="j" class="h-8" />
                        </div>
                    </div>
                </div>

                <!-- Sin resultados -->
                <section
                    v-else-if="!f.filtered.value.length"
                    class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-border bg-card px-6 py-14 text-center"
                >
                    <SearchX
                        class="size-10 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h2 class="mt-3 text-lg font-bold text-foreground">
                        Nadie coincide con esa búsqueda
                    </h2>
                    <p class="mt-1 max-w-md text-sm text-muted-foreground">
                        Prueba con menos letras o quita algún filtro.
                    </p>
                    <button
                        v-if="f.activeCount.value"
                        type="button"
                        class="mt-4 rounded-full bg-primary px-4 py-2 text-sm font-bold text-primary-foreground shadow-soft-sm transition hover:opacity-90"
                        @click="f.clearAll"
                    >
                        Limpiar filtros
                    </button>
                </section>

                <template v-else>
                    <TransitionGroup
                        v-if="view === 'cards'"
                        tag="div"
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-1"
                        move-class="transition duration-200"
                    >
                        <CollaboratorCard
                            v-for="(row, i) in visible"
                            :key="row.id"
                            :rank="i + 1"
                            :row="row"
                            :tokens="f.tokens.value"
                            @open="openRow"
                        />
                    </TransitionGroup>
                    <CollaboratorTable
                        v-else
                        :rows="visible"
                        :tokens="f.tokens.value"
                        :sort-key="f.sortKey.value"
                        :sort-dir="f.sortDir.value"
                        @open="openRow"
                        @sort="f.setSort"
                    />
                    <div ref="sentinel" class="h-1" aria-hidden="true" />
                    <p
                        v-if="limit < f.filtered.value.length"
                        class="text-center text-xs text-muted-foreground"
                    >
                        Cargando más colaboradores…
                    </p>
                </template>
            </template>
        </template>
    </div>

    <CollaboratorSheet
        v-model:open="sheetOpen"
        :row="selected"
        :period="currentPeriod"
    />
</template>
