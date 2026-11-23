import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';

/**
 * Lógica de la pantalla de Periodos (`pages/Periodos/Index.vue`): búsqueda, contadores,
 * alta de periodos semanales, configuración de meses operativos (agrupando semanas),
 * cierre/reapertura, eliminación de meses operativos y cambio de nombre manual.
 *
 * Rutas (Inertia):
 * - POST   `/periodos`                    → crear periodo (semanas de un mes) o mes operativo.
 * - POST   `/periodos/{id}/close|open`    → cerrar / reabrir periodo.
 * - DELETE `/periodos/{id}`               → eliminar mes operativo (las semanas se conservan).
 * - PATCH  `/periodos/{id}/nombre`        → nombre manual (`custom_name`, `null` = nombre del sistema).
 */

/** Periodo tal como lo envía el backend a la pantalla. */
type PeriodItem = {
    id: number;
    name?: string | null;
    custom_name?: string | null;
    system_label?: string | null;
    code: string;
    type?: string | null;
    sequence?: number | null;
    label: string;
    year: number;
    month: number | null;
    start_date?: string | null;
    end_date?: string | null;
    is_closed?: boolean;
    is_compound?: boolean;
    can_receive_uploads?: boolean;
    component_period_ids?: number[];
    component_labels?: string[];
    component_weeks?: Array<{
        id: number;
        sequence: number | null;
        start_date: string | null;
        end_date: string | null;
    }>;
    uploaded_sources_count?: number;
    required_sources_count?: number;
    can_close?: boolean;
    close_issues_count?: number;
    close_issues_preview?: string[];
};

/** Semana base disponible (aún sin asignar a un mes operativo). */
type WeekItem = {
    id: number;
    label: string;
    year: number;
    month: number;
    sequence: number;
    start_date?: string;
    end_date?: string;
};

/** Props de la página: periodos existentes y semanas libres para armar meses operativos. */
type Props = {
    periods: PeriodItem[];
    availableWeeks?: WeekItem[];
};

/** Nombre en español de cada mes (1–12). */
const monthNames: Record<number, string> = {
    1: 'Enero',
    2: 'Febrero',
    3: 'Marzo',
    4: 'Abril',
    5: 'Mayo',
    6: 'Junio',
    7: 'Julio',
    8: 'Agosto',
    9: 'Septiembre',
    10: 'Octubre',
    11: 'Noviembre',
    12: 'Diciembre',
};

/**
 * Estado y acciones de la pantalla de Periodos.
 *
 * @param props Props de la página (`periods`, `availableWeeks`).
 * @returns Filtros, formularios, listas filtradas, contadores y acciones con confirmación SweetAlert2.
 */
export function usePeriodosIndex(props: Props) {
    /** Texto de búsqueda del listado de periodos. */
    const filters = ref({
        query: '',
    });

    /** Formulario de alta de periodo (tipo, año y mes). */
    const form = useForm<{
        type: string;
        year: string | number;
        month: string | number;
    }>({
        type: 'weekly',
        year: '',
        month: '',
    });

    /** Formulario del mes operativo: año, mes y semanas base que lo componen. */
    const monthlyForm = useForm<{
        type: string;
        year: string | number;
        month: string | number;
        week_ids: number[];
    }>({
        type: 'monthly',
        year: new Date().getFullYear(),
        month: '',
        week_ids: [],
    });

    /**
     * Semanas disponibles para el mes operativo: todas las libres del año elegido, ordenadas
     * por fecha de inicio. Vacío hasta elegir año y mes.
     */
    const filteredWeeksForMonth = computed(() => {
        const year = Number(monthlyForm.year);
        const month = Number(monthlyForm.month);

        if (!year || !month) {
            return [];
        }

        // Muestra todas las semanas sin asignar del año (no filtrar por mes calendario)
        // para que semanas sobrantes del mes anterior estén disponibles
        return [...(props.availableWeeks ?? [])]
            .filter((w) => w.year === year)
            .sort((a, b) =>
                (a.start_date ?? '').localeCompare(b.start_date ?? ''),
            );
    });

    /** Periodos que coinciden con la búsqueda (etiqueta, código, año, mes o tipo). */
    const filteredPeriods = computed(() => {
        const query = filters.value.query.trim().toLowerCase();

        if (!query) {
            return props.periods;
        }

        return props.periods.filter((period) => {
            return (
                period.label.toLowerCase().includes(query) ||
                period.code.toLowerCase().includes(query) ||
                String(period.year).includes(query) ||
                String(period.month ?? '').includes(query) ||
                String(period.type ?? '')
                    .toLowerCase()
                    .includes(query)
            );
        });
    });

    /** Contadores de las tarjetas: total, cerrados, abiertos y abiertos que no se pueden cerrar (incidencias críticas). */
    const totalPeriods = computed(() => props.periods.length);
    const closedPeriods = computed(
        () => props.periods.filter((p) => p.is_closed).length,
    );
    const openPeriods = computed(
        () => props.periods.filter((p) => !p.is_closed).length,
    );
    const blockedPeriods = computed(
        () =>
            props.periods.filter((p) => !p.is_closed && p.can_close === false)
                .length,
    );

    /** Texto descriptivo del periodo que se creará según tipo/año/mes (vacío si falta año o mes). */
    const createLabel = computed(() => {
        const type = form.type;
        const year = Number(form.year);
        const month = Number(form.month);

        if (!year || !month) {
            return '';
        }

        if (type === 'weekly') {
            return `Semanas de ${monthNames[month] ?? 'Periodo'} ${year}`;
        }

        if (type === 'monthly') {
            return `Periodo mensual: ${monthNames[month] ?? 'Periodo'} ${year}`;
        }

        if (type === 'bimonthly') {
            return `Bimestres de ${year}`;
        }

        if (type === 'quarterly') {
            const quarter = Math.ceil(month / 3);

            return `Trimestre ${quarter} de ${year}`;
        }

        if (type === 'semiannual') {
            return month <= 6
                ? `Semestre 1 de ${year}`
                : `Semestre 2 de ${year}`;
        }

        if (type === 'annual') {
            return `Periodo anual ${year}`;
        }

        return `${monthNames[month] ?? 'Periodo'} ${year}`;
    });

    /**
     * Confirma y crea el periodo del formulario (POST `/periodos`). Al terminar limpia año/mes
     * y regresa el tipo a semanal.
     */
    const submitCreate = async () => {
        const result = await Swal.fire({
            title: '¿Crear periodo?',
            text: createLabel.value || 'Se generará el periodo seleccionado.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, crear',
            cancelButtonText: 'Cancelar',
        });

        if (!result.isConfirmed) {
            return;
        }

        form.post('/periodos', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('year', 'month');
                form.type = 'weekly';
            },
        });
    };

    /**
     * Valida (año, mes y al menos una semana), confirma y crea el mes operativo con las semanas
     * elegidas (POST `/periodos`). Al terminar limpia mes y semanas.
     */
    const submitConfigureMonth = async () => {
        const year = Number(monthlyForm.year);
        const month = Number(monthlyForm.month);
        const monthLabel = monthNames[month] ?? 'Periodo';

        if (!year || !month) {
            await Swal.fire({
                title: 'Datos incompletos',
                text: 'Selecciona el año y el mes antes de continuar.',
                icon: 'warning',
                confirmButtonText: 'Entendido',
            });

            return;
        }

        if (monthlyForm.week_ids.length === 0) {
            await Swal.fire({
                title: 'Sin semanas seleccionadas',
                text: 'Selecciona al menos una semana para el mes operativo.',
                icon: 'warning',
                confirmButtonText: 'Entendido',
            });

            return;
        }

        const result = await Swal.fire({
            title: '¿Configurar mes operativo?',
            text: `Se creará el periodo mensual de ${monthLabel} ${year} con ${monthlyForm.week_ids.length} semana(s) seleccionada(s).`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, crear',
            cancelButtonText: 'Cancelar',
        });

        if (!result.isConfirmed) {
            return;
        }

        monthlyForm.post('/periodos', {
            preserveScroll: true,
            onSuccess: () => {
                monthlyForm.reset('month', 'week_ids');
            },
        });
    };

    /**
     * Confirma y cierra o reabre un periodo (POST `/periodos/{id}/close|open`). Si el periodo
     * tiene incidencias críticas avisa que el backend bloqueará el cierre.
     *
     * @param period Periodo a cerrar/reabrir.
     */
    const togglePeriod = async (period: PeriodItem) => {
        const isClosing = !period.is_closed;

        let text = isClosing
            ? `Se intentará cerrar ${period.label}.`
            : `Se reabrirá ${period.label}.`;

        if (isClosing && period.can_close === false) {
            text = `Este periodo tiene ${period.close_issues_count ?? 0} incidencia(s) crítica(s). Si intentas cerrarlo, el backend lo bloqueará.`;
        }

        const result = await Swal.fire({
            title: isClosing ? '¿Cerrar periodo?' : '¿Reabrir periodo?',
            text,
            icon: isClosing ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonText: isClosing ? 'Sí, cerrar' : 'Sí, reabrir',
            cancelButtonText: 'Cancelar',
        });

        if (!result.isConfirmed) {
            return;
        }

        // router (no el formulario de «Generar semanas»): no manda campos de más ni comparte su estado `processing`.
        router.post(
            `/periodos/${period.id}/${period.is_closed ? 'open' : 'close'}`,
            {},
            {
                preserveScroll: true,
            },
        );
    };

    /**
     * Confirma y elimina un mes operativo (DELETE `/periodos/{id}`). Solo borra la agrupación;
     * las semanas base vuelven a quedar disponibles.
     *
     * @param period Mes operativo a eliminar.
     */
    const destroyMonthly = async (period: PeriodItem) => {
        const result = await Swal.fire({
            title: '¿Eliminar mes operativo?',
            html: `Se eliminará únicamente la agrupación <strong>${period.label}</strong>.<br><br>Las semanas base <strong>no se eliminarán</strong> y volverán a estar disponibles para crear otro periodo.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Eliminar mes operativo',
            cancelButtonText: 'Cancelar',
        });

        if (!result.isConfirmed) {
            return;
        }

        router.delete(`/periodos/${period.id}`, {
            preserveScroll: true,
        });
    };

    // Nombre manual (23-nov-2026): solo cambia la etiqueta; la información del
    // periodo (ingresos, gastos, cargas, reportes) sigue ligada a su id.
    /**
     * Pide un nombre manual para el periodo (máx. 120 caracteres) y lo guarda
     * (PATCH `/periodos/{id}/nombre`). Vacío = volver al nombre del sistema (`null`).
     *
     * @param period Periodo a renombrar.
     */
    const renamePeriod = async (period: PeriodItem) => {
        /** Escapa HTML para mostrar el nombre del sistema de forma segura dentro del diálogo. */
        const escape = (v: string) =>
            v.replace(
                /[&<>"']/g,
                (c) =>
                    ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;',
                    })[c] as string,
            );
        const systemLabel = period.system_label ?? period.label;

        const result = await Swal.fire({
            title: 'Cambiar nombre del periodo',
            html:
                `<p style="font-size:14px;margin-bottom:6px">Nombre del sistema: <strong>${escape(systemLabel)}</strong></p>` +
                '<p style="font-size:13px;color:#64748b">Solo cambia cómo se ve el nombre. Las fechas, las semanas y toda la información del periodo se conservan igual. Déjalo vacío para volver al nombre del sistema.</p>',
            input: 'text',
            inputValue: period.custom_name ?? '',
            inputPlaceholder: systemLabel,
            inputAttributes: {
                maxlength: '120',
                'aria-label': 'Nuevo nombre del periodo',
            },
            showCancelButton: true,
            confirmButtonText: 'Guardar nombre',
            cancelButtonText: 'Cancelar',
            inputValidator: (value) =>
                value && value.trim().length > 120
                    ? 'Máximo 120 caracteres.'
                    : undefined,
        });

        if (!result.isConfirmed) {
            return;
        }

        router.patch(
            `/periodos/${period.id}/nombre`,
            { custom_name: (result.value ?? '').trim() || null },
            {
                preserveScroll: true,
            },
        );
    };

    return {
        renamePeriod,
        filters,
        form,
        monthlyForm,
        filteredWeeksForMonth,
        filteredPeriods,
        totalPeriods,
        closedPeriods,
        openPeriods,
        blockedPeriods,
        createLabel,
        submitCreate,
        submitConfigureMonth,
        togglePeriod,
        destroyMonthly,
    };
}
