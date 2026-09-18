<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import {
    BookOpen, Download, Upload, RefreshCw, Play, Eye,
    FileSpreadsheet, FileText, History, CheckCircle2, Clock, AlertTriangle,
    Layers3, TrendingUp, TrendingDown, PiggyBank, Users, Percent,
    HelpCircle, Lightbulb, GitCompareArrows, ChevronRight, ChevronLeft, CalendarPlus,
    FolderOpen, ListChecks, Sliders, Building2, UserRound,
    Sparkles, FileCheck2, Filter, Search, Ban, Check,
} from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import GuideMockup from '@/components/guide/GuideMockup.vue'
import GuideStepList from '@/components/guide/GuideStepList.vue'
import GuideButtonChip from '@/components/guide/GuideButtonChip.vue'

defineOptions({ layout: AppLayout })

// Guía rediseñada como WIZARD paso a paso (cierre 17-sep-2026, ronda 5) — reemplaza la
// versión anterior de scroll largo con ancla de tabla de contenido (el usuario pidió
// explícitamente quitarla: "la guía actual no me ayuda mucho"). Mismo contenido
// validado de cada módulo — nunca se reescribió el texto, solo la forma en que se
// recorre: un paso a la vez, con Anterior/Siguiente y un stepper con progreso.
const steps = [
    { id: 's1', label: 'Bienvenida', icon: Sparkles },
    { id: 's2', label: 'Flujo general', icon: Layers3 },
    { id: 's3', label: 'Alta de periodo', icon: CalendarPlus },
    { id: 's4', label: 'Carga de archivos', icon: Upload },
    { id: 's5', label: 'Carga de registros', icon: History },
    { id: 's6', label: 'Incidencias', icon: AlertTriangle },
    { id: 's7', label: 'Configurar reporte', icon: Sliders },
    { id: 's8', label: 'Generar reporte', icon: Play },
    { id: 's9', label: 'Vista previa', icon: Eye },
    { id: 's10', label: 'Exportar Excel / PDF', icon: Download },
    { id: 's11', label: 'Reportes generados', icon: FolderOpen },
    { id: 's12', label: 'Reportes mensuales', icon: FileSpreadsheet },
    { id: 's13', label: 'Bimestres y trimestres', icon: Layers3 },
    { id: 's14', label: 'Comparativos', icon: GitCompareArrows },
    { id: 's15', label: 'Por sucursal', icon: Building2 },
    { id: 's16', label: 'Por empleado / gestor', icon: UserRound },
    { id: 's17', label: 'Interpretar KPIs', icon: TrendingUp },
    { id: 's18', label: 'Errores comunes', icon: Ban },
    { id: 's19', label: 'Buenas prácticas', icon: Lightbulb },
]

const currentIndex = ref(0)
const direction = ref<'forward' | 'back'>('forward')
const current = computed(() => steps[currentIndex.value])
const progressPct = computed(() => Math.round(((currentIndex.value + 1) / steps.length) * 100))
const isFirst = computed(() => currentIndex.value === 0)
const isLast = computed(() => currentIndex.value === steps.length - 1)

function goTo(index: number) {
    direction.value = index > currentIndex.value ? 'forward' : 'back'
    currentIndex.value = index
    window.scrollTo({ top: 0, behavior: 'smooth' })
}
function next() { if (!isLast.value) goTo(currentIndex.value + 1) }
function prev() { if (!isFirst.value) goTo(currentIndex.value - 1) }
</script>

<template>
    <Head title="Guía del sistema" />

    <div class="mx-auto max-w-screen-2xl space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Hero -->
        <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300 sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-lg shadow-indigo-900/40">
                        <BookOpen class="size-6 text-white" />
                    </div>
                    <div>
                        <p class="text-xs font-black tracking-[0.28em] text-indigo-300 uppercase">Guía del sistema</p>
                        <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">Manual de uso — Radiografía Financiera</h1>
                    </div>
                </div>
                <a
                    href="/guia-sistema/pdf"
                    class="inline-flex h-11 items-center gap-2 rounded-2xl bg-emerald-500 px-5 text-sm font-black text-white transition duration-200 hover:-translate-y-0.5 hover:bg-emerald-400 hover:shadow-lg hover:shadow-emerald-500/30"
                >
                    <Download class="size-4" /> Descargar guía en PDF
                </a>
            </div>

            <!-- Progreso -->
            <div class="relative mt-6">
                <div class="flex items-center justify-between text-xs font-bold text-slate-400">
                    <span>Paso {{ currentIndex + 1 }} de {{ steps.length }}</span>
                    <span>{{ progressPct }}%</span>
                </div>
                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-emerald-400 transition-all duration-500 ease-out" :style="{ width: `${progressPct}%` }" />
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
            <!-- Stepper -->
            <nav class="app-card sticky top-6 h-fit space-y-1 p-3">
                <p class="mb-2 px-2 text-xs font-black tracking-wider text-slate-400 uppercase">Módulos</p>
                <button
                    v-for="(s, i) in steps"
                    :key="s.id"
                    type="button"
                    class="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition duration-150"
                    :class="i === currentIndex
                        ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20'
                        : i < currentIndex
                            ? 'text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10'
                            : 'text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100'"
                    @click="goTo(i)"
                >
                    <span
                        class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-black"
                        :class="i === currentIndex ? 'bg-white/20 text-white' : i < currentIndex ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-200 text-slate-500 dark:bg-slate-700 dark:text-slate-300'"
                    >
                        <Check v-if="i < currentIndex" class="size-3" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <component :is="s.icon" class="size-3.5 shrink-0" :class="i === currentIndex ? 'text-white' : 'text-slate-400'" />
                    <span class="truncate">{{ s.label }}</span>
                </button>
            </nav>

            <!-- Contenido del paso actual -->
            <div class="min-w-0 space-y-5">
                <Transition :name="direction === 'forward' ? 'guide-forward' : 'guide-back'" mode="out-in">
                    <div :key="current.id" class="app-card space-y-5 p-6 sm:p-8">
                        <div class="flex items-center gap-3">
                            <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300"><component :is="current.icon" class="size-5" /></div>
                            <h2 class="text-xl font-black text-slate-950 dark:text-slate-50">{{ currentIndex + 1 }}. {{ current.label }}</h2>
                        </div>

                        <!-- 1. Bienvenida -->
                        <template v-if="current.id === 's1'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Este sistema arma, mes con mes, el reporte financiero completo del negocio: cuánto se
                                recuperó de cartera, cuánto se colocó en créditos nuevos, cuánto se gastó, cuánto se pagó
                                de nómina, y cuál fue la utilidad del negocio — en general y por cada sucursal.
                            </p>
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Esta guía te acompaña paso a paso: crear el periodo, cargar los archivos, revisar que todo
                                esté correcto, generar el reporte y consultarlo cuando lo necesites. Usa
                                <strong>Siguiente</strong> para avanzar, o entra directo al módulo que te interese desde el
                                menú de la izquierda.
                            </p>
                        </template>

                        <!-- 2. Flujo general -->
                        <template v-else-if="current.id === 's2'">
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div v-for="(step, i) in [
                                    { icon: CalendarPlus, t: 'Crear periodo', d: 'Das de alta el mes, bimestre o trimestre que vas a trabajar.' },
                                    { icon: Upload, t: 'Cargar archivos', d: 'Subes los archivos fuente de ese periodo.' },
                                    { icon: Sliders, t: 'Configurar y generar', d: 'Eliges tipo y alcance, y generas el reporte.' },
                                    { icon: Eye, t: 'Revisar y descargar', d: 'Consultas la vista previa y descargas Excel/PDF.' },
                                ]" :key="i" class="rounded-2xl border border-slate-200 p-4 transition duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md dark:border-slate-800 dark:hover:border-indigo-800">
                                    <div class="flex size-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">
                                        <component :is="step.icon" class="size-4.5" />
                                    </div>
                                    <p class="mt-3 text-sm font-black text-slate-900 dark:text-slate-50">{{ i + 1 }}. {{ step.t }}</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ step.d }}</p>
                                </div>
                            </div>
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Una vez generado, el reporte queda guardado — puedes consultarlo y descargarlo cuantas
                                veces quieras desde <strong>Reportes mensuales</strong>, sin volver a generarlo.
                            </p>
                        </template>

                        <!-- 3. Alta de periodo -->
                        <template v-else-if="current.id === 's3'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Primero se crea el periodo operativo que se va a trabajar. Este periodo define las fechas
                                y semanas que tomará en cuenta la radiografía.
                            </p>
                            <div class="grid gap-5 lg:grid-cols-2">
                                <GuideStepList :steps="[
                                    'Entra al módulo Periodos.',
                                    'Presiona el botón para crear un nuevo periodo.',
                                    'Selecciona el tipo de periodo: mensual, bimestral o trimestral.',
                                    'Elige el mes (o el bimestre/trimestre correspondiente).',
                                    'Revisa las fechas de inicio y fin que se muestran.',
                                    'Confirma que las semanas que abarca sean las correctas.',
                                    'Guarda el periodo.',
                                    'Verifica que el periodo aparezca en la lista como creado.',
                                ]" />
                                <GuideMockup title="Periodos">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-black text-slate-700">Periodos</span>
                                        <span class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[11px] font-black text-white">+ Crear periodo</span>
                                    </div>
                                    <div class="mt-3 flex gap-2">
                                        <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-[10px] font-black text-indigo-700">Mensual</span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">Bimestral</span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">Trimestral</span>
                                    </div>
                                    <div class="mt-3 space-y-2">
                                        <div v-for="i in 3" :key="i" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-2">
                                            <span class="text-[11px] font-bold text-slate-700">Junio 2026</span>
                                            <span class="text-[10px] text-slate-400">01/06 → 30/06</span>
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-600">Creado</span>
                                        </div>
                                    </div>
                                </GuideMockup>
                            </div>
                        </template>

                        <!-- 4. Carga de archivos -->
                        <template v-else-if="current.id === 's4'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Con el periodo mensual creado, se cargan los archivos fuente de ese mes: nómina, cobranza,
                                colocación, cartera y gastos. El sistema muestra una tarjeta por cada archivo que se
                                necesita para ese periodo.
                            </p>
                            <div class="grid gap-5 lg:grid-cols-2">
                                <GuideStepList :steps="[
                                    'Entra al periodo mensual que vas a trabajar.',
                                    'Revisa la lista de archivos que pide el sistema.',
                                    'Selecciona o arrastra el archivo correspondiente a cada tarjeta.',
                                    'Verifica que el archivo quede marcado como cargado.',
                                    'Si subiste el archivo incorrecto, usa Reemplazar archivo.',
                                    'Si subiste un archivo por error, usa Eliminar archivo.',
                                ]" />
                                <GuideMockup title="Archivos y periodo">
                                    <div class="grid grid-cols-2 gap-2">
                                        <div v-for="i in 4" :key="i" class="rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center">
                                            <FileCheck2 v-if="i % 2 === 0" class="mx-auto size-4 text-emerald-500" />
                                            <Upload v-else class="mx-auto size-4 text-slate-400" />
                                            <p class="mt-1 text-[10px] font-bold text-slate-600">{{ i % 2 === 0 ? 'Cargado' : 'Pendiente' }}</p>
                                        </div>
                                    </div>
                                </GuideMockup>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <GuideButtonChip name="Seleccionar archivo" desc="Abre el explorador de tu computadora para elegir el archivo." />
                                <GuideButtonChip name="Arrastrar archivo" desc="Suelta el archivo directamente sobre la tarjeta." />
                                <GuideButtonChip name="Reemplazar archivo" desc="Sustituye el archivo ya cargado por uno nuevo." />
                                <GuideButtonChip name="Eliminar archivo" desc="Borra el archivo cargado (pide confirmación)." />
                            </div>
                        </template>

                        <!-- 5. Carga de registros -->
                        <template v-else-if="current.id === 's5'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Después de cargar los archivos, el sistema lee su información y la prepara para el
                                reporte. Puedes cerrar la ventana mientras procesa; al terminar te muestra si todo salió
                                correcto o si hay algo que revisar.
                            </p>
                            <div class="grid gap-5 lg:grid-cols-2">
                                <GuideStepList :steps="[
                                    'Con los archivos cargados, presiona Cargar registros.',
                                    'Espera a que el estado cambie de Procesando a Completado.',
                                    'Si algo falla, usa Reintentar carga.',
                                    'Usa Refrescar si quieres confirmar el estado más reciente.',
                                ]" />
                                <GuideMockup title="Estado de carga">
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                            <Clock class="size-4 text-amber-500" /><span class="text-[11px] font-bold text-slate-600">Pendiente</span>
                                        </div>
                                        <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                            <RefreshCw class="size-4 text-indigo-500" /><span class="text-[11px] font-bold text-slate-600">Procesando…</span>
                                        </div>
                                        <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                            <CheckCircle2 class="size-4 text-emerald-500" /><span class="text-[11px] font-bold text-slate-600">Completado</span>
                                        </div>
                                    </div>
                                </GuideMockup>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <GuideButtonChip name="Cargar registros" desc="Procesa los archivos cargados." />
                                <GuideButtonChip name="Reintentar carga" desc="Vuelve a intentar si algo falló." />
                                <GuideButtonChip name="Refrescar" desc="Consulta el estado más reciente." />
                            </div>
                        </template>

                        <!-- 6. Incidencias -->
                        <template v-else-if="current.id === 's6'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Una incidencia es una advertencia: el sistema detectó algo que no pudo resolver solo, por
                                ejemplo una persona sin sucursal asignada o un gasto sin identificar. No todas las
                                incidencias bloquean el reporte, pero conviene revisarlas antes de generar.
                            </p>
                            <GuideStepList :steps="[
                                'Abre el panel de incidencias del periodo.',
                                'Si hay personas sin sucursal, asígnalas desde el mismo panel.',
                                'Si hay gastos sin asignar, indica a qué sucursal o colaborador corresponden.',
                                'Usa Ver detalle para entender exactamente qué encontró el sistema.',
                                'Marca la incidencia como resuelta cuando la hayas corregido.',
                                'Usa Refrescar para confirmar que ya no aparece.',
                            ]" />
                            <div class="grid gap-2 sm:grid-cols-3">
                                <GuideButtonChip name="Ver detalle" desc="Muestra la información completa de la incidencia." />
                                <GuideButtonChip name="Resolver" desc="Aplica la corrección indicada." />
                                <GuideButtonChip name="Refrescar" desc="Actualiza la lista de incidencias." />
                            </div>
                        </template>

                        <!-- 7. Configurar reporte -->
                        <template v-else-if="current.id === 's7'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">Elige el tipo de reporte y qué tanto vas a revisar (alcance).</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                    <p class="text-xs font-black tracking-wide text-indigo-600 uppercase dark:text-indigo-400">Tipos de reporte</p>
                                    <ul class="mt-2 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                                        <li><strong>Radiografía simple</strong> — para ver un periodo individual.</li>
                                        <li><strong>Comparativo mes vs mes</strong> — para comparar un mes contra otro.</li>
                                        <li><strong>Comparativo bimestre vs bimestre</strong> — compara dos bimestres.</li>
                                        <li><strong>Comparativo trimestre vs trimestre</strong> — compara dos trimestres.</li>
                                    </ul>
                                </div>
                                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                    <p class="text-xs font-black tracking-wide text-indigo-600 uppercase dark:text-indigo-400">Alcances</p>
                                    <ul class="mt-2 space-y-2 text-sm text-slate-600 dark:text-slate-300">
                                        <li><strong>General</strong> — todas las sucursales juntas.</li>
                                        <li><strong>Por sucursal</strong> — para revisar una sucursal específica.</li>
                                        <li><strong>Por empleado / gestor</strong> — para revisar desempeño individual.</li>
                                    </ul>
                                </div>
                            </div>
                            <GuideMockup title="Configurar reporte">
                                <p class="text-[11px] font-black text-slate-500">TIPO DE REPORTE</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    <span class="rounded-xl bg-indigo-600 px-3 py-1.5 text-[10px] font-black text-white">Radiografía simple</span>
                                    <span class="rounded-xl bg-white px-3 py-1.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Comparativo mes vs mes</span>
                                </div>
                                <p class="mt-3 text-[11px] font-black text-slate-500">ALCANCE</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    <span class="rounded-xl bg-indigo-600 px-3 py-1.5 text-[10px] font-black text-white">General</span>
                                    <span class="rounded-xl bg-white px-3 py-1.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Por sucursal</span>
                                    <span class="rounded-xl bg-white px-3 py-1.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Por empleado / gestor</span>
                                </div>
                            </GuideMockup>
                        </template>

                        <!-- 8. Generar reporte -->
                        <template v-else-if="current.id === 's8'">
                            <div class="grid gap-5 lg:grid-cols-2">
                                <GuideStepList :steps="[
                                    'Revisa que la configuración elegida sea la correcta.',
                                    'Presiona Generar reporte.',
                                    'Espera la confirmación — puedes cerrar la ventana, te avisamos por correo.',
                                    'Cuando termine, se habilitan Vista previa, Excel y PDF.',
                                ]" />
                                <GuideMockup title="Generar reporte">
                                    <div class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2.5">
                                        <CheckCircle2 class="size-4 text-emerald-600" />
                                        <span class="text-[11px] font-black text-emerald-700">Reporte generado</span>
                                    </div>
                                    <div class="mt-2 flex gap-2">
                                        <span class="flex-1 rounded-lg bg-slate-900 px-2 py-1.5 text-center text-[10px] font-black text-white">Ver resultado</span>
                                        <span class="flex-1 rounded-lg bg-white px-2 py-1.5 text-center text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Excel</span>
                                        <span class="flex-1 rounded-lg bg-white px-2 py-1.5 text-center text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">PDF</span>
                                    </div>
                                </GuideMockup>
                            </div>
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Mientras se genera, el reporte pasa por los estados <strong>En cola</strong> →
                                <strong>Procesando</strong> → <strong>Generado</strong>. Si algo realmente falla, verás
                                <strong>Error</strong> con el motivo. Si el reporte terminó bien, siempre verás
                                "Generado" — no debe aparecer un error si el resultado ya está disponible.
                            </p>
                        </template>

                        <!-- 9. Vista previa -->
                        <template v-else-if="current.id === 's9'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                La vista previa muestra el reporte completo dentro del sistema, organizado en pestañas:
                                resumen general, sucursales, ingresos, gastos, nómina, cartera y mora, rotación de
                                personal y EBITDA. Cada pestaña tiene sus propios filtros para revisar el detalle.
                            </p>
                            <GuideMockup title="Vista previa">
                                <div class="flex gap-2 overflow-hidden text-[10px] font-bold">
                                    <span class="rounded-lg bg-indigo-600 px-2.5 py-1 text-white">Resumen</span>
                                    <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Sucursales</span>
                                    <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Nómina</span>
                                    <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Mora</span>
                                    <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Rotación</span>
                                </div>
                                <div class="mt-3 grid grid-cols-3 gap-2">
                                    <div v-for="i in 3" :key="i" class="rounded-xl border border-slate-200 bg-white p-2 text-center">
                                        <p class="text-[9px] font-bold text-slate-400">KPI</p>
                                        <p class="text-[11px] font-black text-slate-800">$ · · ·</p>
                                    </div>
                                </div>
                            </GuideMockup>
                        </template>

                        <!-- 10. Exportación -->
                        <template v-else-if="current.id === 's10'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Desde la vista previa o desde Reportes generados puedes descargar el reporte completo.
                            </p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <GuideButtonChip name="Excel" desc="Ideal para analizar el detalle, hacer filtros propios o revisar hoja por hoja." />
                                <GuideButtonChip name="PDF" desc="Ideal para compartir o imprimir un resumen ejecutivo ya formateado." />
                            </div>
                        </template>

                        <!-- 11. Reportes generados -->
                        <template v-else-if="current.id === 's11'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Aquí se listan todos los reportes que ya generaste, con buscador y filtros por estado.
                                Este módulo es solo para consultar reportes existentes.
                            </p>
                            <GuideStepList :steps="[
                                'Usa el buscador para encontrar un reporte por nombre o periodo.',
                                'Usa los filtros de estado si quieres ver solo generados, con error, etc.',
                                'Presiona Ver para abrir la vista previa.',
                                'Presiona Excel o PDF para descargarlo directamente.',
                            ]" />
                            <GuideMockup title="Reportes generados">
                                <div class="flex items-center gap-2">
                                    <div class="flex flex-1 items-center gap-1.5 rounded-lg bg-white px-2.5 py-1.5 ring-1 ring-slate-200">
                                        <Search class="size-3 text-slate-400" /><span class="text-[10px] text-slate-400">Buscar reporte…</span>
                                    </div>
                                    <Filter class="size-3.5 text-slate-400" />
                                </div>
                                <div class="mt-2 space-y-1.5">
                                    <div v-for="i in 2" :key="i" class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-2.5 py-1.5">
                                        <span class="text-[10px] font-bold text-slate-700">Radiografía Junio 2026</span>
                                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-black text-emerald-600">Generado</span>
                                    </div>
                                </div>
                            </GuideMockup>
                        </template>

                        <!-- 12. Reportes mensuales -->
                        <template v-else-if="current.id === 's12'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Un reporte mensual es la radiografía de un solo mes. Es el punto de partida: los
                                bimestres y trimestres se arman a partir de los meses ya generados.
                            </p>
                        </template>

                        <!-- 13. Bimestres y trimestres -->
                        <template v-else-if="current.id === 's13'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Un bimestre o trimestre se arma automáticamente con sus meses operativos. Antes de poder
                                generarlo, deben existir los reportes mensuales de todos los meses que lo componen — si
                                falta alguno, el sistema te indica claramente cuál falta.
                            </p>
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                El reporte resultante muestra el rango completo del periodo, incluyendo qué meses abarca:
                            </p>
                            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 font-mono text-xs leading-6 text-indigo-800 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-300">
                                Trimestre 2 - 2026<br>
                                Abril 2026 + Mayo 2026 + Junio 2026<br>
                                Rango: 2026-03-30 → 2026-06-21
                            </div>
                        </template>

                        <!-- 14. Comparativos -->
                        <template v-else-if="current.id === 's14'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Un comparativo pone lado a lado dos periodos del mismo tipo (mes vs mes, bimestre vs
                                bimestre o trimestre vs trimestre) para ver qué tanto creció o bajó cada indicador.
                            </p>
                            <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800">
                                <table class="w-full text-xs">
                                    <thead class="bg-slate-50 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                                        <tr><th class="px-3 py-2 text-left font-black">Métrica</th><th class="px-3 py-2 text-right font-black">Mayo 2026</th><th class="px-3 py-2 text-right font-black">Junio 2026</th><th class="px-3 py-2 text-right font-black">Diferencia</th><th class="px-3 py-2 text-right font-black">Variación %</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr class="border-t dark:border-slate-800"><td class="px-3 py-2 font-bold text-slate-700 dark:text-slate-200">Recuperación</td><td class="px-3 py-2 text-right text-slate-500 dark:text-slate-400">$17,697,872</td><td class="px-3 py-2 text-right text-slate-500 dark:text-slate-400">$17,888,527</td><td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">+$190,655</td><td class="px-3 py-2 text-right font-black text-emerald-600 dark:text-emerald-400">+1.08%</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Verde significa que la métrica subió; rojo, que bajó. Sirve para ver el crecimiento o la
                                disminución del negocio entre un periodo y otro.
                            </p>
                        </template>

                        <!-- 15. Por sucursal -->
                        <template v-else-if="current.id === 's15'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Al elegir alcance <strong>Por sucursal</strong> en Configurar reporte, el reporte trae
                                únicamente la información de esa sucursal: su recuperación, colocación, cartera, mora,
                                gastos, nómina y utilidad. Útil para revisar el desempeño de una sucursal en particular
                                sin mezclarlo con las demás.
                            </p>
                        </template>

                        <!-- 16. Por empleado -->
                        <template v-else-if="current.id === 's16'">
                            <p class="text-sm leading-7 text-slate-600 dark:text-slate-300">
                                Al elegir alcance <strong>Por empleado / gestor</strong>, el reporte trae únicamente la
                                información de esa persona: su recuperación, colocación, cartera asignada y utilidad
                                generada. Útil para revisar el desempeño individual de un gestor.
                            </p>
                        </template>

                        <!-- 17. KPIs -->
                        <template v-else-if="current.id === 's17'">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div v-for="(k, i) in [
                                    { icon: TrendingUp, t: 'Recuperación', d: 'Dinero real cobrado a los clientes en el periodo.' },
                                    { icon: PiggyBank, t: 'Colocación', d: 'Monto total desembolsado en créditos nuevos.' },
                                    { icon: FileSpreadsheet, t: 'Valor cartera', d: 'Saldo total de los créditos activos al cierre del periodo.' },
                                    { icon: TrendingDown, t: 'Cartera vencida', d: 'Parte de la cartera que está atrasada en sus pagos.' },
                                    { icon: Percent, t: 'Mora %', d: 'Cartera vencida entre valor cartera. Entre más bajo, mejor.' },
                                    { icon: FileText, t: 'OPEX', d: 'Gastos operativos del negocio, sin nómina.' },
                                    { icon: Users, t: 'Nómina y Capital Humano', d: 'Todo el gasto relacionado a pago de personal.' },
                                    { icon: TrendingUp, t: 'EBITDA', d: 'La utilidad real del negocio en el periodo.' },
                                    { icon: Percent, t: 'Margen EBITDA', d: 'Qué porcentaje de los ingresos se convierte en utilidad.' },
                                    { icon: ListChecks, t: 'Préstamo activo', d: 'Número de créditos/contratos vigentes en el periodo.' },
                                    { icon: Users, t: 'Percepciones', d: 'Todo lo que se le pagó al personal antes de descuentos.' },
                                    { icon: Users, t: 'Deducciones', d: 'Descuentos aplicados a la nómina, solo informativos.' },
                                    { icon: PiggyBank, t: 'Neto pagado', d: 'Lo que efectivamente recibió el personal.' },
                                    { icon: UserRound, t: 'Rotación de personal', d: 'Qué tanto entra y sale personal de la empresa en el periodo.' },
                                ]" :key="i" class="flex items-start gap-3 rounded-2xl border border-slate-200 p-4 transition hover:border-indigo-200 hover:shadow-sm dark:border-slate-800 dark:hover:border-indigo-800">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                        <component :is="k.icon" class="size-4.5" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-black text-slate-900 dark:text-slate-50">{{ k.t }}</p>
                                        <p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ k.d }}</p>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- 18. Errores comunes -->
                        <template v-else-if="current.id === 's18'">
                            <div class="space-y-3">
                                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                    <p class="flex items-center gap-2 text-sm font-black text-slate-900 dark:text-slate-50"><AlertTriangle class="size-4 text-amber-500" />"Faltan reportes mensuales" al generar un bimestre/trimestre</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Genera primero el reporte del mes o meses que indica el mensaje; después el consolidado se habilita solo.</p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                    <p class="flex items-center gap-2 text-sm font-black text-slate-900 dark:text-slate-50"><AlertTriangle class="size-4 text-amber-500" />Un archivo queda en incidencia</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Revisa el detalle de la incidencia y sigue la acción sugerida — casi siempre se resuelve indicando manualmente el dato faltante.</p>
                                </div>
                                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                                    <p class="flex items-center gap-2 text-sm font-black text-slate-900 dark:text-slate-50"><AlertTriangle class="size-4 text-amber-500" />El reporte tarda en generarse</p>
                                    <p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Es normal para una radiografía mensual — puede tardar varios minutos. Puedes cerrar la ventana; te avisamos por correo cuando esté listo.</p>
                                </div>
                            </div>
                        </template>

                        <!-- 19. Buenas prácticas -->
                        <template v-else-if="current.id === 's19'">
                            <div class="space-y-2">
                                <div v-for="(tip, i) in [
                                    'Carga todos los archivos del mes antes de generar el reporte.',
                                    'Revisa las incidencias antes de generar.',
                                    'Genera primero los reportes mensuales antes de un bimestre o trimestre.',
                                    'Abre Ver resultado antes de compartir un reporte, para confirmar que los números se ven correctos.',
                                    'Si algo se ve raro, compáralo contra el mes anterior con un comparativo.',
                                ]" :key="i" class="flex items-start gap-3 rounded-2xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
                                    <Lightbulb class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    <p class="text-sm leading-6 text-emerald-800 dark:text-emerald-300">{{ tip }}</p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/60">
                                <HelpCircle class="mt-0.5 size-5 shrink-0 text-slate-500 dark:text-slate-400" />
                                <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">
                                    ¿Tienes dudas que esta guía no resuelve? Descarga la versión en PDF para consultarla
                                    sin conexión, o contacta al equipo responsable del sistema.
                                </p>
                            </div>
                        </template>
                    </div>
                </Transition>

                <!-- Controles Anterior / Siguiente -->
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        class="inline-flex h-11 items-center gap-1.5 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-600 shadow-sm transition duration-150 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md disabled:pointer-events-none disabled:opacity-40 dark:border-slate-800 dark:bg-card dark:text-slate-300 dark:hover:border-slate-700"
                        :disabled="isFirst"
                        @click="prev"
                    >
                        <ChevronLeft class="size-4" /> Anterior
                    </button>
                    <button
                        v-if="!isLast"
                        type="button"
                        class="inline-flex h-11 items-center gap-1.5 rounded-2xl bg-indigo-600 px-5 text-sm font-black text-white shadow-lg shadow-indigo-600/20 transition duration-150 hover:-translate-y-0.5 hover:bg-indigo-500 hover:shadow-xl hover:shadow-indigo-600/30"
                        @click="next"
                    >
                        Siguiente <ChevronRight class="size-4" />
                    </button>
                    <a
                        v-else
                        href="/guia-sistema/pdf"
                        class="inline-flex h-11 items-center gap-1.5 rounded-2xl bg-emerald-500 px-5 text-sm font-black text-white shadow-lg shadow-emerald-500/20 transition duration-150 hover:-translate-y-0.5 hover:bg-emerald-400 hover:shadow-xl"
                    >
                        <Download class="size-4" /> Descargar guía completa
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.guide-forward-enter-active,
.guide-forward-leave-active,
.guide-back-enter-active,
.guide-back-leave-active {
    transition: all 0.22s ease;
}
.guide-forward-enter-from { opacity: 0; transform: translateX(16px); }
.guide-forward-leave-to { opacity: 0; transform: translateX(-16px); }
.guide-back-enter-from { opacity: 0; transform: translateX(-16px); }
.guide-back-leave-to { opacity: 0; transform: translateX(16px); }
</style>
