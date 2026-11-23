<script setup lang="ts">
/**
 * Pantalla: CALCULADORA DE EFECTIVIDAD DE COBRANZA (/efectividad-cobranza).
 *
 * Para qué sirve: flujo único y EFÍMERO (sin base de datos) en 4 pasos:
 * 1) elegir modalidad (diario, acumulado, semanal, mensual), 2) subir los
 * Excel de carteras diarias e Ingresos Cobranza de Lendus, 3) elegir el corte
 * y calcular, 4) ver/filtrar el resultado (sucursal, oficina, promotor) y
 * exportarlo a PNG, PDF o Excel. Los archivos viven en un espacio temporal del
 * servidor identificado por un token ligado al usuario y se borran al salir.
 *
 * Origen de los datos: `App\Http\Controllers\EfectividadCalculadoraController@index`
 * renderiza `EfectividadCobranza/Index` solo con `limites` (máx. archivos y MB).
 * (EfectividadCobranzaController también renderiza este nombre de página, pero
 * no tiene rutas registradas: es el flujo anterior con base de datos.)
 * Todo lo demás llega por fetch JSON a las rutas de la calculadora.
 *
 * Rutas que usa (prefijo `{base}/efectividad-cobranza/calculadora`):
 * - POST   /archivos                    subir un archivo (crea el token si no hay)
 * - DELETE /{token}/archivos/{id}       quitar un archivo
 * - POST   /{token}/limpiar             borrar temporales (también al salir)
 * - POST   /{token}/calcular            calcular con { modo, valor }
 * - GET    /{token}/vista?filtros       filas filtradas
 * - GET    /{token}/exportar/{pdf|png}  exportar PDF o imagen
 * - GET    /{token}/exportar-excel      exportar Excel (permiso propio)
 */
import { Head, usePage } from '@inertiajs/vue3';
import {
    Calculator,
    CheckCircle2,
    CircleDashed,
    Download,
    FileImage,
    FileSpreadsheet,
    FileText,
    Loader2,
    RotateCcw,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

import AppPageHeader from '@/components/app/AppPageHeader.vue';
import ArchivosDropzone from '@/components/efectividad/calculadora/ArchivosDropzone.vue';
import ControlTrazabilidad from '@/components/efectividad/calculadora/ControlTrazabilidad.vue';
import FiltrosResultado from '@/components/efectividad/calculadora/FiltrosResultado.vue';
import PasoCalculadora from '@/components/efectividad/calculadora/PasoCalculadora.vue';
import RequisitosCorte from '@/components/efectividad/calculadora/RequisitosCorte.vue';
import ResultadoEfectividad from '@/components/efectividad/calculadora/ResultadoEfectividad.vue';
import ResultadoHero from '@/components/efectividad/calculadora/ResultadoHero.vue';
import ResumenFuentes from '@/components/efectividad/calculadora/ResumenFuentes.vue';
import SelectorModalidad from '@/components/efectividad/calculadora/SelectorModalidad.vue';
import type {
    FilaEfectividad,
    Modo,
    Rechazado,
    Resultado,
    ResumenFuentes as Resumen,
} from '@/components/efectividad/calculadora/types';
import {
    fmtPct,
    mensajeError,
    xsrfHeaders,
} from '@/components/efectividad/calculadora/types';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import SelectField from '@/components/forms/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useEfectividadFiltros } from '@/composables/useEfectividadFiltros';
import {
    hideDownloadLoading,
    showDownloadLoading,
} from '@/lib/downloadOverlay';
import { index as efectividadIndex } from '@/routes/efectividad-cobranza';

/** Props de EfectividadCalculadoraController@index: límites de carga (máximo de archivos y MB por archivo). */
const props = defineProps<{
    limites: { max_archivos: number; max_mb: number };
}>();

// Migas de pan del layout: la pantalla se presenta como "Efectividad de Cobranza".
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Efectividad de Cobranza', href: efectividadIndex() },
        ],
    },
});

const page = usePage();

/**
 * Prefijo de instalación de la app: "/reportes/public" cuando Laravel vive en un
 * subdirectorio (WAMP), "" cuando se sirve en la raíz del dominio (producción nginx).
 * Lo expone el servidor (HandleInertiaRequests → props.base = request()->getBaseUrl()).
 * Se antepone a TODAS las URLs del módulo (subir, calcular, vista, exportar, excel)
 * para que fetch/descarga resuelvan bien en ambos casos sin hardcodear ni depender de
 * reiniciar Apache. En producción (base vacía) el resultado es idéntico al anterior.
 */
const appBase = (page.props as { base?: string }).base ?? '';
/** URL base de todas las peticiones de la calculadora. */
const BASE = `${appBase}/efectividad-cobranza/calculadora`;

/**
 * El botón de Excel se designa por rol (permiso `efectividad.exportar_excel`); solo se
 * muestra a quien lo tiene. El backend vuelve a exigirlo en la ruta, esto es solo UX.
 */
const canExportarExcel = computed(() => {
    const perms = (page.props as { auth?: { permissions?: string[] } }).auth
        ?.permissions;

    return Array.isArray(perms) && perms.includes('efectividad.exportar_excel');
});

/** Modalidad elegida en el paso 1 (por defecto semanal). */
const modo = ref<Modo>('SEMANAL');
/** Token del espacio efímero en el servidor (null hasta subir el primer archivo o tras limpiar/expirar). */
const token = ref<string | null>(null);
/** Resumen de fuentes cargadas (carteras, ingresos y cortes disponibles por modalidad). */
const resumen = ref<Resumen | null>(null);
/** Archivos rechazados (no Excel, inválidos o con error del servidor) con su motivo. */
const rechazados = ref<Rechazado[]>([]);
/** Hay una subida en curso. */
const subiendo = ref(false);
/** Progreso de la subida archivo por archivo (null cuando no se sube). */
const progreso = ref<{ actual: number; total: number; nombre: string } | null>(
    null,
);
/** Valor del corte elegido en el paso 3 (día, semana o mes según la modalidad). */
const corte = ref<string | null>(null);
/** Hay un cálculo en curso. */
const calculando = ref(false);
/** Resultado completo del último cálculo exitoso (null si no hay o se invalidó). */
const resultado = ref<Resultado | null>(null);
/** Filas mostradas en la tabla: las del resultado o las de la vista filtrada. */
const filas = ref<FilaEfectividad[]>([]);
/** Formato que se está exportando (null = ninguno). */
const exportando = ref<'pdf' | 'png' | 'xlsx' | null>(null);
/** Se está pidiendo la vista filtrada al servidor. */
const cargandoVista = ref(false);
/** Identifica cada cálculo exitoso: reinicia la animación de entrada del resultado. */
const calculoId = ref(0);
/** Duración del último cálculo en milisegundos (se muestra en el hero). */
const duracionMs = ref<number | null>(null);
/** Panel "Control y trazabilidad" desplegado. */
const controlAbierto = ref(false);
/** Mensaje para lectores de pantalla al terminar el cálculo. */
const anuncio = ref('');
// Referencias de plantilla para desplazar la vista al resultado y al control.
const seccionResultado = ref<HTMLElement | null>(null);
const seccionControl = ref<HTMLElement | null>(null);

/** Filtros del resultado (sucursal → oficina → promotor) con sus opciones y la query para el servidor. */
const filtros = useEfectividadFiltros(resultado);
const { sucursal, oficina, promotor } = filtros;

/** Hay alguna operación en curso (subir, calcular o exportar): bloquea los controles. */
const ocupado = computed(
    () => subiendo.value || calculando.value || exportando.value !== null,
);
/** Hay al menos una cartera o un ingreso cargado. */
const hayArchivos = computed(
    () =>
        !!resumen.value &&
        resumen.value.carteras.length + resumen.value.ingresos.length > 0,
);
/** Cortes disponibles para la modalidad actual, según los archivos cargados. */
const opcionesCorte = computed(() => resumen.value?.cortes[modo.value] ?? []);
/** Opción de corte actualmente elegida (null si no existe en la lista). */
const opcionCorte = computed(
    () => opcionesCorte.value.find((o) => o.valor === corte.value) ?? null,
);
/**
 * Qué falta para poder calcular (texto para el usuario) o null si está todo.
 * El botón solo se habilita con TODAS las fuentes del corte elegido (carteras e ingresos).
 */
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
/** Se puede calcular: no falta nada, el corte está listo y no hay otra operación en curso. */
const puedeCalcular = computed(
    () => !faltante.value && !!opcionCorte.value?.listo && !ocupado.value,
);
/** Etiqueta del selector de corte según la modalidad. */
const etiquetaCorte = computed(
    () =>
        ({
            MENSUAL: 'Mes',
            SEMANAL: 'Semana',
            ACUMULADO: 'Acumulado hasta',
            DIARIO: 'Día de corte',
        })[modo.value],
);

/** Texto legible de cada estado de corte que devuelve el servidor. */
const ESTADO_CORTE: Record<string, string> = {
    listo: 'listo para calcular',
    faltan_ingresos: 'carteras completas · faltan ingresos',
    faltan_carteras: 'faltan carteras',
};
/** Opciones del selector de corte: etiqueta + estado (listo / faltan ingresos / faltan carteras). */
const opcionesSelectCorte = computed(() =>
    opcionesCorte.value.map((o) => ({
        value: o.valor,
        label: `${o.etiqueta} — ${ESTADO_CORTE[o.estado]}`,
    })),
);

/** Estado visual del paso 2 (completo cuando ya hay archivos). */
const estadoPasoArchivos = computed(() =>
    hayArchivos.value ? 'completo' : 'activo',
);
/** Estado visual del paso 3: completo con resultado, activo con archivos, pendiente sin ellos. */
const estadoPasoCalcular = computed(() => {
    if (resultado.value) {
        return 'completo';
    }

    return hayArchivos.value ? 'activo' : 'pendiente';
});

/**
 * Al cambiar de modalidad o de archivos se conserva lo cargado y se elige el corte
 * listo más reciente (o el más reciente si ninguno está listo). Solo cambia la
 * selección si la actual ya no existe, o si no está lista habiendo otra lista.
 */
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

/** Cada cambio de filtro (sucursal, oficina, promotor) recarga la vista filtrada. */
watch([sucursal, oficina, promotor], () => void cargarVista());

/**
 * Sube los archivos elegidos, UNO por petición (progreso real y sin chocar con
 * el límite de tamaño del servidor). Descarta los que no son .xlsx/.xlsm,
 * invalida el resultado anterior, conserva el token devuelto y acumula los
 * rechazos. Si el servidor responde que la sesión expiró, descarta token y resumen.
 */
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
        // Cada archivo tiene su propio try/catch: si uno falla (red, respuesta no JSON),
        // se registra como rechazado y se sigue con los demás en lugar de cortar el lote.
        for (const [i, archivo] of excel.entries()) {
            progreso.value = {
                actual: i + 1,
                total: excel.length,
                nombre: archivo.name,
            };

            try {
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
            } catch {
                rechazados.value.push({
                    nombre: archivo.name,
                    error: 'Se perdió la conexión con el servidor mientras se subía este archivo.',
                });
            }
        }
    } finally {
        subiendo.value = false;
        progreso.value = null;
    }
}

/** Quita un archivo del espacio efímero, actualiza el resumen e invalida el resultado. */
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

/**
 * Borra en el servidor los archivos y el resultado (POST /limpiar) y reinicia
 * el estado local. Por defecto pide confirmación; los errores de red se ignoran.
 */
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

/** El resultado llegó completo (nunca se anuncia un éxito con una respuesta incompleta). */
function esResultadoValido(r: unknown): r is Resultado {
    const x = r as Partial<Resultado> | null | undefined;

    return (
        !!x &&
        typeof x.titulo === 'string' &&
        Array.isArray(x.filas) &&
        !!x.parametros
    );
}

/** El usuario pidió reducir animaciones (prefers-reduced-motion): el desplazamiento será instantáneo. */
function prefiereMenosMovimiento(): boolean {
    return (
        typeof window !== 'undefined' &&
        !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    );
}

/** Lleva la vista al resultado recién generado y le da el foco (accesible). */
async function mostrarResultado() {
    await nextTick();
    const el = seccionResultado.value;

    if (!el) {
        return;
    }

    el.scrollIntoView({
        behavior: prefiereMenosMovimiento() ? 'auto' : 'smooth',
        block: 'start',
    });
    el.focus({ preventScroll: true });
}

/**
 * Calcula la efectividad del corte elegido (POST /calcular). Valida que la
 * respuesta esté completa antes de anunciar éxito; al terminar limpia filtros,
 * mide la duración, cierra el panel de control, reinicia la animación,
 * prepara el anuncio accesible y lleva la vista al resultado.
 */
async function calcular() {
    if (!token.value || !corte.value) {
        return;
    }

    calculando.value = true;
    const inicio = performance.now();

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

        const body = (await res.json().catch(() => null)) as {
            resultado?: unknown;
        } | null;

        const nuevo = body?.resultado;

        if (!esResultadoValido(nuevo)) {
            void Swal.fire({
                icon: 'error',
                title: 'Respuesta incompleta',
                text: 'El servidor terminó, pero el resultado llegó incompleto. Vuelve a calcular; si se repite, informa a Sistemas.',
            });

            return;
        }

        resultado.value = nuevo;
        filas.value = nuevo.filas;
        filtros.limpiar();
        duracionMs.value = performance.now() - inicio;
        controlAbierto.value = false;
        calculoId.value++;

        const total = nuevo.filas.find((f) => f.tipo === 'gran_total');
        anuncio.value = total
            ? `Reporte generado: ${nuevo.titulo}. Efectividad total ${fmtPct(total.pct_total)}.`
            : `Cálculo terminado: ${nuevo.titulo}, sin movimientos.`;

        void mostrarResultado();
    } catch {
        void Swal.fire({
            icon: 'error',
            title: 'Sin conexión',
            text: 'No se pudo contactar al servidor. Revisa tu conexión e intenta de nuevo.',
        });
    } finally {
        calculando.value = false;
    }
}

/** Secuencia de peticiones de vista: cada cambio de filtro pide su vista y solo se aplica la respuesta más reciente. */
let vistaSeq = 0;

/**
 * Obtiene las filas filtradas (GET /vista). Sin filtros usa directamente las
 * filas del resultado sin ir al servidor. Descarta respuestas obsoletas.
 */
async function cargarVista() {
    if (!token.value || !resultado.value) {
        return;
    }

    const seq = ++vistaSeq;

    if (!filtros.hayFiltros.value) {
        filas.value = resultado.value.filas;
        cargandoVista.value = false;

        return;
    }

    cargandoVista.value = true;

    try {
        const res = await fetch(
            `${BASE}/${token.value}/vista?${filtros.query()}`,
            { headers: xsrfHeaders() },
        );

        if (seq !== vistaSeq) {
            return;
        }

        if (!res.ok) {
            void Swal.fire({
                icon: 'warning',
                title: 'No se pudo aplicar el filtro',
                text: await mensajeError(res),
            });

            return;
        }

        const body = (await res.json()) as { filas: FilaEfectividad[] };

        if (seq === vistaSeq) {
            filas.value = body.filas;
        }
    } catch {
        if (seq === vistaSeq) {
            void Swal.fire({
                icon: 'error',
                title: 'Sin conexión',
                text: 'No se pudo actualizar la vista filtrada. Intenta de nuevo.',
            });
        }
    } finally {
        if (seq === vistaSeq) {
            cargandoVista.value = false;
        }
    }
}

/** Abre el panel "Control y trazabilidad" y desplaza la vista hasta él. */
async function verControl() {
    controlAbierto.value = true;
    await nextTick();
    seccionControl.value?.scrollIntoView({
        behavior: prefiereMenosMovimiento() ? 'auto' : 'smooth',
        block: 'start',
    });
}

/**
 * Descarga el resultado (con los filtros vigentes) en PDF, PNG o Excel: pide
 * el archivo por fetch, toma el nombre de Content-Disposition y lo descarga
 * con un enlace temporal. Muestra el overlay de descarga mientras tanto.
 */
async function exportar(formato: 'pdf' | 'png' | 'xlsx') {
    if (!token.value) {
        return;
    }

    exportando.value = formato;
    showDownloadLoading(formato === 'pdf' ? 'pdf' : 'archivo');

    try {
        // El Excel tiene ruta/permiso propios; PDF e imagen comparten la ruta `exportar/{formato}`.
        const ruta =
            formato === 'xlsx'
                ? `${BASE}/${token.value}/exportar-excel?${filtros.query()}`
                : `${BASE}/${token.value}/exportar/${formato}?${filtros.query()}`;
        const res = await fetch(ruta, { headers: xsrfHeaders() });

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
    } catch {
        // Fallo de red o al leer el archivo: antes se tragaba en silencio.
        void Swal.fire({
            icon: 'error',
            title: 'No se pudo generar el archivo',
            text: 'Se perdió la conexión con el servidor durante la descarga. Intenta de nuevo.',
        });
    } finally {
        hideDownloadLoading();
        exportando.value = null;
    }
}

/** Abandono de la página: borra los temporales del servidor con keepalive (el TTL cubre cierres bruscos). */
function alSalir() {
    if (token.value) {
        void fetch(`${BASE}/${token.value}/limpiar`, {
            method: 'POST',
            headers: xsrfHeaders(),
            keepalive: true,
        }).catch(() => undefined);
    }
}

// Limpia temporales al cerrar/recargar la pestaña (pagehide) y al salir de la pantalla (desmontaje).
onMounted(() => window.addEventListener('pagehide', alSalir));
onBeforeUnmount(() => {
    window.removeEventListener('pagehide', alSalir);
    alSalir();
});
</script>

<template>
    <Head title="Efectividad de Cobranza" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-6">
        <!-- Encabezado de la calculadora -->
        <AppPageHeader
            kicker="Calculadora"
            title="Efectividad de Cobranza"
            subtitle="Calcula la efectividad diaria, semanal o mensual con tus archivos de cartera e ingresos de Lendus. Los archivos no se guardan: se usan para este cálculo y se eliminan."
        />

        <!-- Paso 1. Modalidad -->
        <PasoCalculadora
            :numero="1"
            titulo="Modalidad"
            descripcion="Elige el tipo de periodo que quieres medir."
            estado="completo"
        >
            <SelectorModalidad v-model="modo" :disabled="calculando" />
        </PasoCalculadora>

        <!-- Paso 2. Archivos -->
        <PasoCalculadora
            :numero="2"
            titulo="Cargar archivos"
            descripcion="Carteras diarias e Ingresos Cobranza en Excel. Puedes agregar más archivos en cualquier momento."
            :estado="estadoPasoArchivos"
        >
            <template v-if="hayArchivos" #acciones>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="ocupado"
                    @click="limpiar()"
                >
                    <RotateCcw class="size-4" /> Limpiar archivos
                </Button>
            </template>
            <ArchivosDropzone
                :subiendo="subiendo"
                :progreso="progreso"
                :max-mb="props.limites.max_mb"
                @archivos="subir"
            />
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
        </PasoCalculadora>

        <!-- Paso 3. Corte y cálculo -->
        <PasoCalculadora
            :numero="3"
            titulo="Elegir corte y calcular"
            descripcion="Revisa que el corte tenga todas sus carteras e ingresos antes de calcular."
            :estado="estadoPasoCalcular"
        >
            <div v-if="opcionesCorte.length" class="md:w-[28rem]">
                <SelectField
                    v-model="corte"
                    :label="etiquetaCorte"
                    :options="opcionesSelectCorte"
                    :disabled="ocupado"
                />
            </div>
            <RequisitosCorte :modo="modo" :opcion="opcionCorte" />
            <div
                class="flex flex-col gap-3 rounded-2xl border bg-muted/30 p-4 md:flex-row md:items-center md:justify-between"
            >
                <p
                    class="flex items-start gap-2 text-sm"
                    :class="
                        calculando
                            ? 'text-foreground'
                            : faltante
                              ? 'text-muted-foreground'
                              : 'text-emerald-700 dark:text-emerald-400'
                    "
                    aria-live="polite"
                >
                    <Loader2
                        v-if="calculando"
                        class="mt-0.5 size-4 shrink-0 animate-spin"
                        aria-hidden="true"
                    />
                    <CircleDashed
                        v-else-if="faltante"
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <CheckCircle2
                        v-else
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{
                        calculando
                            ? 'Procesando carteras e ingresos. Esto puede tardar unos segundos…'
                            : (faltante ?? `Listo: ${opcionCorte?.titulo}`)
                    }}</span>
                </p>
                <Button
                    size="lg"
                    class="shrink-0 rounded-xl"
                    :disabled="!puedeCalcular"
                    @click="calcular"
                >
                    <Loader2 v-if="calculando" class="size-4 animate-spin" />
                    <Calculator v-else class="size-4" />
                    {{
                        calculando
                            ? 'Calculando…'
                            : resultado
                              ? 'Volver a calcular'
                              : 'Calcular efectividad'
                    }}
                </Button>
            </div>
        </PasoCalculadora>

        <!-- Anuncio para lectores de pantalla -->
        <p class="sr-only" role="status" aria-live="polite">{{ anuncio }}</p>

        <!-- Espera del primer cálculo -->
        <div
            v-if="calculando && !resultado"
            class="space-y-4 rounded-2xl border bg-card p-5 shadow-sm md:p-6"
            aria-hidden="true"
        >
            <div class="flex items-center gap-3">
                <Skeleton class="size-10 rounded-full" />
                <div class="flex-1 space-y-2">
                    <Skeleton class="h-3 w-40" />
                    <Skeleton class="h-5 w-72 max-w-full" />
                </div>
            </div>
            <div class="grid gap-3 lg:grid-cols-4">
                <Skeleton v-for="i in 4" :key="i" class="h-24 rounded-2xl" />
            </div>
        </div>

        <!-- Paso 4. Resultado -->
        <section
            v-if="resultado"
            ref="seccionResultado"
            tabindex="-1"
            class="scroll-mt-4 space-y-5 outline-none"
            aria-label="Resultado de efectividad"
        >
            <Transition
                appear
                mode="out-in"
                enter-active-class="transition duration-500 ease-out motion-reduce:transition-none"
                enter-from-class="translate-y-3 scale-[0.99] opacity-0"
                enter-to-class="translate-y-0 scale-100 opacity-100"
            >
                <ResultadoHero
                    :key="calculoId"
                    :resultado="resultado"
                    :duracion-ms="duracionMs"
                    :class="calculando ? 'opacity-60 transition-opacity' : ''"
                    @ver-control="verControl"
                />
            </Transition>

            <!-- Control y trazabilidad del cálculo -->
            <div
                v-if="resultado.auditoria"
                ref="seccionControl"
                class="scroll-mt-4"
            >
                <ControlTrazabilidad
                    v-model:abierto="controlAbierto"
                    :auditoria="resultado.auditoria"
                    :metodologia="resultado.metodologia"
                    :engine-version="resultado.engine_version"
                />
            </div>

            <!-- Filtros del resultado y botones de exportación -->
            <FiltrosResultado
                v-model:sucursal="sucursal"
                v-model:oficina="oficina"
                v-model:promotor="promotor"
                :opciones-sucursal="filtros.opcionesSucursal.value"
                :opciones-oficina="filtros.opcionesOficina.value"
                :opciones-promotor="filtros.opcionesPromotor.value"
                :activos="filtros.activos.value"
                :disabled="ocupado"
                @quitar="filtros.quitar"
                @limpiar="filtros.limpiar"
            >
                <template #acciones>
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
                    <Button
                        v-if="canExportarExcel"
                        variant="outline"
                        :disabled="ocupado"
                        @click="exportar('xlsx')"
                    >
                        <Loader2
                            v-if="exportando === 'xlsx'"
                            class="size-4 animate-spin"
                        />
                        <FileSpreadsheet v-else class="size-4" />
                        Descargar Excel
                    </Button>
                </template>
            </FiltrosResultado>

            <!-- Tabla de efectividad -->
            <ResultadoEfectividad
                :resultado="resultado"
                :filas="filas"
                :filtrado="filtros.hayFiltros.value"
                :cargando="cargandoVista"
            />

            <p
                class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
            >
                <Download class="size-3.5" aria-hidden="true" /> PNG y PDF usan
                exactamente este mismo resultado. Generado
                {{ resultado.generado
                }}<template v-if="resultado.metodologia">
                    · Metodología {{ resultado.metodologia }}</template
                >.
            </p>
        </section>
    </div>
</template>
