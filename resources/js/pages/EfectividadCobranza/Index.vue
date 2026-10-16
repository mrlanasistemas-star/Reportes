<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Calculator,
    CalendarCheck,
    CalendarDays,
    CalendarRange,
    Download,
    FileImage,
    FileText,
    Loader2,
    RotateCcw,
    Sun,
} from 'lucide-vue-next';
import Swal from 'sweetalert2';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import ArchivosDropzone from '@/components/efectividad/calculadora/ArchivosDropzone.vue';
import RequisitosCorte from '@/components/efectividad/calculadora/RequisitosCorte.vue';
import ResultadoEfectividad from '@/components/efectividad/calculadora/ResultadoEfectividad.vue';
import ResumenFuentes from '@/components/efectividad/calculadora/ResumenFuentes.vue';
import type {
    FilaEfectividad,
    Modo,
    Rechazado,
    Resultado,
    ResumenFuentes as Resumen,
} from '@/components/efectividad/calculadora/types';
import {
    mensajeError,
    xsrfHeaders,
} from '@/components/efectividad/calculadora/types';
import { toast } from '@/components/feedback/Toast';
import SelectField from '@/components/forms/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index as efectividadIndex } from '@/routes/efectividad-cobranza';

const props = defineProps<{
    limites: { max_archivos: number; max_mb: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Efectividad de Cobranza', href: efectividadIndex() },
        ],
    },
});

const BASE = '/efectividad-cobranza/calculadora';

const MODOS: Array<{
    valor: Modo;
    titulo: string;
    texto: string;
    icono: typeof Sun;
}> = [
    {
        valor: 'DIARIO',
        titulo: 'Diario',
        texto: 'Cobranza de un solo día. Usa las carteras desde el domingo anterior hasta ese día.',
        icono: Sun,
    },
    {
        valor: 'ACUMULADO',
        titulo: 'Acumulado',
        texto: 'Del lunes de la semana hasta el día elegido.',
        icono: CalendarCheck,
    },
    {
        valor: 'SEMANAL',
        titulo: 'Semanal',
        texto: 'Lunes a domingo. Requiere las 8 carteras (domingo base a domingo de corte).',
        icono: CalendarDays,
    },
    {
        valor: 'MENSUAL',
        titulo: 'Mensual',
        texto: 'Suma las semanas completas del mes. Los saldos de atraso/vencido son los del último corte.',
        icono: CalendarRange,
    },
];

const modo = ref<Modo>('SEMANAL');
const token = ref<string | null>(null);
const resumen = ref<Resumen | null>(null);
const rechazados = ref<Rechazado[]>([]);
const subiendo = ref(false);
const progreso = ref<{ actual: number; total: number; nombre: string } | null>(
    null,
);
const corte = ref<string | null>(null);
const calculando = ref(false);
const resultado = ref<Resultado | null>(null);
const filas = ref<FilaEfectividad[]>([]);
const sucursal = ref<string>('');
const oficina = ref<string>(''); // RUTA de Lendus
const promotor = ref<string>('');
const exportando = ref<'pdf' | 'png' | null>(null);

const ocupado = computed(
    () => subiendo.value || calculando.value || exportando.value !== null,
);
const hayArchivos = computed(
    () =>
        !!resumen.value &&
        resumen.value.carteras.length + resumen.value.ingresos.length > 0,
);
const opcionesCorte = computed(() => resumen.value?.cortes[modo.value] ?? []);
// Filtros en cascada: Sucursal → Ruta → Promotor (una ruta pertenece a una sucursal).
const opcionesSucursal = computed(() => [
    { value: '', label: 'Todas las sucursales' },
    ...(resultado.value?.catalogo ?? []).map((s) => ({
        value: s.sucursal,
        label: s.sucursal,
    })),
]);
const rutasVisibles = computed(() =>
    (resultado.value?.catalogo ?? [])
        .filter((s) => !sucursal.value || s.sucursal === sucursal.value)
        .flatMap((s) => s.rutas),
);
const opcionesOficina = computed(() => [
    { value: '', label: 'Todas las rutas' },
    ...rutasVisibles.value.map((r) => ({ value: r.ruta, label: r.ruta })),
]);
const opcionesPromotor = computed(() => {
    const lista = oficina.value
        ? rutasVisibles.value.filter((r) => r.ruta === oficina.value)
        : rutasVisibles.value;

    return [
        { value: '', label: 'Todos los promotores' },
        ...lista.flatMap((r) =>
            r.promotores.map((p) => ({
                value: p,
                label: oficina.value ? p : `${p} · ${r.ruta}`,
            })),
        ),
    ];
});
const opcionCorte = computed(
    () => opcionesCorte.value.find((o) => o.valor === corte.value) ?? null,
);
// El botón solo se habilita con TODAS las fuentes del corte elegido (carteras e ingresos).
const faltante = computed(() => {
    if (!resumen.value || !hayArchivos.value) {
        return 'Carga tus archivos de cartera e ingresos.';
    }

    if (!opcionCorte.value) {
        return modo.value === 'MENSUAL'
            ? 'Para el mensual necesitas al menos una semana completa (8 carteras) dentro del mes.'
            : 'Carga las carteras del periodo que quieres calcular.';
    }

    return opcionCorte.value.listo ? null : opcionCorte.value.falta;
});

// Al cambiar de modalidad o de archivos se conserva lo cargado y se elige el corte
// listo más reciente (o el más reciente si ninguno está listo).
watch(
    opcionesCorte,
    (ops) => {
        const actual = ops.find((o) => o.valor === corte.value);

        if (!actual || (!actual.listo && ops.some((o) => o.listo))) {
            corte.value = (ops.find((o) => o.listo) ?? ops[0])?.valor ?? null;
        }
    },
    { immediate: true },
);

const ESTADO_CORTE: Record<string, string> = {
    listo: 'listo para calcular',
    faltan_ingresos: 'carteras completas · faltan ingresos',
    faltan_carteras: 'faltan carteras',
};
watch(sucursal, () => {
    oficina.value = '';
    promotor.value = '';
});
watch(oficina, () => {
    promotor.value = '';
});
watch([sucursal, oficina, promotor], () => void cargarVista());

async function subir(archivos: File[]) {
    const excel = archivos.filter((f) => /\.xls[xm]$/i.test(f.name));
    const ignorados: Rechazado[] = archivos
        .filter((f) => !excel.includes(f))
        .map((f) => ({
            nombre: f.name,
            error: 'No es un archivo de Excel (.xlsx).',
        }));
    rechazados.value = ignorados;
    subiendo.value = true;
    resultado.value = null;

    try {
        // Un archivo por petición: progreso real y sin chocar con el límite de tamaño del servidor.
        for (const [i, archivo] of excel.entries()) {
            progreso.value = {
                actual: i + 1,
                total: excel.length,
                nombre: archivo.name,
            };
            const fd = new FormData();
            fd.append('archivos[]', archivo);

            if (token.value) {
                fd.append('token', token.value);
            }

            const res = await fetch(`${BASE}/archivos`, {
                method: 'POST',
                headers: xsrfHeaders(),
                body: fd,
            });

            if (!res.ok) {
                const msg = await mensajeError(res);

                if (res.status === 422 && msg.includes('expiró')) {
                    token.value = null;
                    resumen.value = null;
                }

                rechazados.value.push({ nombre: archivo.name, error: msg });
                continue;
            }

            const body = (await res.json()) as {
                token: string;
                rechazados: Rechazado[];
                resumen: Resumen;
            };
            token.value = body.token;
            resumen.value = body.resumen;
            rechazados.value.push(...body.rechazados);
        }
    } catch {
        rechazados.value.push({
            nombre: '—',
            error: 'Se perdió la conexión con el servidor mientras se subían los archivos.',
        });
    } finally {
        subiendo.value = false;
        progreso.value = null;
    }
}

async function quitar(id: string) {
    if (!token.value) {
        return;
    }

    const res = await fetch(`${BASE}/${token.value}/archivos/${id}`, {
        method: 'DELETE',
        headers: xsrfHeaders(),
    });

    if (!res.ok) {
        void Swal.fire({
            icon: 'error',
            title: 'No se pudo quitar',
            text: await mensajeError(res),
        });

        return;
    }

    resumen.value = ((await res.json()) as { resumen: Resumen }).resumen;
    resultado.value = null;
}

async function limpiar(confirmar = true) {
    if (confirmar) {
        const r = await Swal.fire({
            icon: 'question',
            title: '¿Limpiar archivos?',
            text: 'Se eliminarán del servidor los archivos cargados y el resultado. Para otro cálculo vuelve a subirlos.',
            showCancelButton: true,
            confirmButtonText: 'Sí, limpiar',
            cancelButtonText: 'Cancelar',
        });

        if (!r.isConfirmed) {
            return;
        }
    }

    if (token.value) {
        await fetch(`${BASE}/${token.value}/limpiar`, {
            method: 'POST',
            headers: xsrfHeaders(),
            keepalive: true,
        }).catch(() => undefined);
    }

    token.value = null;
    resumen.value = null;
    resultado.value = null;
    rechazados.value = [];
}

async function calcular() {
    if (!token.value || !corte.value) {
        return;
    }

    calculando.value = true;

    try {
        const res = await fetch(`${BASE}/${token.value}/calcular`, {
            method: 'POST',
            headers: { ...xsrfHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ modo: modo.value, valor: corte.value }),
        });

        if (!res.ok) {
            void Swal.fire({
                icon: 'warning',
                title: 'No se pudo calcular',
                text: await mensajeError(res),
            });

            return;
        }

        resultado.value = (
            (await res.json()) as { resultado: Resultado }
        ).resultado;
        filas.value = resultado.value.filas;
        sucursal.value = '';
        oficina.value = '';
        promotor.value = '';
        toast.fire({ icon: 'success', title: 'Efectividad calculada' });
    } catch {
        void Swal.fire({
            icon: 'error',
            title: 'Sin conexión',
            text: 'No se pudo contactar al servidor.',
        });
    } finally {
        calculando.value = false;
    }
}

function filtrosQuery(): string {
    const q = new URLSearchParams();

    if (sucursal.value) {
        q.set('sucursal', sucursal.value);
    }

    if (oficina.value) {
        q.set('oficina', oficina.value);
    }

    if (promotor.value) {
        q.set('promotor', promotor.value);
    }

    return q.toString();
}

async function cargarVista() {
    if (!token.value || !resultado.value) {
        return;
    }

    if (!sucursal.value && !oficina.value && !promotor.value) {
        filas.value = resultado.value.filas;

        return;
    }

    const res = await fetch(`${BASE}/${token.value}/vista?${filtrosQuery()}`, {
        headers: xsrfHeaders(),
    });

    if (res.ok) {
        filas.value = (
            (await res.json()) as { filas: FilaEfectividad[] }
        ).filas;
    }
}

async function exportar(formato: 'pdf' | 'png') {
    if (!token.value) {
        return;
    }

    exportando.value = formato;

    try {
        const res = await fetch(
            `${BASE}/${token.value}/exportar/${formato}?${filtrosQuery()}`,
            { headers: xsrfHeaders() },
        );

        if (!res.ok) {
            void Swal.fire({
                icon: 'error',
                title: 'No se pudo generar el archivo',
                text: await mensajeError(res),
            });

            return;
        }

        const nombre =
            /filename="?([^";]+)"?/.exec(
                res.headers.get('Content-Disposition') ?? '',
            )?.[1] ?? `Efectividad.${formato}`;
        const url = URL.createObjectURL(await res.blob());
        const a = document.createElement('a');
        a.href = url;
        a.download = nombre;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
    } finally {
        exportando.value = null;
    }
}

// Abandono de la página: se borran los temporales del servidor (el TTL cubre cierres bruscos).
function alSalir() {
    if (token.value) {
        void fetch(`${BASE}/${token.value}/limpiar`, {
            method: 'POST',
            headers: xsrfHeaders(),
            keepalive: true,
        }).catch(() => undefined);
    }
}

onMounted(() => window.addEventListener('pagehide', alSalir));
onBeforeUnmount(() => {
    window.removeEventListener('pagehide', alSalir);
    alSalir();
});
</script>

<template>
    <Head title="Efectividad de Cobranza" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Efectividad de Cobranza
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Calcula la efectividad diaria, semanal o mensual utilizando tus
                archivos de cartera e ingresos de Lendus. Los archivos no se
                guardan: se usan para este cálculo y se eliminan.
            </p>
        </header>

        <!-- 1. Modalidad -->
        <Card>
            <CardContent class="space-y-3 p-5">
                <h2 class="font-semibold">
                    <span class="text-primary">1.</span> Modalidad
                </h2>
                <div
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
                    role="radiogroup"
                    aria-label="Modalidad"
                >
                    <button
                        v-for="m in MODOS"
                        :key="m.valor"
                        type="button"
                        role="radio"
                        :aria-checked="modo === m.valor"
                        class="flex gap-3 rounded-xl border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                        :class="
                            modo === m.valor
                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                : 'hover:bg-muted/40'
                        "
                        @click="modo = m.valor"
                    >
                        <component
                            :is="m.icono"
                            class="mt-0.5 size-5 shrink-0"
                            :class="
                                modo === m.valor
                                    ? 'text-primary'
                                    : 'text-muted-foreground'
                            "
                        />
                        <span>
                            <span class="block font-medium">{{
                                m.titulo
                            }}</span>
                            <span
                                class="mt-0.5 block text-xs text-muted-foreground"
                                >{{ m.texto }}</span
                            >
                        </span>
                    </button>
                </div>
            </CardContent>
        </Card>

        <!-- 2-3. Archivos y resumen -->
        <Card>
            <CardContent class="space-y-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">
                        <span class="text-primary">2.</span> Cargar archivos
                    </h2>
                    <Button
                        v-if="hayArchivos"
                        variant="outline"
                        size="sm"
                        :disabled="ocupado"
                        @click="limpiar()"
                    >
                        <RotateCcw class="size-4" /> Limpiar archivos
                    </Button>
                </div>
                <ArchivosDropzone
                    :subiendo="subiendo"
                    :progreso="progreso"
                    :max-mb="props.limites.max_mb"
                    @archivos="subir"
                />
                <div class="space-y-3">
                    <div v-if="opcionesCorte.length" class="md:w-[28rem]">
                        <SelectField
                            v-model="corte"
                            :label="
                                modo === 'MENSUAL'
                                    ? 'Mes'
                                    : modo === 'SEMANAL'
                                      ? 'Semana'
                                      : modo === 'ACUMULADO'
                                        ? 'Acumulado hasta'
                                        : 'Día de corte'
                            "
                            :options="
                                opcionesCorte.map((o) => ({
                                    value: o.valor,
                                    label: `${o.etiqueta} — ${ESTADO_CORTE[o.estado]}`,
                                }))
                            "
                            :disabled="ocupado"
                        />
                    </div>
                    <RequisitosCorte :modo="modo" :opcion="opcionCorte" />
                </div>
                <ResumenFuentes
                    v-if="resumen && hayArchivos"
                    :resumen="resumen"
                    :rechazados="rechazados"
                    :ocupado="ocupado"
                    :inicio-periodo="opcionCorte?.desde ?? null"
                    @quitar="quitar"
                />
                <ResumenFuentes
                    v-else-if="rechazados.length"
                    :resumen="{
                        carteras: [],
                        ingresos: [],
                        cortes: {
                            DIARIO: [],
                            ACUMULADO: [],
                            SEMANAL: [],
                            MENSUAL: [],
                        },
                        expira_minutos: 0,
                    }"
                    :rechazados="rechazados"
                    :ocupado="ocupado"
                />
            </CardContent>
        </Card>

        <!-- 4-5. Corte y cálculo -->
        <Card>
            <CardContent class="space-y-4 p-5">
                <h2 class="font-semibold">
                    <span class="text-primary">3.</span> Calcular
                </h2>
                <div class="flex flex-col gap-3 md:flex-row md:items-center">
                    <Button
                        size="lg"
                        :disabled="!!faltante || !opcionCorte?.listo || ocupado"
                        @click="calcular"
                    >
                        <Loader2
                            v-if="calculando"
                            class="size-4 animate-spin"
                        />
                        <Calculator v-else class="size-4" />
                        {{
                            calculando ? 'Calculando…' : 'Calcular efectividad'
                        }}
                    </Button>
                    <p
                        class="text-sm"
                        :class="
                            faltante
                                ? 'text-muted-foreground'
                                : 'text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        {{ faltante ?? opcionCorte?.titulo }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <!-- 6-7. Resultados y exportación -->
        <Card v-if="resultado">
            <CardContent class="space-y-5 p-5">
                <div
                    class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="grid gap-3 sm:grid-cols-3 lg:w-full lg:max-w-3xl">
                        <SelectField
                            v-model="sucursal"
                            label="Sucursal"
                            :options="opcionesSucursal"
                            :disabled="ocupado"
                        />
                        <SelectField
                            v-model="oficina"
                            label="Ruta"
                            :options="opcionesOficina"
                            :disabled="ocupado"
                        />
                        <SelectField
                            v-model="promotor"
                            label="Promotor"
                            :options="opcionesPromotor"
                            :disabled="ocupado"
                        />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            :disabled="ocupado"
                            @click="exportar('png')"
                        >
                            <Loader2
                                v-if="exportando === 'png'"
                                class="size-4 animate-spin"
                            />
                            <FileImage v-else class="size-4" />
                            Descargar imagen PNG
                        </Button>
                        <Button :disabled="ocupado" @click="exportar('pdf')">
                            <Loader2
                                v-if="exportando === 'pdf'"
                                class="size-4 animate-spin"
                            />
                            <FileText v-else class="size-4" />
                            Descargar PDF
                        </Button>
                    </div>
                </div>
                <ResultadoEfectividad :resultado="resultado" :filas="filas" />
                <p
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Download class="size-3.5" /> PNG y PDF usan exactamente
                    este mismo resultado. Generado {{ resultado.generado }}.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
