<script setup lang="ts">
import {
    ArrowDown,
    ArrowUpRight,
    Database,
    Landmark,
    Lock,
    Undo2,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { mxn, num } from './types';
import type { Movement, MovementLink } from './types';

const props = defineProps<{
    movement: Movement;
    /** Busca un movimiento visible del dataset por huella (null si no existe o está oculto). */
    lookup: (fingerprint: string) => Movement | null;
}>();

const emit = defineEmits<{ open: [m: Movement] }>();

const ICON: Record<string, unknown> = {
    banco: Landmark,
    erp: Database,
    noi: Users,
};
const TYPE_LABEL: Record<string, string> = {
    banco: 'Estado de cuenta',
    erp: 'ERP de gastos',
    noi: 'Nómina NOI',
};

const self = computed(() => ({
    type: props.movement.source,
    title:
        props.movement.source === 'erp'
            ? `Requisición ${props.movement.folio ?? ''}`.trim()
            : `${props.movement.account_label ?? props.movement.account_slot} · fila ${props.movement.source_row}`,
    sub: props.movement.concept ?? '',
    amount: num(props.movement.cargo)
        ? props.movement.cargo
        : props.movement.abono,
    date: props.movement.date,
}));

function str(v: unknown): string | null {
    return v === null || v === undefined || v === '' ? null : String(v);
}

/** Descripción del otro extremo de cada vínculo (sólo datos del dataset). */
const nodes = computed(() =>
    (props.movement.links ?? []).map((l: MovementLink) => {
        const target = l.target ? props.lookup(l.target) : null;
        const erp =
            l.target_type === 'erp' && l.target
                ? (props.movement.erp ?? []).find(
                      (e) => e.fingerprint === l.target,
                  )
                : undefined;
        const ev = (Array.isArray(l.evidence) ? {} : l.evidence) as Record<
            string,
            unknown
        >;
        let title = TYPE_LABEL[l.target_type] ?? l.target_type;
        let sub = '';
        let amount: string | null = null;
        let date: string | null = null;

        if (target) {
            title =
                target.source === 'erp'
                    ? `Requisición ${target.folio ?? ''}`.trim()
                    : `${target.account_label ?? target.account_slot} · fila ${target.source_row}`;
            sub = target.concept ?? '';
            amount = num(target.cargo) ? target.cargo : target.abono;
            date = target.date;
        } else if (erp) {
            title = `Requisición ${erp.folio ?? ''}`.trim();
            sub = [erp.concept, erp.branch_name].filter(Boolean).join(' · ');
            amount = erp.amount;
            date = erp.date;
        } else if (l.target_type === 'noi') {
            title = str(ev.employee) ?? 'Colaborador NOI';
            sub = [
                str(ev.branch),
                ev.operative === false ? 'no operativo' : null,
            ]
                .filter(Boolean)
                .join(' · ');
            amount = str(ev.noi_total);
        } else if (str(ev.counter_account)) {
            sub = `Cuenta contraparte: ${str(ev.counter_account)}`;
        }

        return {
            link: l,
            target,
            title,
            sub,
            amount,
            date,
            redacted: !!l.redacted,
            icon: ICON[l.target_type] ?? Database,
        };
    }),
);

const extra = computed(() => {
    const m = props.movement;
    const out: Array<{ label: string; target: Movement | null }> = [];

    if (m.refund_of) {
        out.push({
            label: `Reembolso de un gasto${m.refund_status ? ` (${m.refund_status})` : ''}`,
            target: props.lookup(m.refund_of),
        });
    }

    if (m.reversal_pair) {
        out.push({ label: `Par de reverso ${m.reversal_pair}`, target: null });
    }

    return out;
});
</script>

<template>
    <div>
        <p class="text-sm font-extrabold">Cadena de evidencia</p>
        <p class="mt-0.5 text-xs text-muted-foreground">
            Con qué otros registros se emparejó este movimiento y cuánto se
            aplicó en cada vínculo.
        </p>

        <p
            v-if="!nodes.length && !extra.length"
            class="mt-3 rounded-xl border border-dashed p-3 text-xs text-muted-foreground"
        >
            Sin vínculos: no se emparejó con ERP, NOI ni con otra cuenta.
        </p>

        <ol v-else class="mt-3 space-y-1">
            <!-- Este movimiento -->
            <li
                class="flex items-center gap-3 rounded-xl border-2 border-foreground/15 bg-card p-2.5"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white dark:bg-white dark:text-slate-900"
                >
                    <component
                        :is="ICON[self.type] ?? Landmark"
                        class="size-4"
                    />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-bold">{{
                        self.title
                    }}</span>
                    <span
                        class="block truncate text-[11px] text-muted-foreground"
                        >{{ self.sub }}</span
                    >
                </span>
                <span class="text-right text-xs">
                    <b class="block tabular-nums">{{ mxn(self.amount) }}</b>
                    <span class="text-muted-foreground">{{ self.date }}</span>
                </span>
            </li>

            <template v-for="(n, i) in nodes" :key="i">
                <li class="flex items-center gap-2 py-1 pl-5 text-[11px]">
                    <ArrowDown
                        class="size-3.5 shrink-0 text-muted-foreground"
                    />
                    <span
                        class="rounded-full bg-muted px-2 py-0.5 font-semibold"
                        >{{ n.link.kind_label }}</span
                    >
                    <span
                        v-if="n.link.match_type"
                        class="font-semibold text-cyan-700 dark:text-cyan-300"
                        >{{ n.link.match_type }}</span
                    >
                    <span v-if="num(n.link.applied)" class="tabular-nums"
                        >aplicado <b>{{ mxn(n.link.applied) }}</b></span
                    >
                    <span
                        v-if="n.link.delta_days !== null"
                        class="text-muted-foreground"
                        >· {{ n.link.delta_days }} día(s) de diferencia</span
                    >
                </li>
                <li>
                    <component
                        :is="n.target ? 'button' : 'div'"
                        :type="n.target ? 'button' : undefined"
                        class="flex w-full items-center gap-3 rounded-xl border p-2.5 text-left"
                        :class="
                            n.target
                                ? 'group transition hover:border-cyan-400 hover:bg-cyan-50/50 focus-visible:ring-3 focus-visible:ring-cyan-500/30 focus-visible:outline-none dark:hover:bg-cyan-950/20'
                                : ''
                        "
                        @click="n.target && emit('open', n.target)"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <Lock
                                v-if="n.redacted"
                                class="size-4 text-muted-foreground"
                            />
                            <component :is="n.icon" v-else class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-bold">{{
                                n.redacted
                                    ? 'Detalle restringido por permisos'
                                    : n.title
                            }}</span>
                            <span
                                v-if="n.sub && !n.redacted"
                                class="block truncate text-[11px] text-muted-foreground"
                                >{{ n.sub }}</span
                            >
                            <span
                                v-if="
                                    num(n.link.movement_pending) ||
                                    num(n.link.target_pending)
                                "
                                class="block text-[11px] font-semibold text-amber-700 dark:text-amber-400"
                            >
                                Queda sin aplicar:
                                <template v-if="num(n.link.movement_pending)"
                                    >{{ mxn(n.link.movement_pending) }} de este
                                    movimiento</template
                                >
                                <template v-if="num(n.link.target_pending)">
                                    · {{ mxn(n.link.target_pending) }} del otro
                                    registro</template
                                >
                            </span>
                        </span>
                        <span class="text-right text-xs">
                            <b v-if="n.amount" class="block tabular-nums">{{
                                mxn(n.amount)
                            }}</b>
                            <span v-if="n.date" class="text-muted-foreground">{{
                                n.date
                            }}</span>
                        </span>
                        <ArrowUpRight
                            v-if="n.target"
                            class="size-4 shrink-0 text-muted-foreground transition group-hover:text-cyan-600"
                        />
                    </component>
                </li>
            </template>

            <li
                v-for="(x, i) in extra"
                :key="`x${i}`"
                class="mt-2 flex items-center gap-2 rounded-xl bg-muted/50 p-2.5 text-xs"
            >
                <Undo2 class="size-4 shrink-0" />
                <span class="flex-1">{{ x.label }}</span>
                <button
                    v-if="x.target"
                    type="button"
                    class="font-semibold text-cyan-700 hover:underline dark:text-cyan-300"
                    @click="x.target && emit('open', x.target)"
                >
                    Ver gasto original
                </button>
            </li>
        </ol>
    </div>
</template>
