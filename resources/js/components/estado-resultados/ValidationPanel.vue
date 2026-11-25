<script setup lang="ts">
/**
 * ValidationPanel — pestaña «Validación»: comprueba que cada peso del banco y
 * del ERP termine en exactamente un destino.
 *
 * Se usa en pages/EstadoResultados/Index. Pantalla compacta (casi sin desplazamiento):
 * arriba un resumen visual (veredicto, cifras grandes y barra de destinos del dinero);
 * debajo, una tarjeta corta por bloque (Control de movimientos, Sin resolver, Diferencias
 * banco ↔ ERP, Del archivo a cada destino, Control por archivo, Integridad, EBITDA =
 * Radiografía, Movimientos internos y Nómina NOI). Cada tarjeta abre su detalle en un
 * modal centrado (ErModal) con desplazamiento interno, filtros y búsqueda.
 *
 * Props:
 * - validation: bloque `validation` del dataset.
 * - movements: movimientos del dataset (ya redactados) para la lista de control.
 * - canClassify / canExport: permisos para clasificar y exportar desde la lista.
 * - lockReason: motivo por el que no se puede clasificar.
 * - url: arma `/estado-resultados/{periodo}` + path; sin ella no se muestra la lista de control.
 * - scopeQuery: consulta de alcance/versión que se agrega a las peticiones.
 * - groups: vista «Agrupados» de pendientes (catálogo, permisos y procesar).
 *
 * Emits:
 * - open(fingerprint): abrir el detalle de un movimiento.
 * - classify(fingerprints): clasificar uno o varios movimientos.
 * - reload / open-catalog: se reenvían desde la vista agrupada.
 */
import {
    ArrowRight,
    CheckCircle2,
    CircleAlert,
    FileCheck2,
    FileSpreadsheet,
    GitCompareArrows,
    Info,
    ListChecks,
    Repeat2,
    Route,
    Scale,
    ShieldAlert,
    Users,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useMovementControl } from '@/composables/estado-resultados/useMovementControl';
import type { RuleDraft } from './conceptCatalog';
import ErModal from './ErModal.vue';
import { TONE_CHIP, TONE_ICON } from './erTone';
import type { ErTone } from './erTone';
import FileControlTable from './FileControlTable.vue';
import type {
    ControlFileRef,
    ControlFilter,
    ControlRef,
    ControlValidation,
    ControlledMovement,
} from './movementControl';
import MovementControlList from './MovementControlList.vue';
import { isZero, mxn, num } from './types';
import type {
    Movement,
    PartitionCheck,
    TreatmentMeta,
    Validation,
} from './types';
import UnresolvedList from './UnresolvedList.vue';
import ValidationDifferences from './ValidationDifferences.vue';
import ValidationTile from './ValidationTile.vue';

const props = withDefaults(
    defineProps<{
        validation: Validation;
        /** Movimientos del dataset (ya redactados): la lista de control filtra sobre ellos. */
        movements?: Movement[];
        canClassify?: boolean;
        canExport?: boolean;
        lockReason?: string | null;
        /** `/estado-resultados/{periodo}` + path, y la consulta de alcance/versión. */
        url?: (path: string) => string;
        scopeQuery?: () => string;
        /** Vista «Agrupados» de pendientes: catálogo de tratamientos, permisos y procesar. */
        groups?: {
            treatments: TreatmentMeta[];
            canCatalog: boolean;
            periodId: number;
            canProcess: boolean;
            process: () => Promise<void>;
        } | null;
    }>(),
    {
        movements: () => [],
        canClassify: false,
        canExport: false,
        lockReason: null,
        url: undefined,
        scopeQuery: () => '',
        groups: null,
    },
);
const emit = defineEmits<{
    open: [fingerprint: string];
    classify: [fingerprints: string[]];
    reload: [];
    'open-catalog': [draft: Partial<RuleDraft>];
}>();

/** Modal abierto (uno a la vez; el detalle de movimiento se abre encima). */
type ModalKey =
    | 'control'
    | 'unresolved'
    | 'differences'
    | 'funnel'
    | 'files'
    | 'integrity'
    | 'radiography'
    | 'eliminations'
    | 'noi';
const active = ref<ModalKey | null>(null);

/**
 * Cierra el modal cuando el usuario lo descarta (Esc, clic fuera o X).
 * @param open nuevo estado del modal.
 */
function onModal(open: boolean): void {
    if (!open) {
        active.value = null;
    }
}

/** Datos de la vista «Agrupados» (null si el padre no la habilita). */
const groupsCtx = computed(() =>
    props.groups && props.url
        ? {
              ...props.groups,
              movements: props.movements as ControlledMovement[],
              url: props.url,
              scopeQuery: props.scopeQuery,
          }
        : null,
);

/** La validación vista con el tipo extendido de control total. */
const v = computed(() => props.validation as unknown as ControlValidation);

// ── Control total: lista de movimientos por estado / partida / archivo ──
const control = useMovementControl({
    movements: () => props.movements as ControlledMovement[],
    url: (path) => (props.url ? props.url(path) : path),
    scopeQuery: () => props.scopeQuery(),
});

/**
 * Enfoca la lista de control en unos movimientos/archivos o un filtro y abre su modal.
 * @param opts título, nota, referencias, filtro y archivo (uploadId) a mostrar.
 */
function showList(opts: {
    title: string;
    note?: string | null;
    items?: ControlRef[];
    files?: ControlFileRef[];
    filter?: ControlFilter;
    uploadId?: number | null;
}): void {
    control.focusOn(opts);

    // Sin nada que mostrar, focusOn sólo informa (SweetAlert): no se abre la lista.
    if (
        props.url !== undefined &&
        (control.focus.value || opts.filter || opts.uploadId)
    ) {
        active.value = 'control';
    }
}

/**
 * Abre la lista completa de control: sin enfoque, sin archivo y en «Todos» (antes conservaba el
 * último filtro, p. ej. «Pendientes», y la lista completa parecía tener sólo pendientes).
 */
function openControl(): void {
    control.clearFocus();
    active.value = 'control';
}

/** Control por archivo (null si el backend no lo manda). */
const fileControl = computed(() => v.value.file_control ?? null);

/** Título, tono e icono del veredicto por estado de la validación. */
const STATUS = {
    ok: {
        title: 'Todo cuadra y no hay diferencias sin resolver',
        chip: 'Cuadra',
        tone: 'emerald' as ErTone,
        icon: CheckCircle2,
    },
    con_pendientes: {
        title: 'Las sumas cuadran, pero quedan puntos por resolver',
        chip: 'Con pendientes',
        tone: 'amber' as ErTone,
        icon: CircleAlert,
    },
    con_diferencias: {
        title: 'Hay diferencias: algo no cuadra',
        chip: 'Con diferencias',
        tone: 'rose' as ErTone,
        icon: XCircle,
    },
} as const;

/** Veredicto actual; un estado desconocido se trata como «con diferencias». */
const status = computed(
    () =>
        STATUS[v.value.status as keyof typeof STATUS] ?? STATUS.con_diferencias,
);

/** Destinos de cada peso (todos los movimientos: banco + ERP fuera de Radiografía). */
const parts = computed(() => {
    const t = v.value.totals;
    const list = [
        {
            key: 'conciliated',
            label: 'Conciliado con ERP',
            ...t.conciliated,
            cls: 'bg-cyan-500',
        },
        {
            key: 'recognized',
            label: 'Reconocido en el ER',
            ...t.recognized,
            cls: 'bg-emerald-600 dark:bg-emerald-500',
        },
        {
            key: 'excluded',
            label: 'Excluido con justificación',
            ...t.excluded_justified,
            cls: 'bg-slate-500 dark:bg-slate-400',
        },
        {
            key: 'pending',
            label: 'Pendiente',
            ...t.pending,
            cls: 'bg-[repeating-linear-gradient(135deg,#f59e0b_0_6px,#fbbf24_6px_12px)]',
        },
        {
            key: 'out',
            label: 'Fuera del periodo',
            ...t.out_of_period,
            cls: 'bg-violet-500',
        },
    ];
    const total = list.reduce((s, p) => s + Math.abs(num(p.amount)), 0) || 1;

    return list.map((p) => ({
        ...p,
        width: (Math.abs(num(p.amount)) / total) * 100,
    }));
});

/** Las tres particiones que deben cuadrar en $0.00: banco, ERP fuera de Radiografía y total. */
const partitions = computed<Array<{ label: string; p: PartitionCheck }>>(() => [
    { label: 'Estados de cuenta', p: v.value.partition.bank },
    { label: 'ERP fuera de Radiografía', p: v.value.partition.erp },
    { label: 'Todo el periodo', p: v.value.partition.all },
]);
/** Particiones que cuadran. */
const partitionsOk = computed(
    () => partitions.value.filter((x) => x.p.ok).length,
);

/** Lo importado coincide con lo leído del archivo (true si el backend no lo informa). */
const importOk = computed(
    () => v.value.integrity.import_matches_source ?? true,
);

/** Partidas sin resolver que bloquean la aprobación. */
const blocking = computed(
    () => v.value.unresolved.filter((u) => u.blocking).length,
);
/** Diferencias banco ↔ ERP por tipo y en total. */
const diffCount = computed(
    () =>
        v.value.differences.by_amount.count +
        v.value.differences.by_date.count +
        v.value.differences.by_folio.count,
);
/** Archivos por resultado del control del banco. */
const integrityCounts = computed(() => {
    const u = v.value.integrity.uploads;

    return {
        ok: u.filter((x) => x.control_ok === true).length,
        bad: u.filter((x) => x.control_ok === false).length,
        none: u.filter((x) => x.control_ok === null).length,
    };
});
/** Conteos de la lista de control (los mismos de los chips del modal). */
/** Conteos de la tarjeta: siempre de TODO el periodo (no dependen del último enfoque abierto). */
const counts = computed(() => control.allCounts.value);
</script>

<template>
    <div class="space-y-5 text-[15px]">
        <!-- ═══ Resumen visual ═══ -->
        <section
            class="relative overflow-hidden rounded-3xl border bg-card p-5 shadow-soft-sm sm:p-6"
            aria-label="Resumen de la validación"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-24 size-64 rounded-full opacity-40 blur-3xl"
                :class="
                    status.tone === 'emerald'
                        ? 'bg-emerald-200 dark:bg-emerald-900/40'
                        : status.tone === 'amber'
                          ? 'bg-amber-200 dark:bg-amber-900/40'
                          : 'bg-rose-200 dark:bg-rose-900/40'
                "
                aria-hidden="true"
            />

            <!-- Veredicto -->
            <div
                class="relative flex flex-wrap items-center gap-4"
                role="status"
            >
                <span
                    class="grid size-12 shrink-0 place-items-center rounded-2xl ring-1"
                    :class="TONE_ICON[status.tone]"
                >
                    <component :is="status.icon" class="size-6" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-lg leading-tight font-extrabold sm:text-xl">
                        {{ status.title }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cada peso del banco y del ERP debe terminar en un solo
                        destino.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold"
                        :class="TONE_CHIP[status.tone]"
                    >
                        <component :is="status.icon" class="size-4" />
                        {{ status.chip }}
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold"
                        :class="
                            partitionsOk === partitions.length
                                ? TONE_CHIP.emerald
                                : TONE_CHIP.rose
                        "
                        :title="'Particiones que cuadran en $0.00'"
                    >
                        <Scale class="size-4" />
                        {{ partitionsOk }}/{{ partitions.length }} en $0.00
                    </span>
                    <span
                        v-if="v.scope_note"
                        class="inline-flex max-w-xs items-center gap-1.5 rounded-full bg-sky-100 px-3 py-1 text-sm font-semibold text-sky-800 dark:bg-sky-950/60 dark:text-sky-300"
                        :title="v.scope_note"
                    >
                        <Info class="size-4 shrink-0" />
                        <span class="truncate">{{ v.scope_note }}</span>
                    </span>
                </div>
            </div>

            <!-- Cifras grandes -->
            <dl class="relative mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-2xl bg-muted/50 p-4">
                    <dt
                        class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <FileSpreadsheet class="size-4" /> Archivos del banco
                    </dt>
                    <dd
                        class="mt-1 text-xl font-extrabold tabular-nums sm:text-2xl"
                    >
                        {{ mxn(v.totals.source.total) }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{ v.totals.source.count.toLocaleString('es-MX') }}
                        movimientos
                    </dd>
                </div>
                <div
                    class="rounded-2xl p-4"
                    :class="
                        importOk
                            ? 'bg-muted/50'
                            : 'bg-rose-50 ring-1 ring-rose-300 dark:bg-rose-950/30 dark:ring-rose-800'
                    "
                >
                    <dt
                        class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <CheckCircle2
                            v-if="importOk"
                            class="size-4 text-emerald-600"
                        />
                        <XCircle v-else class="size-4 text-rose-600" />
                        Importado
                    </dt>
                    <dd
                        class="mt-1 text-xl font-extrabold tabular-nums sm:text-2xl"
                    >
                        {{ mxn(v.totals.imported.total) }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{ v.totals.imported.count.toLocaleString('es-MX') }}
                        movimientos
                        <template v-if="v.totals.erp_outside.count">
                            + {{ v.totals.erp_outside.count }} ERP</template
                        >
                    </dd>
                </div>
                <div
                    class="rounded-2xl p-4"
                    :class="
                        num(v.totals.pending.amount)
                            ? 'bg-amber-50 dark:bg-amber-950/25'
                            : 'bg-muted/50'
                    "
                >
                    <dt
                        class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <CircleAlert class="size-4 text-amber-600" /> Pendiente
                        de clasificar
                    </dt>
                    <dd
                        class="mt-1 text-xl font-extrabold tabular-nums sm:text-2xl"
                        :class="
                            num(v.totals.pending.amount)
                                ? 'text-amber-700 dark:text-amber-400'
                                : ''
                        "
                    >
                        {{ mxn(v.totals.pending.amount) }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{ v.totals.pending.count.toLocaleString('es-MX') }}
                        movimientos
                    </dd>
                </div>
                <div
                    class="rounded-2xl p-4"
                    :class="
                        v.unresolved.length
                            ? 'bg-rose-50 dark:bg-rose-950/25'
                            : 'bg-emerald-50 dark:bg-emerald-950/25'
                    "
                >
                    <dt
                        class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    >
                        <ShieldAlert
                            class="size-4"
                            :class="
                                v.unresolved.length
                                    ? 'text-rose-600'
                                    : 'text-emerald-600'
                            "
                        />
                        Sin resolver
                    </dt>
                    <dd
                        class="mt-1 text-xl font-extrabold tabular-nums sm:text-2xl"
                        :class="
                            v.unresolved.length
                                ? 'text-rose-700 dark:text-rose-400'
                                : 'text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        {{ v.unresolved.length }}
                        {{ v.unresolved.length === 1 ? 'partida' : 'partidas' }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{
                            blocking
                                ? `${blocking} bloquean la aprobación`
                                : 'Ninguna bloquea la aprobación'
                        }}
                    </dd>
                </div>
            </dl>

            <!-- Barra de destinos del dinero -->
            <div class="relative mt-5">
                <p class="mb-2 text-sm font-bold">¿A dónde fue cada peso?</p>
                <div
                    class="flex h-4 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="
                        parts
                            .filter((p) => p.width > 0)
                            .map((p) => `${p.label} ${mxn(p.amount)}`)
                            .join(', ')
                    "
                >
                    <span
                        v-for="p in parts.filter((x) => x.width > 0)"
                        :key="p.key"
                        class="h-full transition-[width] duration-700 ease-out first:rounded-l-full last:rounded-r-full"
                        :class="p.cls"
                        :style="{ width: `${p.width}%` }"
                        :title="`${p.label}: ${mxn(p.amount)}`"
                    />
                </div>
                <ul class="mt-3 flex flex-wrap gap-2">
                    <li
                        v-for="p in parts"
                        :key="p.key"
                        class="inline-flex items-center gap-2 rounded-full border bg-background px-3 py-1 text-sm"
                        :class="p.width > 0 ? '' : 'opacity-50'"
                    >
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :class="p.cls"
                        />
                        <span>{{ p.label }}</span>
                        <b
                            class="tabular-nums"
                            :class="
                                p.key === 'pending' && num(p.amount)
                                    ? 'text-amber-700 dark:text-amber-400'
                                    : ''
                            "
                            >{{ mxn(p.amount) }}</b
                        >
                        <span class="text-xs text-muted-foreground tabular-nums"
                            >{{ p.count }} mov.</span
                        >
                    </li>
                </ul>
            </div>
        </section>

        <!-- ═══ Tarjetas por bloque ═══ -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <ValidationTile
                v-if="url !== undefined"
                title="Control de movimientos"
                :icon="ListChecks"
                :tone="counts.pendiente.count ? 'amber' : 'emerald'"
                :status="
                    counts.pendiente.count
                        ? `${counts.pendiente.count.toLocaleString('es-MX')} pendientes`
                        : 'Todo clasificado'
                "
                :value="`${counts.todos.count.toLocaleString('es-MX')} movimientos`"
                :caption="`${mxn(counts.todos.amount)} brutos, agrupados por motivo y concepto`"
                :stats="[
                    {
                        label: 'Completados',
                        value: counts.completado.count.toLocaleString('es-MX'),
                        tone: 'emerald',
                    },
                    {
                        label: 'Repetidos',
                        value: counts.duplicado.count.toLocaleString('es-MX'),
                    },
                    {
                        label: 'Revisar concepto',
                        value: counts.revisar.count.toLocaleString('es-MX'),
                        tone: counts.revisar.count ? 'amber' : undefined,
                    },
                ]"
                action="Abrir la lista"
                @open="openControl"
            />

            <ValidationTile
                title="Sin resolver"
                :icon="ShieldAlert"
                :tone="
                    !v.unresolved.length
                        ? 'emerald'
                        : blocking
                          ? 'rose'
                          : 'amber'
                "
                :status="
                    !v.unresolved.length
                        ? 'Nada pendiente'
                        : blocking
                          ? 'Bloquea la aprobación'
                          : 'Informativo'
                "
                :value="`${v.unresolved.length} ${v.unresolved.length === 1 ? 'partida' : 'partidas'}`"
                :value-tone="v.unresolved.length ? 'rose' : 'emerald'"
                :caption="
                    v.unresolved.length
                        ? 'Cada partida abre los movimientos que la explican.'
                        : 'No hay diferencias sin resolver.'
                "
                :stats="
                    v.unresolved.length
                        ? [
                              {
                                  label: 'Bloquean',
                                  value: String(blocking),
                                  tone: blocking ? 'rose' : undefined,
                              },
                              {
                                  label: 'Informativas',
                                  value: String(v.unresolved.length - blocking),
                              },
                          ]
                        : []
                "
                @open="active = 'unresolved'"
            />

            <ValidationTile
                title="Diferencias banco ↔ ERP"
                :icon="GitCompareArrows"
                :tone="diffCount ? 'rose' : 'emerald'"
                :status="diffCount ? 'Por revisar' : 'Sin diferencias'"
                :value="`${diffCount.toLocaleString('es-MX')} ${diffCount === 1 ? 'movimiento' : 'movimientos'}`"
                :value-tone="diffCount ? 'rose' : 'emerald'"
                caption="Movimientos del banco que no conciliaron limpio."
                :stats="[
                    {
                        label: 'Por importe',
                        value: String(v.differences.by_amount.count),
                        tone: v.differences.by_amount.count
                            ? 'rose'
                            : undefined,
                    },
                    {
                        label: 'Por fecha',
                        value: String(v.differences.by_date.count),
                        tone: v.differences.by_date.count ? 'rose' : undefined,
                    },
                    {
                        label: 'Por folio',
                        value: String(v.differences.by_folio.count),
                        tone: v.differences.by_folio.count ? 'rose' : undefined,
                    },
                ]"
                @open="active = 'differences'"
            />

            <ValidationTile
                title="Del archivo a cada destino"
                :icon="Route"
                :tone="
                    partitionsOk === partitions.length && importOk
                        ? 'emerald'
                        : 'rose'
                "
                :status="
                    partitionsOk === partitions.length
                        ? 'Cuadra en $0.00'
                        : 'Hay diferencia'
                "
                :value="mxn(v.totals.imported.total)"
                :caption="
                    importOk
                        ? 'Lo importado coincide con el archivo.'
                        : 'Lo importado no coincide con el archivo.'
                "
                :stats="
                    partitions.map((x) => ({
                        label: x.label,
                        value: mxn(x.p.difference),
                        tone: x.p.ok ? 'emerald' : 'rose',
                    }))
                "
                @open="active = 'funnel'"
            />

            <ValidationTile
                v-if="fileControl"
                title="Qué pasó con cada archivo"
                :icon="FileSpreadsheet"
                :tone="fileControl.totals.not_saved.count ? 'rose' : 'emerald'"
                :status="
                    fileControl.totals.not_saved.count
                        ? `${fileControl.totals.not_saved.count} filas no guardadas`
                        : 'Cada fila quedó guardada'
                "
                :value="`${fileControl.totals.files} ${fileControl.totals.files === 1 ? 'archivo' : 'archivos'}`"
                :caption="`Aportan a este periodo ${mxn(fileControl.totals.period_share.total)}`"
                :stats="[
                    {
                        label: 'Subidos aquí',
                        value: String(fileControl.totals.own_files),
                    },
                    {
                        label: 'En otro periodo',
                        value: String(
                            fileControl.totals.files -
                                fileControl.totals.own_files,
                        ),
                    },
                ]"
                @open="active = 'files'"
            />

            <ValidationTile
                title="Integridad de los archivos"
                :icon="FileCheck2"
                :tone="v.integrity.status === 'ok' ? 'emerald' : 'rose'"
                :status="
                    v.integrity.status === 'ok' ? 'Íntegros' : 'Con incidencias'
                "
                :value="`${v.integrity.uploads.length} ${v.integrity.uploads.length === 1 ? 'archivo' : 'archivos'}`"
                :caption="
                    v.integrity.erp_stale
                        ? 'El ERP cambió: vuelve a procesar.'
                        : 'Control del banco contra lo leído.'
                "
                :stats="[
                    {
                        label: 'Cuadran',
                        value: String(integrityCounts.ok),
                        tone: 'emerald',
                    },
                    {
                        label: 'No cuadran',
                        value: String(integrityCounts.bad),
                        tone: integrityCounts.bad ? 'rose' : undefined,
                    },
                    {
                        label: 'Sin control',
                        value: String(integrityCounts.none),
                    },
                ]"
                @open="active = 'integrity'"
            />

            <ValidationTile
                title="EBITDA de sucursales = Radiografía"
                :icon="Scale"
                :tone="v.radiography.ok ? 'emerald' : 'rose'"
                :status="v.radiography.ok ? 'Cuadra' : 'Diferencia'"
                :value="mxn(v.radiography.difference)"
                :value-tone="
                    isZero(v.radiography.difference) ? 'emerald' : 'rose'
                "
                caption="Diferencia entre el ER y la Radiografía."
                :stats="[
                    {
                        label: 'Estado de Resultados',
                        value: mxn(v.radiography.er),
                    },
                    {
                        label: 'Radiografía',
                        value: mxn(v.radiography.radiografia),
                    },
                ]"
                @open="active = 'radiography'"
            />

            <ValidationTile
                title="Movimientos internos"
                :icon="Repeat2"
                :tone="
                    v.eliminations.unpaired_transfers.count ? 'rose' : 'cyan'
                "
                :status="
                    v.eliminations.unpaired_transfers.count
                        ? `${v.eliminations.unpaired_transfers.count} sin contraparte`
                        : 'Todos emparejados'
                "
                :value="mxn(v.eliminations.transfers.amount)"
                :caption="`${v.eliminations.transfers.pairs} pares de traspasos eliminados`"
                :stats="[
                    {
                        label: 'Flujos internos',
                        value: mxn(v.eliminations.internal_flows.amount),
                    },
                    {
                        label: 'Reversos',
                        value: mxn(v.eliminations.reversals.amount),
                    },
                ]"
                @open="active = 'eliminations'"
            />

            <ValidationTile
                title="Nómina NOI"
                :icon="Users"
                :tone="num(v.noi.unassigned_total) ? 'rose' : 'violet'"
                :status="
                    num(v.noi.unassigned_total)
                        ? `${v.noi.unassigned_employees} sin sucursal`
                        : 'Todos con sucursal'
                "
                :value="mxn(v.noi.total)"
                :caption="`${v.noi.employees} colaboradores`"
                :stats="[
                    {
                        label: 'Sin sucursal',
                        value: mxn(v.noi.unassigned_total),
                        tone: num(v.noi.unassigned_total) ? 'rose' : undefined,
                    },
                    {
                        label: 'Pagos vinculados',
                        value: String(v.noi.linked_payments.count),
                    },
                ]"
                @open="active = 'noi'"
            />
        </div>

        <!-- ═══ Modales de detalle ═══ -->

        <!-- Control de movimientos -->
        <ErModal
            v-if="url !== undefined"
            :open="active === 'control'"
            title="Control de movimientos"
            description="Agrupados por motivo y concepto. Abre cualquiera para ver su detalle y clasificarlo."
            :icon="ListChecks"
            tone="emerald"
            size="2xl"
            :scroll="false"
            @update:open="onModal"
        >
            <template #badge>
                <span
                    class="rounded-full bg-muted px-3 py-1 text-sm font-bold tabular-nums"
                    >{{ counts.todos.count.toLocaleString('es-MX') }} ·
                    {{ mxn(counts.todos.amount) }}</span
                >
            </template>
            <MovementControlList
                :control="control"
                :can-classify="canClassify"
                :can-export="canExport"
                :lock-reason="lockReason"
                :not-saved-count="fileControl?.totals.not_saved.count ?? 0"
                :groups="groupsCtx"
                @open="(fp) => emit('open', fp)"
                @classify="(fps) => emit('classify', fps)"
                @reload="emit('reload')"
                @open-catalog="(d) => emit('open-catalog', d)"
            />
        </ErModal>

        <!-- Sin resolver -->
        <ErModal
            :open="active === 'unresolved'"
            title="Sin resolver"
            description="Las diferencias sin resolver nunca se ocultan. Cada partida abre los movimientos que la explican."
            :icon="ShieldAlert"
            :tone="blocking ? 'rose' : 'amber'"
            size="lg"
            @update:open="onModal"
        >
            <template #badge>
                <span
                    class="rounded-full px-3 py-1 text-sm font-bold"
                    :class="blocking ? TONE_CHIP.rose : TONE_CHIP.slate"
                    >{{ blocking }} bloquean</span
                >
            </template>
            <UnresolvedList
                :unresolved="v.unresolved"
                @show="(p) => showList(p)"
            />
        </ErModal>

        <!-- Diferencias banco ↔ ERP -->
        <ErModal
            :open="active === 'differences'"
            title="Diferencias banco ↔ ERP"
            description="Cada renglón es un movimiento del banco que no concilió limpio. Ábrelo para decidir qué hacer."
            :icon="GitCompareArrows"
            :tone="diffCount ? 'rose' : 'emerald'"
            size="2xl"
            :scroll="false"
            @update:open="onModal"
        >
            <template #badge>
                <span
                    class="rounded-full px-3 py-1 text-sm font-bold tabular-nums"
                    :class="diffCount ? TONE_CHIP.rose : TONE_CHIP.emerald"
                    >{{ diffCount }} en total</span
                >
            </template>
            <ValidationDifferences
                :differences="v.differences"
                @open="(fp) => emit('open', fp)"
            />
        </ErModal>

        <!-- Del archivo a cada destino -->
        <ErModal
            :open="active === 'funnel'"
            title="Del archivo a cada destino"
            description="Lo leído del archivo, lo importado y a dónde fue cada peso. Las tres particiones deben cuadrar en $0.00."
            :icon="Route"
            :tone="partitionsOk === partitions.length ? 'emerald' : 'rose'"
            size="xl"
            @update:open="onModal"
        >
            <template #badge>
                <span
                    class="rounded-full px-3 py-1 text-sm font-bold"
                    :class="
                        partitionsOk === partitions.length
                            ? TONE_CHIP.emerald
                            : TONE_CHIP.rose
                    "
                    >{{ partitionsOk }}/{{ partitions.length }} en $0.00</span
                >
            </template>
            <div class="space-y-5">
                <div
                    class="grid items-stretch gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]"
                >
                    <div class="rounded-2xl bg-muted/50 p-4">
                        <p class="text-sm text-muted-foreground">
                            Archivos del banco
                        </p>
                        <p class="text-2xl font-extrabold tabular-nums">
                            {{ mxn(v.totals.source.total) }}
                        </p>
                        <p
                            class="mt-1 text-sm text-muted-foreground tabular-nums"
                        >
                            {{ v.totals.source.count.toLocaleString('es-MX') }}
                            movimientos
                        </p>
                        <p
                            class="mt-2 flex flex-wrap gap-2 text-sm tabular-nums"
                        >
                            <span
                                class="rounded-full bg-rose-100 px-2.5 py-0.5 font-semibold text-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                                >Cargos {{ mxn(v.totals.source.cargos) }}</span
                            >
                            <span
                                class="rounded-full bg-emerald-100 px-2.5 py-0.5 font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                                >Abonos {{ mxn(v.totals.source.abonos) }}</span
                            >
                        </p>
                        <button
                            v-if="fileControl && fileControl.totals.files"
                            type="button"
                            class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
                            @click="active = 'files'"
                        >
                            Lo que aporta cada uno de los
                            {{ fileControl.totals.files }} archivo(s)
                            <ArrowRight class="size-4" />
                        </button>
                    </div>
                    <span
                        class="hidden items-center md:flex"
                        aria-hidden="true"
                    >
                        <ArrowRight class="size-6 text-muted-foreground" />
                    </span>
                    <div
                        class="rounded-2xl p-4"
                        :class="
                            importOk
                                ? 'bg-muted/50'
                                : 'bg-rose-50 ring-1 ring-rose-300 dark:bg-rose-950/30 dark:ring-rose-800'
                        "
                    >
                        <p
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            Importado
                            <CheckCircle2
                                v-if="importOk"
                                class="size-4 text-emerald-600"
                            />
                            <XCircle v-else class="size-4 text-rose-600" />
                        </p>
                        <p class="text-2xl font-extrabold tabular-nums">
                            {{ mxn(v.totals.imported.total) }}
                        </p>
                        <p
                            class="mt-1 text-sm text-muted-foreground tabular-nums"
                        >
                            {{
                                v.totals.imported.count.toLocaleString('es-MX')
                            }}
                            movimientos
                            <template v-if="v.totals.erp_outside.count">
                                + ERP fuera de Radiografía
                                {{ mxn(v.totals.erp_outside.total) }}
                                ({{ v.totals.erp_outside.count }})</template
                            >
                        </p>
                        <p
                            v-if="!importOk"
                            class="mt-2 text-sm font-bold text-rose-700 dark:text-rose-300"
                        >
                            Lo importado no coincide con el archivo.
                            <button
                                type="button"
                                class="ml-1 underline underline-offset-2"
                                @click="
                                    showList({
                                        title: 'Filas leídas del archivo y no guardadas',
                                        filter: 'no_guardado',
                                    })
                                "
                            >
                                Ver filas no guardadas
                            </button>
                        </p>
                    </div>
                </div>

                <!-- Destinos -->
                <div class="rounded-2xl border p-4">
                    <p class="mb-3 text-sm font-bold">Destinos del dinero</p>
                    <div
                        class="flex h-5 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
                        aria-hidden="true"
                    >
                        <span
                            v-for="p in parts.filter((x) => x.width > 0)"
                            :key="p.key"
                            class="h-full transition-[width] duration-700"
                            :class="p.cls"
                            :style="{ width: `${p.width}%` }"
                            :title="`${p.label}: ${mxn(p.amount)}`"
                        />
                    </div>
                    <ul class="mt-4 divide-y">
                        <li
                            v-for="p in parts"
                            :key="p.key"
                            class="flex items-center gap-3 py-2"
                            :class="p.width > 0 ? '' : 'opacity-50'"
                        >
                            <span
                                class="size-3 shrink-0 rounded-full"
                                :class="p.cls"
                            />
                            <span class="min-w-0 flex-1">{{ p.label }}</span>
                            <span
                                class="text-sm text-muted-foreground tabular-nums"
                                >{{ p.count }} mov.</span
                            >
                            <span
                                class="w-36 text-right font-bold tabular-nums"
                                :class="
                                    p.key === 'pending' && num(p.amount)
                                        ? 'text-amber-700 dark:text-amber-400'
                                        : ''
                                "
                                >{{ mxn(p.amount) }}</span
                            >
                        </li>
                    </ul>
                </div>

                <!-- Partición en $0.00 -->
                <div class="grid gap-3 md:grid-cols-3">
                    <div
                        v-for="x in partitions"
                        :key="x.label"
                        class="rounded-2xl border p-4"
                        :class="
                            x.p.ok
                                ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20'
                                : 'border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/30'
                        "
                    >
                        <p class="flex items-center gap-2 text-sm font-bold">
                            <CheckCircle2
                                v-if="x.p.ok"
                                class="size-4 text-emerald-600"
                            />
                            <XCircle v-else class="size-4 text-rose-600" />
                            {{ x.label }}
                        </p>
                        <p
                            class="mt-2 text-xl font-extrabold tabular-nums"
                            :class="
                                x.p.ok
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-rose-700 dark:text-rose-400'
                            "
                        >
                            {{ mxn(x.p.difference) }}
                        </p>
                        <p
                            class="mt-1 text-xs text-muted-foreground tabular-nums"
                        >
                            Σ destinos {{ mxn(x.p.sum_parts) }} = total
                            {{ mxn(x.p.total) }}
                        </p>
                    </div>
                </div>
            </div>
        </ErModal>

        <!-- Qué pasó con cada peso de cada archivo -->
        <ErModal
            v-if="fileControl"
            :open="active === 'files'"
            title="Qué pasó con cada peso de cada archivo"
            :description="fileControl.totals.rule"
            :icon="FileSpreadsheet"
            :tone="fileControl.totals.not_saved.count ? 'rose' : 'emerald'"
            size="2xl"
            @update:open="onModal"
        >
            <template #badge>
                <span class="rounded-full bg-muted px-3 py-1 text-sm font-bold"
                    >{{ fileControl.totals.files }} archivo(s)</span
                >
            </template>
            <FileControlTable
                :control="fileControl"
                @drill="
                    (d) =>
                        showList({
                            title: d.title,
                            filter: d.filter,
                            uploadId: d.uploadId,
                        })
                "
            />
        </ErModal>

        <!-- Integridad de los archivos -->
        <ErModal
            :open="active === 'integrity'"
            title="Integridad de los archivos"
            description="Control del banco (TOTAL EXCEL) contra lo leído de cada archivo."
            :icon="FileCheck2"
            :tone="v.integrity.status === 'ok' ? 'emerald' : 'rose'"
            size="xl"
            @update:open="onModal"
        >
            <template #badge>
                <span
                    class="rounded-full px-3 py-1 text-sm font-bold"
                    :class="
                        v.integrity.status === 'ok'
                            ? TONE_CHIP.emerald
                            : TONE_CHIP.rose
                    "
                    >{{
                        v.integrity.status === 'ok'
                            ? 'Íntegros'
                            : 'Con incidencias'
                    }}</span
                >
            </template>
            <p
                v-if="v.integrity.erp_stale"
                class="mb-4 flex items-center gap-2 rounded-2xl bg-rose-50 p-3 text-sm font-semibold text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                role="alert"
            >
                <XCircle class="size-5 shrink-0" />
                El ERP cambió después del último procesamiento: vuelve a
                procesar la conciliación.
            </p>
            <ul class="grid gap-3 md:grid-cols-2">
                <li
                    v-for="u in v.integrity.uploads"
                    :key="u.hash"
                    class="rounded-2xl border p-4"
                    :class="
                        u.control_ok === false
                            ? 'border-rose-300 bg-rose-50/40 dark:border-rose-800 dark:bg-rose-950/20'
                            : ''
                    "
                >
                    <p class="flex items-center gap-2 font-semibold">
                        <FileSpreadsheet
                            class="size-5 shrink-0 text-emerald-600"
                        />
                        <span class="min-w-0 flex-1 truncate" :title="u.file">{{
                            u.file
                        }}</span>
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        <span
                            class="rounded-full px-2.5 py-0.5 font-bold"
                            :class="
                                u.control_ok === false
                                    ? 'bg-rose-600 text-white'
                                    : u.control_ok
                                      ? 'bg-emerald-600 text-white'
                                      : 'bg-muted text-muted-foreground'
                            "
                            >{{
                                u.control_ok === false
                                    ? 'No cuadra con el control'
                                    : u.control_ok
                                      ? 'Cuadra con el control'
                                      : 'Sin total de control'
                            }}</span
                        >
                        <span class="text-muted-foreground tabular-nums"
                            >{{ u.slot }} · {{ u.movements }} movimientos</span
                        >
                        <span
                            class="font-mono text-muted-foreground"
                            :title="'sha256 ' + u.hash"
                            >#{{ u.hash.slice(0, 10) }}</span
                        >
                    </p>
                    <table class="mt-3 w-full text-sm tabular-nums">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="font-semibold"></th>
                                <th class="text-right font-semibold">
                                    Control del banco
                                </th>
                                <th class="text-right font-semibold">Leído</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in [
                                    {
                                        l: 'Cargos',
                                        c: u.control_cargos,
                                        r: u.read_cargos,
                                    },
                                    {
                                        l: 'Abonos',
                                        c: u.control_abonos,
                                        r: u.read_abonos,
                                    },
                                ]"
                                :key="row.l"
                                class="border-t"
                            >
                                <td class="py-1.5">{{ row.l }}</td>
                                <td class="text-right text-muted-foreground">
                                    {{
                                        row.c === null
                                            ? 'Sin control'
                                            : mxn(row.c)
                                    }}
                                </td>
                                <td class="text-right font-bold">
                                    {{ mxn(row.r) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </li>
            </ul>
            <p class="mt-4 text-xs text-muted-foreground">
                Reglas del último procesamiento:
                {{ v.integrity.rules_version_last_run ?? '—' }} · criterio de
                reconocimiento
                {{ v.integrity.recognition_version_last_run ?? '—' }}
            </p>
        </ErModal>

        <!-- EBITDA = Radiografía -->
        <ErModal
            :open="active === 'radiography'"
            title="Control: EBITDA de sucursales = Radiografía"
            description="El EBITDA de las sucursales del Estado de Resultados debe coincidir con la Radiografía."
            :icon="Scale"
            :tone="v.radiography.ok ? 'emerald' : 'rose'"
            size="lg"
            @update:open="onModal"
        >
            <template #badge>
                <span
                    class="rounded-full px-3 py-1 text-sm font-bold"
                    :class="
                        v.radiography.ok ? TONE_CHIP.emerald : TONE_CHIP.rose
                    "
                    >{{ v.radiography.ok ? 'Cuadra' : 'Diferencia' }}</span
                >
            </template>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-muted/50 p-4 text-center">
                    <p class="text-sm text-muted-foreground">
                        Estado de Resultados
                    </p>
                    <p class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ mxn(v.radiography.er) }}
                    </p>
                </div>
                <div class="rounded-2xl bg-muted/50 p-4 text-center">
                    <p class="text-sm text-muted-foreground">Radiografía</p>
                    <p class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ mxn(v.radiography.radiografia) }}
                    </p>
                </div>
                <div
                    class="rounded-2xl p-4 text-center text-white"
                    :class="
                        isZero(v.radiography.difference)
                            ? 'bg-emerald-600'
                            : 'bg-rose-600'
                    "
                >
                    <p class="text-sm opacity-90">Diferencia</p>
                    <p class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ mxn(v.radiography.difference) }}
                    </p>
                </div>
            </div>
            <ul
                v-if="v.radiography.explained.length"
                class="mt-4 divide-y rounded-2xl border"
            >
                <li
                    v-for="e in v.radiography.explained"
                    :key="e.label"
                    class="flex justify-between gap-3 px-4 py-2.5 text-sm"
                >
                    <span>{{ e.label }}</span>
                    <b class="tabular-nums">{{ mxn(e.amount) }}</b>
                </li>
            </ul>
        </ErModal>

        <!-- Movimientos internos eliminados -->
        <ErModal
            :open="active === 'eliminations'"
            title="Movimientos internos eliminados"
            description="Traspasos entre cuentas propias, flujos internos y reversos: no se cuentan dos veces."
            :icon="Repeat2"
            :tone="v.eliminations.unpaired_transfers.count ? 'rose' : 'cyan'"
            size="xl"
            @update:open="onModal"
        >
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-muted/50 p-4">
                    <dt class="text-sm text-muted-foreground">
                        Traspasos emparejados
                    </dt>
                    <dd class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ mxn(v.eliminations.transfers.amount) }}
                    </dd>
                    <dd class="text-sm text-muted-foreground">
                        {{ v.eliminations.transfers.pairs }} pares
                    </dd>
                </div>
                <div class="rounded-2xl bg-muted/50 p-4">
                    <dt class="text-sm text-muted-foreground">
                        Flujos internos
                    </dt>
                    <dd class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ mxn(v.eliminations.internal_flows.amount) }}
                    </dd>
                    <dd class="text-sm text-muted-foreground">
                        {{ v.eliminations.internal_flows.count }} mov.
                    </dd>
                </div>
                <div class="rounded-2xl bg-muted/50 p-4">
                    <dt class="text-sm text-muted-foreground">Reversos</dt>
                    <dd class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ mxn(v.eliminations.reversals.amount) }}
                    </dd>
                    <dd class="text-sm text-muted-foreground">
                        {{ v.eliminations.reversals.pairs }} pares
                    </dd>
                </div>
            </dl>
            <div
                v-if="v.eliminations.unpaired_transfers.count"
                class="mt-4 overflow-hidden rounded-2xl border border-rose-300 dark:border-rose-800"
            >
                <p
                    class="flex flex-wrap items-center justify-between gap-2 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                >
                    <span
                        >{{
                            v.eliminations.unpaired_transfers.count
                        }}
                        traspaso(s) sin contraparte en la otra cuenta</span
                    >
                    <span class="tabular-nums">{{
                        mxn(v.eliminations.unpaired_transfers.amount)
                    }}</span>
                </p>
                <ul class="divide-y">
                    <li
                        v-for="(t, i) in v.eliminations.unpaired_transfers
                            .items"
                        :key="t.fingerprint ?? i"
                    >
                        <button
                            type="button"
                            class="grid w-full grid-cols-[6.5rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-2.5 text-left transition hover:bg-rose-50/60 focus-visible:bg-rose-50 focus-visible:outline-none disabled:cursor-default dark:hover:bg-rose-950/20"
                            :disabled="!t.fingerprint"
                            @click="
                                t.fingerprint && emit('open', t.fingerprint)
                            "
                        >
                            <span
                                class="text-sm text-muted-foreground tabular-nums"
                                >{{ t.date ?? '—' }}</span
                            >
                            <span class="min-w-0">
                                <span
                                    class="block truncate font-semibold"
                                    :title="t.concept ?? ''"
                                    >{{ t.concept ?? '—' }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    t.account_slot
                                }}</span>
                            </span>
                            <b
                                class="tabular-nums"
                                :class="
                                    num(t.cargo)
                                        ? 'text-rose-700 dark:text-rose-400'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                >{{ mxn(num(t.cargo) ? t.cargo : t.abono) }}</b
                            >
                        </button>
                    </li>
                </ul>
            </div>
            <p
                v-if="v.eliminations.corporate_allocation"
                class="mt-4 text-sm text-muted-foreground"
            >
                {{ v.eliminations.corporate_allocation }}
            </p>
        </ErModal>

        <!-- Nómina NOI -->
        <ErModal
            :open="active === 'noi'"
            title="Nómina NOI"
            description="Colaboradores del NOI, los que no tienen sucursal y los pagos del banco vinculados."
            :icon="Users"
            :tone="num(v.noi.unassigned_total) ? 'rose' : 'violet'"
            size="lg"
            @update:open="onModal"
        >
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-muted/50 p-4">
                    <dt class="text-sm text-muted-foreground">Colaboradores</dt>
                    <dd class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ v.noi.employees }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{ mxn(v.noi.total) }}
                    </dd>
                </div>
                <div
                    class="rounded-2xl p-4"
                    :class="
                        num(v.noi.unassigned_total)
                            ? 'bg-rose-50 dark:bg-rose-950/30'
                            : 'bg-muted/50'
                    "
                >
                    <dt class="text-sm text-muted-foreground">Sin sucursal</dt>
                    <dd class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ v.noi.unassigned_employees }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{ mxn(v.noi.unassigned_total) }}
                    </dd>
                </div>
                <div class="rounded-2xl bg-muted/50 p-4">
                    <dt class="text-sm text-muted-foreground">
                        Pagos del banco vinculados
                    </dt>
                    <dd class="mt-1 text-xl font-extrabold tabular-nums">
                        {{ v.noi.linked_payments.count }}
                    </dd>
                    <dd class="text-sm text-muted-foreground tabular-nums">
                        {{ mxn(v.noi.linked_payments.amount) }}
                    </dd>
                </div>
            </dl>
            <p
                v-if="v.noi.recognizing_rule"
                class="mt-4 flex items-start gap-2 rounded-2xl bg-muted/40 p-3 text-sm leading-relaxed text-muted-foreground"
            >
                <Info class="mt-0.5 size-4 shrink-0" />
                {{ v.noi.recognizing_rule }}
            </p>
        </ErModal>
    </div>
</template>
