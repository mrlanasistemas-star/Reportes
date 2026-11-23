<script setup lang="ts">
/**
 * DetalleAuditoria — panel desplegable "Notas metodológicas e incidencias" con
 * dos pestañas: las notas del cálculo y la tabla de incidencias de auditoría.
 *
 * Se usa en ResultadoEfectividad. Las incidencias se pueden filtrar por código
 * (chips con su conteo) y la tabla muestra como máximo LIMITE filas.
 *
 * Props:
 * - notas: notas metodológicas generadas por el cálculo.
 * - incidencias: incidencias de auditoría (contrato, fecha, importe, descripción).
 */
import { BookOpen, ChevronDown, FileWarning } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Incidencia } from './types';
import { fmtMoney } from './types';

const props = defineProps<{ notas: string[]; incidencias: Incidencia[] }>();

/** Máximo de incidencias que se pintan en la tabla (evita tablas enormes). */
const LIMITE = 500;

/** Pestaña activa; inicia en Incidencias solo si no hay notas y sí hay incidencias. */
const pestana = ref<'notas' | 'incidencias'>(
    props.notas.length || !props.incidencias.length ? 'notas' : 'incidencias',
);
/** Código de incidencia seleccionado como filtro (null = todas). */
const codigo = ref<string | null>(null);

/** Definición de las pestañas con su texto, conteo e icono. */
const pestanas = computed(() => [
    {
        id: 'notas' as const,
        texto: 'Notas',
        n: props.notas.length,
        icono: BookOpen,
    },
    {
        id: 'incidencias' as const,
        texto: 'Incidencias',
        n: props.incidencias.length,
        icono: FileWarning,
    },
]);

/** Pares [código, cantidad] de incidencias, ordenados de mayor a menor. */
const porCodigo = computed(() => {
    const m = new Map<string, number>();
    props.incidencias.forEach((i) =>
        m.set(i.codigo, (m.get(i.codigo) ?? 0) + 1),
    );

    return [...m.entries()].sort((a, b) => b[1] - a[1]);
});

/** Incidencias que pasan el filtro de código. */
const visibles = computed(() =>
    codigo.value
        ? props.incidencias.filter((i) => i.codigo === codigo.value)
        : props.incidencias,
);

/** Formatea un conteo con separadores es-MX. */
const fmtN = (v: number) => v.toLocaleString('es-MX');

/** Activa el filtro por un código o lo quita si ya estaba activo. */
function alternarCodigo(c: string) {
    codigo.value = codigo.value === c ? null : c;
}
</script>

<template>
    <details class="group rounded-2xl border bg-card shadow-sm">
        <!-- Encabezado clicable con el total de notas e incidencias -->
        <summary
            class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-5 py-4 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none [&::-webkit-details-marker]:hidden"
        >
            <span>
                <span class="block font-semibold"
                    >Notas metodológicas e incidencias</span
                >
                <span class="block text-xs text-muted-foreground">
                    {{ fmtN(notas.length) }}
                    {{ notas.length === 1 ? 'nota' : 'notas' }} ·
                    {{ fmtN(incidencias.length) }}
                    {{
                        incidencias.length === 1 ? 'incidencia' : 'incidencias'
                    }}
                    de auditoría
                </span>
            </span>
            <ChevronDown
                class="size-4 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                aria-hidden="true"
            />
        </summary>

        <!-- Contenido: selector de pestañas y panel activo -->
        <div class="space-y-4 border-t px-5 py-4 text-sm">
            <div
                class="inline-flex rounded-xl bg-muted p-1"
                role="tablist"
                aria-label="Detalle del cálculo"
            >
                <button
                    v-for="t in pestanas"
                    :key="t.id"
                    type="button"
                    role="tab"
                    :aria-selected="pestana === t.id"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-all focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                    :class="
                        pestana === t.id
                            ? 'bg-card text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="pestana = t.id"
                >
                    <component
                        :is="t.icono"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ t.texto }}
                    <span
                        class="rounded-full bg-muted-foreground/10 px-1.5 text-xs tabular-nums"
                        >{{ fmtN(t.n) }}</span
                    >
                </button>
            </div>

            <!-- Pestaña Notas (la pestaña Incidencias es el v-else siguiente) -->
            <div v-if="pestana === 'notas'" role="tabpanel">
                <ul
                    v-if="notas.length"
                    class="list-disc space-y-1.5 pl-5 text-muted-foreground"
                >
                    <li v-for="(n, idx) in notas" :key="`${idx}-${n}`">
                        {{ n }}
                    </li>
                </ul>
                <p v-else class="text-muted-foreground">
                    Este cálculo no generó notas.
                </p>
            </div>

            <div v-else role="tabpanel" class="space-y-3">
                <p v-if="!incidencias.length" class="text-muted-foreground">
                    No se registraron incidencias de auditoría.
                </p>
                <template v-else>
                    <!-- Chips para filtrar por código de incidencia -->
                    <div
                        class="flex flex-wrap gap-2"
                        aria-label="Filtrar por código"
                    >
                        <button
                            v-for="[c, total] in porCodigo"
                            :key="c"
                            type="button"
                            class="rounded-full border px-2.5 py-0.5 text-xs transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                            :class="
                                codigo === c
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted'
                            "
                            :aria-pressed="codigo === c"
                            @click="alternarCodigo(c)"
                        >
                            <span class="font-mono">{{ c }}</span
                            >:
                            <b>{{ fmtN(total) }}</b>
                        </button>
                        <button
                            v-if="codigo"
                            type="button"
                            class="rounded-full px-2.5 py-0.5 text-xs text-muted-foreground underline-offset-2 hover:underline"
                            @click="codigo = null"
                        >
                            Ver todas
                        </button>
                    </div>
                    <!-- Tabla de incidencias (limitada a LIMITE filas) -->
                    <div class="max-h-96 overflow-auto rounded-xl border">
                        <table class="w-full text-xs">
                            <thead
                                class="sticky top-0 bg-muted text-left text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-3 py-2 font-medium">
                                        Código
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Contrato
                                    </th>
                                    <th class="px-3 py-2 font-medium">Fecha</th>
                                    <th
                                        class="px-3 py-2 text-right font-medium"
                                    >
                                        Importe
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Descripción
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="(i, idx) in visibles.slice(
                                        0,
                                        LIMITE,
                                    )"
                                    :key="`${i.id}-${idx}`"
                                    class="hover:bg-muted/40"
                                >
                                    <td class="px-3 py-1.5 font-mono">
                                        {{ i.codigo }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ i.contrato ?? '—' }}
                                    </td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        {{ i.fecha ?? '—' }}
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-right whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            i.importe !== null
                                                ? fmtMoney(i.importe)
                                                : '—'
                                        }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        {{ i.descripcion }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p
                            v-if="visibles.length > LIMITE"
                            class="border-t px-3 py-2 text-xs text-muted-foreground"
                        >
                            Se muestran las primeras {{ fmtN(LIMITE) }} de
                            {{ fmtN(visibles.length) }}.
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </details>
</template>
