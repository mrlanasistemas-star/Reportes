<script setup lang="ts">
/**
 * Última etapa del flujo Histórico general («Descargas»): tarjetas para descargar el
 * Excel y el PDF del reporte y abrir la vista previa completa, con el estado de exportación.
 * Las URLs dependen del alcance configurado: reporte simple general
 * (/reportes-mensuales/{periodo}/radiografia.xlsx|pdf, /preview), run exacto
 * (/reportes-mensuales/runs/{run}/excel|pdf|ver) o exportación filtrada bajo demanda
 * (/reportes-mensuales/{periodo}/export-filtrado.xlsx|pdf?...).
 * Props: `period`, `config` (reportConfig), `identityState` (estado por identidad exacta
 * emitido por GenerateReportStep). Sin emits.
 */
import {
    Download,
    ExternalLink,
    FileSpreadsheet,
    FileText,
} from 'lucide-vue-next';
import { computed } from 'vue';
import SectionHeader from './SectionHeader.vue';
import StatusBadge from './StatusBadge.vue';

/** Props: periodo actual, configuración del reporte y estado resuelto por identidad (opcional). */
const props = defineProps<{ period: any; config?: any; identityState?: any }>();

// PROBLEMA 2/4/5 (auditoría 27-ago-2026): props.period.can_export_radiography/
// radiography_run_id SIEMPRE describen la identidad simple/general (ver
// ReportUploadController::index()) — usarlos para un alcance distinto (sucursal/
// gestor/comparativo) podía habilitar el botón con el run EQUIVOCADO (runId de un
// reporte simple ajeno) o dejarlo bloqueado pese a que ESE alcance sí tiene éxito.
// identityState (emitido por GenerateReportStep vía generationProgress(), resuelto
// por identidad exacta) es la fuente correcta para cualquier alcance — solo se usa
// si su identidad coincide con la configuración actual; si no coincide (o no llegó
// aún) se cae al comportamiento anterior basado en period/config.
/** El `identityState` recibido corresponde exactamente a la configuración actual (tipo, alcance, sucursal, gestor, comparable). */
const identityMatches = computed(() => {
    const s = props.identityState;

    if (!s) {
        return false;
    }

    return (
        s.reportType === (props.config?.report_type ?? 'simple') &&
        s.scope === (props.config?.scope ?? 'general') &&
        (s.branchId ?? null) === (props.config?.branch_id ?? null) &&
        (s.employeeId ?? null) === (props.config?.employee_id ?? null) &&
        (s.comparePeriodId ?? null) ===
            (props.config?.compare_period_id ?? null)
    );
});

/** Se puede exportar: según identityState si coincide; si no, según el periodo (identidad simple/general). */
const canExport = computed(() =>
    identityMatches.value
        ? !!props.identityState.canExport
        : !!props.period?.can_export_radiography,
);
/** Hay una generación en curso. */
const isRunning = computed(() => !!props.period?.radiography_running);

// report_type distinto de 'simple' (comparativo mes/bimestre/trimestre vs vs) TAMBIÉN
// cuenta como "filtrado" — antes solo se miraba scope, así que un comparativo con
// scope=general terminaba usando la ruta plana del reporte simple.
/** El reporte configurado es comparativo (report_type distinto de 'simple'). */
const isComparative = computed(
    () => !!props.config?.report_type && props.config.report_type !== 'simple',
);
/** El reporte no es el simple general (sucursal, gestor o comparativo). */
const isFiltered = computed(
    () =>
        props.config?.scope === 'branch' ||
        props.config?.scope === 'employee' ||
        isComparative.value,
);

/** Query string para la exportación filtrada (scope, report_type, branch_id, employee_id, compare_period_id); vacío si no es filtrado. */
const filteredParams = computed(() => {
    if (!isFiltered.value) {
        return '';
    }

    const p = new URLSearchParams({
        scope: props.config.scope ?? 'general',
        report_type: props.config.report_type ?? 'simple',
    });

    if (props.config.scope === 'branch' && props.config.branch_id) {
        p.set('branch_id', String(props.config.branch_id));
    }

    if (props.config.scope === 'employee' && props.config.employee_id) {
        p.set('employee_id', String(props.config.employee_id));
    }

    if (isComparative.value && props.config.compare_period_id) {
        p.set('compare_period_id', String(props.config.compare_period_id));
    }

    return '?' + p.toString();
});

// El periodo ya trae el id del último run generado (period.radiography_run_id,
// calculado por ReportUploadController). Con él se puede resolver por identidad
// exacta (simple vs comparativo vs por sucursal/gestor) en vez de adivinar la ruta
// plana del reporte simple. Sin run_id todavía, se usa el endpoint de exportación
// filtrada bajo demanda como respaldo (ya corregido para incluir compare_period_id).
/** Id del último run generado del periodo (o null). */
const runId = computed(() => props.period?.radiography_run_id ?? null);

/** URL del Excel: identityState → run exacto → exportación filtrada → radiografía simple. */
const excelUrl = computed(() => {
    if (identityMatches.value && props.identityState.excelUrl) {
        return props.identityState.excelUrl;
    }

    if (!props.period) {
        return '#';
    }

    if (isFiltered.value && runId.value) {
        return `/reportes-mensuales/runs/${runId.value}/excel`;
    }

    return isFiltered.value
        ? `/reportes-mensuales/${props.period.id}/export-filtrado.xlsx${filteredParams.value}`
        : `/reportes-mensuales/${props.period.id}/radiografia.xlsx`;
});

/** URL del PDF con la misma prioridad que `excelUrl`. */
const pdfUrl = computed(() => {
    if (identityMatches.value && props.identityState.pdfUrl) {
        return props.identityState.pdfUrl;
    }

    if (!props.period) {
        return '#';
    }

    if (isFiltered.value && runId.value) {
        return `/reportes-mensuales/runs/${runId.value}/pdf`;
    }

    return isFiltered.value
        ? `/reportes-mensuales/${props.period.id}/export-filtrado.pdf${filteredParams.value}`
        : `/reportes-mensuales/${props.period.id}/radiografia.pdf`;
});

/**
 * URL de la vista previa completa (null si el reporte aún no está listo).
 * Si identityState coincide pero no trae `previewUrl` aunque el reporte ya es
 * exportable, se usa un respaldo: el run de su `excelUrl` (/runs/{id}/ver) o, si no
 * hay run (y no es comparativo), la vista previa del periodo con el mismo alcance.
 */
const previewUrl = computed(() => {
    if (identityMatches.value) {
        if (props.identityState.previewUrl) {
            return props.identityState.previewUrl;
        }

        if (!props.identityState.canExport) {
            return null;
        }

        const runMatch = /\/reportes-mensuales\/runs\/(\d+)\//.exec(
            String(props.identityState.excelUrl ?? ''),
        );

        if (runMatch) {
            return `/reportes-mensuales/runs/${runMatch[1]}/ver`;
        }

        // Sin run identificable: un comparativo no tiene vista previa equivalente por
        // periodo, y el run_id del periodo describe la identidad simple (no sirve aquí).
        if (isComparative.value || !props.period) {
            return null;
        }
    } else if (!props.period?.radiography_ready) {
        return null;
    }

    if (!identityMatches.value && isFiltered.value && runId.value) {
        return `/reportes-mensuales/runs/${runId.value}/ver`;
    }

    const base = `/reportes-mensuales/${props.period.id}/preview`;

    if (props.config?.scope === 'branch' && props.config.branch_id) {
        return `${base}?scope=branch&branch_id=${props.config.branch_id}`;
    }

    if (props.config?.scope === 'employee' && props.config.employee_id) {
        return `${base}?scope=employee&employee_id=${props.config.employee_id}`;
    }

    return base;
});

/** Subtítulo de la tarjeta Excel según tipo/alcance. */
const excelSubtitle = computed(() => {
    if (isComparative.value) {
        return 'Comparativo — archivo distinto al reporte simple';
    }

    if (props.config?.scope === 'branch') {
        return 'Filtrado por sucursal · sin plantilla';
    }

    if (props.config?.scope === 'employee') {
        return 'Filtrado por gestor · sin plantilla';
    }

    return 'Generado desde cero · sin plantilla';
});
/** Subtítulo de la tarjeta PDF según tipo/alcance. */
const pdfSubtitle = computed(() => {
    if (isComparative.value) {
        return 'Comparativo — archivo distinto al reporte simple';
    }

    if (props.config?.scope === 'branch') {
        return 'Diseño filtrado por sucursal';
    }

    if (props.config?.scope === 'employee') {
        return 'Diseño filtrado por gestor';
    }

    return 'Diseño con tablas y métricas';
});
</script>

<template>
    <section
        class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70 dark:border-white/10 dark:bg-card dark:shadow-black/20"
    >
        <SectionHeader
            eyebrow="Etapa 7"
            title="Exportación"
            description="Descarga Excel y PDF generados desde el mismo resultado del reporte. Ambos archivos reflejan exactamente lo que ves en la vista previa."
        />

        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <!-- Excel -->
            <a
                :href="canExport ? excelUrl : '#'"
                class="flex items-center justify-between rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="
                    canExport
                        ? 'cursor-pointer border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10'
                        : 'pointer-events-none border-slate-200 bg-slate-50 opacity-50 dark:border-slate-800 dark:bg-slate-800/40'
                "
            >
                <span>
                    <FileSpreadsheet
                        class="mb-3 size-7 text-emerald-700 dark:text-emerald-400"
                    />
                    <span
                        class="block font-black text-slate-950 dark:text-slate-50"
                        >Descargar Excel</span
                    >
                    <span class="text-xs text-slate-600 dark:text-slate-300">{{
                        excelSubtitle
                    }}</span>
                </span>
                <Download
                    class="size-5 text-emerald-700 dark:text-emerald-400"
                />
            </a>

            <!-- PDF -->
            <a
                :href="canExport ? pdfUrl : '#'"
                class="flex items-center justify-between rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="
                    canExport
                        ? 'cursor-pointer border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10'
                        : 'pointer-events-none border-slate-200 bg-slate-50 opacity-50 dark:border-slate-800 dark:bg-slate-800/40'
                "
            >
                <span>
                    <FileText
                        class="mb-3 size-7 text-rose-700 dark:text-rose-400"
                    />
                    <span
                        class="block font-black text-slate-950 dark:text-slate-50"
                        >Descargar PDF</span
                    >
                    <span class="text-xs text-slate-600 dark:text-slate-300">{{
                        pdfSubtitle
                    }}</span>
                </span>
                <Download class="size-5 text-rose-700 dark:text-rose-400" />
            </a>

            <!-- Página de vista previa -->
            <a
                :href="previewUrl ?? '#'"
                class="flex items-center justify-between rounded-2xl border p-5 transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="
                    previewUrl
                        ? 'cursor-pointer border-primary/30 bg-accent'
                        : 'pointer-events-none border-slate-200 bg-slate-50 opacity-50 dark:border-slate-800 dark:bg-slate-800/40'
                "
            >
                <span>
                    <ExternalLink class="mb-3 size-7 text-primary" />
                    <span
                        class="block font-black text-slate-950 dark:text-slate-50"
                        >Ver reporte completo</span
                    >
                    <span class="text-xs text-slate-600 dark:text-slate-300"
                        >Vista previa web con todas las secciones</span
                    >
                </span>
                <ExternalLink class="size-5 text-primary" />
            </a>
        </div>

        <!-- Estado de exportación -->
        <div class="mt-5">
            <StatusBadge
                :status="
                    canExport ? 'completed' : isRunning ? 'running' : 'blocked'
                "
                :label="
                    canExport
                        ? 'Archivos disponibles'
                        : isRunning
                          ? 'Generando archivos'
                          : 'Sin reporte exportable'
                "
            />
        </div>
    </section>
</template>
