<script setup lang="ts">
import {
    ArrowRight,
    CheckCircle2,
    CircleAlert,
    FileSpreadsheet,
    Info,
    Repeat2,
    Users,
    XCircle,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { isZero, mxn, num } from './types';
import type { PartitionCheck, Validation } from './types';
import ValidationDifferences from './ValidationDifferences.vue';

const props = defineProps<{ validation: Validation }>();
const emit = defineEmits<{ open: [fingerprint: string] }>();

const v = computed(() => props.validation);

const STATUS = {
    ok: {
        title: 'Todo cuadra y no hay diferencias sin resolver',
        cls: 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200',
        icon: CheckCircle2,
    },
    con_pendientes: {
        title: 'Las sumas cuadran, pero quedan puntos por resolver',
        cls: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200',
        icon: CircleAlert,
    },
    con_diferencias: {
        title: 'Hay diferencias: algo no cuadra',
        cls: 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950/30 dark:text-rose-200',
        icon: XCircle,
    },
} as const;

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

const partitions = computed<Array<{ label: string; p: PartitionCheck }>>(() => [
    { label: 'Estados de cuenta', p: v.value.partition.bank },
    { label: 'ERP fuera de Radiografía', p: v.value.partition.erp },
    { label: 'Todo el periodo', p: v.value.partition.all },
]);

const importOk = computed(
    () => v.value.integrity.import_matches_source ?? true,
);
</script>

<template>
    <div class="space-y-4">
        <!-- Veredicto -->
        <div
            class="flex items-start gap-3 rounded-2xl border p-4"
            :class="status.cls"
            role="status"
        >
            <component :is="status.icon" class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-extrabold">{{ status.title }}</p>
                <p class="mt-0.5 text-xs opacity-90">
                    Cada peso del banco y del ERP debe terminar en exactamente
                    un destino. Las diferencias sin resolver se muestran en rojo
                    y nunca se ocultan.
                </p>
                <p
                    v-if="v.scope_note"
                    class="mt-1 flex items-center gap-1 text-xs"
                >
                    <Info class="size-3.5" /> {{ v.scope_note }}
                </p>
            </div>
        </div>

        <!-- Sin resolver -->
        <section
            v-if="v.unresolved.length || v.failed_checks.length"
            class="rounded-2xl border border-rose-300 bg-card shadow-soft-xs dark:border-rose-900"
            aria-label="Diferencias sin resolver"
        >
            <header
                class="flex items-center gap-2 border-b border-rose-200 px-5 py-3 dark:border-rose-900"
            >
                <XCircle class="size-4 text-rose-600" />
                <h3
                    class="text-sm font-extrabold text-rose-800 dark:text-rose-300"
                >
                    Sin resolver ({{ v.unresolved.length }})
                </h3>
            </header>
            <ul class="divide-y divide-rose-100 dark:divide-rose-950">
                <li
                    v-for="u in v.unresolved"
                    :key="u.key"
                    class="flex items-center gap-3 px-5 py-2.5 text-sm"
                >
                    <span class="size-2 shrink-0 rounded-full bg-rose-500" />
                    <span class="min-w-0 flex-1 font-medium">{{
                        u.label
                    }}</span>
                    <span
                        v-if="u.blocking !== undefined"
                        class="shrink-0 rounded-full px-2 py-px text-[10.5px] font-bold"
                        :class="
                            u.blocking
                                ? 'bg-rose-600 text-white'
                                : 'bg-muted text-muted-foreground'
                        "
                        :title="
                            u.blocking
                                ? 'Impide aprobar la versión.'
                                : 'Informativo: no impide aprobar.'
                        "
                        >{{
                            u.blocking ? 'Bloquea la aprobación' : 'Informativo'
                        }}</span
                    >
                    <span
                        class="shrink-0 text-xs text-muted-foreground tabular-nums"
                        :title="
                            u.open_count !== undefined ? 'Abiertas / total' : ''
                        "
                        >{{ (u.open_count ?? u.count).toLocaleString('es-MX')
                        }}<template
                            v-if="
                                u.open_count !== undefined &&
                                u.open_count !== u.count
                            "
                        >
                            de {{ u.count.toLocaleString('es-MX') }}</template
                        >
                        abiertas</span
                    >
                    <span
                        class="w-32 shrink-0 text-right font-bold text-rose-700 tabular-nums dark:text-rose-400"
                        >{{ mxn(u.open_amount ?? u.amount) }}</span
                    >
                </li>
            </ul>
        </section>

        <!-- Embudo: archivo → importado → destinos -->
        <section class="rounded-2xl border bg-card p-5 shadow-soft-xs">
            <h3 class="text-base font-extrabold">Del archivo a cada destino</h3>
            <div
                class="mt-4 grid items-stretch gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] 2xl:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,2fr)]"
            >
                <div class="rounded-xl bg-muted/50 p-3">
                    <p class="text-xs text-muted-foreground">
                        Archivos del banco
                    </p>
                    <p class="text-lg font-extrabold tabular-nums">
                        {{ mxn(v.totals.source.total) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ v.totals.source.count.toLocaleString('es-MX') }}
                        movimientos · cargos {{ mxn(v.totals.source.cargos) }} ·
                        abonos
                        {{ mxn(v.totals.source.abonos) }}
                    </p>
                </div>
                <span class="hidden items-center md:flex" aria-hidden="true">
                    <ArrowRight class="size-5 text-muted-foreground" />
                </span>
                <div
                    class="rounded-xl p-3"
                    :class="
                        importOk
                            ? 'bg-muted/50'
                            : 'bg-rose-50 ring-1 ring-rose-300 dark:bg-rose-950/30 dark:ring-rose-800'
                    "
                >
                    <p
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        Importado
                        <CheckCircle2
                            v-if="importOk"
                            class="size-3.5 text-emerald-600"
                        />
                        <XCircle v-else class="size-3.5 text-rose-600" />
                    </p>
                    <p class="text-lg font-extrabold tabular-nums">
                        {{ mxn(v.totals.imported.total) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ v.totals.imported.count.toLocaleString('es-MX') }}
                        movimientos
                        <template v-if="v.totals.erp_outside.count">
                            + ERP fuera de Radiografía
                            {{ mxn(v.totals.erp_outside.total) }}
                            ({{ v.totals.erp_outside.count }})</template
                        >
                    </p>
                    <p
                        v-if="!importOk"
                        class="mt-1 text-xs font-bold text-rose-700 dark:text-rose-300"
                    >
                        Lo importado no coincide con el archivo.
                    </p>
                </div>
                <span class="hidden items-center 2xl:flex" aria-hidden="true">
                    <ArrowRight class="size-5 text-muted-foreground" />
                </span>
                <div
                    class="min-w-0 rounded-xl border p-3 md:col-span-3 2xl:col-span-1"
                >
                    <div
                        class="flex h-4 w-full gap-px overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            v-for="p in parts.filter((x) => x.width > 0)"
                            :key="p.key"
                            class="h-full"
                            :class="p.cls"
                            :style="{ width: `${p.width}%` }"
                            :title="`${p.label}: ${mxn(p.amount)}`"
                        />
                    </div>
                    <ul class="mt-3 grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
                        <li
                            v-for="p in parts"
                            :key="p.key"
                            class="flex items-center gap-2 text-xs"
                            :class="p.width > 0 ? '' : 'opacity-45'"
                        >
                            <span
                                class="size-2.5 shrink-0 rounded-sm"
                                :class="p.cls"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                p.label
                            }}</span>
                            <span class="text-muted-foreground tabular-nums">{{
                                p.count
                            }}</span>
                            <span
                                class="w-28 text-right font-semibold tabular-nums"
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
            </div>

            <!-- Partición en $0.00 -->
            <div class="mt-4 grid gap-2 md:grid-cols-3">
                <div
                    v-for="x in partitions"
                    :key="x.label"
                    class="rounded-xl border p-3"
                    :class="
                        x.p.ok
                            ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20'
                            : 'border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/30'
                    "
                >
                    <p
                        class="flex items-center justify-between gap-2 text-xs font-bold"
                    >
                        {{ x.label }}
                        <span
                            class="inline-flex items-center gap-1 rounded-full px-2 py-px text-[10.5px]"
                            :class="
                                x.p.ok
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-rose-600 text-white'
                            "
                        >
                            <CheckCircle2 v-if="x.p.ok" class="size-3" />
                            <XCircle v-else class="size-3" />
                            Diferencia {{ mxn(x.p.difference) }}
                        </span>
                    </p>
                    <p
                        class="mt-1.5 text-xs text-muted-foreground tabular-nums"
                    >
                        Σ destinos {{ mxn(x.p.sum_parts) }} = total
                        {{ mxn(x.p.total) }}
                    </p>
                </div>
            </div>
        </section>

        <ValidationDifferences
            :differences="v.differences"
            @open="(fp) => emit('open', fp)"
        />

        <div class="grid gap-4 xl:grid-cols-2">
            <!-- Integridad por archivo -->
            <section class="rounded-2xl border bg-card p-5 shadow-soft-xs">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-base font-extrabold">
                        Integridad de los archivos
                    </h3>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-bold"
                        :class="
                            v.integrity.status === 'ok'
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                        "
                        >{{
                            v.integrity.status === 'ok'
                                ? 'Íntegros'
                                : 'Con incidencias'
                        }}</span
                    >
                </div>
                <p
                    v-if="v.integrity.erp_stale"
                    class="mt-2 rounded-lg bg-rose-50 p-2 text-xs font-semibold text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                >
                    El ERP cambió después del último procesamiento: vuelve a
                    procesar la conciliación.
                </p>
                <ul class="mt-3 space-y-2">
                    <li
                        v-for="u in v.integrity.uploads"
                        :key="u.hash"
                        class="rounded-xl border p-3"
                        :class="
                            u.control_ok === false
                                ? 'border-rose-300 dark:border-rose-800'
                                : ''
                        "
                    >
                        <p
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <FileSpreadsheet
                                class="size-4 shrink-0 text-emerald-600"
                            />
                            <span
                                class="min-w-0 flex-1 truncate"
                                :title="u.file"
                                >{{ u.file }}</span
                            >
                            <span
                                class="shrink-0 rounded-full px-2 py-px text-[10.5px] font-bold"
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
                        </p>
                        <p
                            class="mt-0.5 text-[11px] text-muted-foreground tabular-nums"
                        >
                            {{ u.slot }} · {{ u.movements }} movimientos ·
                            sha256
                            {{ u.hash.slice(0, 12) }}
                        </p>
                        <table class="mt-2 w-full text-xs tabular-nums">
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th class="font-semibold"></th>
                                    <th class="text-right font-semibold">
                                        Control del banco
                                    </th>
                                    <th class="text-right font-semibold">
                                        Leído
                                    </th>
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
                                >
                                    <td class="py-0.5">{{ row.l }}</td>
                                    <td class="text-right">
                                        {{
                                            row.c === null
                                                ? 'Sin control'
                                                : mxn(row.c)
                                        }}
                                    </td>
                                    <td class="text-right font-semibold">
                                        {{ mxn(row.r) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </li>
                </ul>
                <p class="mt-2 text-[11px] text-muted-foreground">
                    Reglas del último procesamiento:
                    {{ v.integrity.rules_version_last_run ?? '—' }} · criterio
                    de reconocimiento
                    {{ v.integrity.recognition_version_last_run ?? '—' }}
                </p>
            </section>

            <div class="space-y-4">
                <!-- Radiografía -->
                <section class="rounded-2xl border bg-card p-5 shadow-soft-xs">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-base font-extrabold">
                            Control: EBITDA de sucursales = Radiografía
                        </h3>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-bold"
                            :class="
                                v.radiography.ok
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                    : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                            "
                            >{{
                                v.radiography.ok
                                    ? 'Cuadra'
                                    : 'Diferencia ' +
                                      mxn(v.radiography.difference)
                            }}</span
                        >
                    </div>
                    <div
                        class="mt-3 grid grid-cols-3 gap-2 text-center text-xs"
                    >
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <p class="text-muted-foreground">
                                Estado de Resultados
                            </p>
                            <p class="text-sm font-extrabold tabular-nums">
                                {{ mxn(v.radiography.er) }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <p class="text-muted-foreground">Radiografía</p>
                            <p class="text-sm font-extrabold tabular-nums">
                                {{ mxn(v.radiography.radiografia) }}
                            </p>
                        </div>
                        <div
                            class="rounded-xl p-2.5"
                            :class="
                                isZero(v.radiography.difference)
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-rose-600 text-white'
                            "
                        >
                            <p class="opacity-85">Diferencia</p>
                            <p class="text-sm font-extrabold tabular-nums">
                                {{ mxn(v.radiography.difference) }}
                            </p>
                        </div>
                    </div>
                    <ul
                        v-if="v.radiography.explained.length"
                        class="mt-3 space-y-1 text-xs"
                    >
                        <li
                            v-for="e in v.radiography.explained"
                            :key="e.label"
                            class="flex justify-between gap-2"
                        >
                            <span>{{ e.label }}</span>
                            <b class="tabular-nums">{{ mxn(e.amount) }}</b>
                        </li>
                    </ul>
                </section>

                <!-- Eliminaciones -->
                <section class="rounded-2xl border bg-card p-5 shadow-soft-xs">
                    <h3
                        class="flex items-center gap-2 text-base font-extrabold"
                    >
                        <Repeat2 class="size-4" /> Movimientos internos
                        eliminados
                    </h3>
                    <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <dt class="text-muted-foreground">
                                Traspasos emparejados
                            </dt>
                            <dd class="font-extrabold tabular-nums">
                                {{ mxn(v.eliminations.transfers.amount) }}
                            </dd>
                            <dd class="text-muted-foreground">
                                {{ v.eliminations.transfers.pairs }} pares
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <dt class="text-muted-foreground">
                                Flujos internos
                            </dt>
                            <dd class="font-extrabold tabular-nums">
                                {{ mxn(v.eliminations.internal_flows.amount) }}
                            </dd>
                            <dd class="text-muted-foreground">
                                {{ v.eliminations.internal_flows.count }} mov.
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <dt class="text-muted-foreground">Reversos</dt>
                            <dd class="font-extrabold tabular-nums">
                                {{ mxn(v.eliminations.reversals.amount) }}
                            </dd>
                            <dd class="text-muted-foreground">
                                {{ v.eliminations.reversals.pairs }} pares
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="v.eliminations.unpaired_transfers.count"
                        class="mt-3 rounded-xl border border-rose-300 p-3 dark:border-rose-800"
                    >
                        <p
                            class="text-xs font-bold text-rose-800 dark:text-rose-300"
                        >
                            {{ v.eliminations.unpaired_transfers.count }}
                            traspaso(s) sin contraparte en la otra cuenta ·
                            {{ mxn(v.eliminations.unpaired_transfers.amount) }}
                        </p>
                        <ul class="mt-2 max-h-48 space-y-0.5 overflow-y-auto">
                            <li
                                v-for="(t, i) in v.eliminations
                                    .unpaired_transfers.items"
                                :key="t.fingerprint ?? i"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-1 text-left text-xs transition hover:bg-rose-50 disabled:cursor-default dark:hover:bg-rose-950/30"
                                    :disabled="!t.fingerprint"
                                    @click="
                                        t.fingerprint &&
                                        emit('open', t.fingerprint)
                                    "
                                >
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                        >{{ t.date }}</span
                                    >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        t.concept ?? '—'
                                    }}</span>
                                    <span class="text-muted-foreground">{{
                                        t.account_slot
                                    }}</span>
                                    <b class="tabular-nums">{{
                                        mxn(num(t.cargo) ? t.cargo : t.abono)
                                    }}</b>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <p
                        v-if="v.eliminations.corporate_allocation"
                        class="mt-2 text-[11px] text-muted-foreground"
                    >
                        {{ v.eliminations.corporate_allocation }}
                    </p>
                </section>

                <!-- NOI -->
                <section class="rounded-2xl border bg-card p-5 shadow-soft-xs">
                    <h3
                        class="flex items-center gap-2 text-base font-extrabold"
                    >
                        <Users class="size-4" /> Nómina NOI
                    </h3>
                    <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <dt class="text-muted-foreground">Colaboradores</dt>
                            <dd class="font-extrabold tabular-nums">
                                {{ v.noi.employees }}
                            </dd>
                            <dd class="text-muted-foreground tabular-nums">
                                {{ mxn(v.noi.total) }}
                            </dd>
                        </div>
                        <div
                            class="rounded-xl p-2.5"
                            :class="
                                num(v.noi.unassigned_total)
                                    ? 'bg-rose-50 dark:bg-rose-950/30'
                                    : 'bg-muted/50'
                            "
                        >
                            <dt class="text-muted-foreground">Sin sucursal</dt>
                            <dd class="font-extrabold tabular-nums">
                                {{ v.noi.unassigned_employees }}
                            </dd>
                            <dd class="text-muted-foreground tabular-nums">
                                {{ mxn(v.noi.unassigned_total) }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2.5">
                            <dt class="text-muted-foreground">
                                Pagos del banco vinculados
                            </dt>
                            <dd class="font-extrabold tabular-nums">
                                {{ v.noi.linked_payments.count }}
                            </dd>
                            <dd class="text-muted-foreground tabular-nums">
                                {{ mxn(v.noi.linked_payments.amount) }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="mt-2 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{ v.noi.recognizing_rule }}
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
