import { computed } from 'vue';
import type { Ref } from 'vue';
import type { Dataset } from '@/components/estado-resultados/types';

export type StepState = 'done' | 'current' | 'todo';

export interface CloseStep {
    key: string;
    title: string;
    /** Frase corta de qué falta (o de qué ya está). */
    detail: string;
    /** Avance 0–1 para la barra del paso (sólo visual). */
    progress: number;
    done: boolean;
    state: StepState;
    blockers: string[];
    go: { tab: string; filter?: string; label: string };
}

/**
 * Ruta de cierre del periodo: los pasos reales para que el Estado de Resultados
 * deje de ser preliminar. Sólo lee el dataset (no calcula dinero): estados de
 * fuentes, procesamiento, pendientes, ajustes sin información y validaciones.
 */
export function useCloseRoute(dataset: Ref<Dataset | null>) {
    const steps = computed<CloseStep[]>(() => {
        const d = dataset.value;

        if (!d) {
            return [];
        }

        // 1 · Fuentes
        const src = d.sources;
        const simple = [src.radiografia, src.erp, src.nomina];
        const srcBlockers = [
            ...simple
                .filter(
                    (s) =>
                        s.status === 'pendiente' ||
                        s.status === 'con_incidencias',
                )
                .map((s) => `${s.label}: ${s.detail}`),
            ...src.cuentas
                .filter((c) => !c.upload)
                .map((c) => `${c.label}: falta cargar el estado de cuenta.`),
            ...src.cuentas
                .filter((c) => c.upload?.control_ok === false)
                .map(
                    (c) =>
                        `${c.label}: los totales no cuadran con el control del banco.`,
                ),
        ];
        // Con la lista de fuentes del backend, los motivos son EXACTOS (qué falta y dónde se resuelve).
        // El NOI de corporativo recomendado no bloquea el paso, pero sí se cuenta en «listas».
        const check = src.checklist;

        if (check) {
            const open = check.items.filter((i) => i.state !== 'lista');
            // Procesar, revisar y ajustes tienen su propio paso: aquí sólo lo que hay que cargar.
            const required = open.filter(
                (i) =>
                    i.required &&
                    (i.action.kind === 'upload' || i.action.kind === 'carga'),
            );
            srcBlockers.splice(
                0,
                srcBlockers.length,
                ...required.map((i) => i.reason ?? `${i.label}: ${i.message}`),
            );
        }

        const srcTotal = check
            ? check.summary.total
            : simple.length + src.cuentas.length;
        const srcReady = check
            ? check.summary.lista
            : simple.filter(
                  (s) =>
                      s.status !== 'pendiente' &&
                      s.status !== 'con_incidencias',
              ).length +
              src.cuentas.filter(
                  (c) => c.upload && c.upload.control_ok !== false,
              ).length;

        // 2 · Procesamiento
        const proc = src.procesamiento;
        const procBlockers: string[] = [];

        if (!proc.last_run) {
            procBlockers.push(
                'La conciliación del periodo no se ha procesado.',
            );
        } else if (proc.last_run.error) {
            procBlockers.push(
                `El último procesamiento falló: ${proc.last_run.error}`,
            );
        }

        if (proc.unprocessed) {
            procBlockers.push('Hay archivos cargados que aún no se procesan.');
        }

        if (proc.stale_erp) {
            procBlockers.push(
                'El ERP cambió después del último procesamiento: vuelve a procesar.',
            );
        }

        // 3 · Pendientes
        const pend = d.conciliation.pendientes.count;
        const inPeriod = d.conciliation.movimientos_en_periodo;

        // 4 · Ajustes contables
        const noInfo = d.adjustments.filter(
            (a) => a.state === 'sin_informacion' && a.affects,
        );
        const affecting = d.adjustments.filter((a) => a.affects).length;

        // 5 · Validaciones
        const failed = d.checks.filter((c) => !c.ok);

        const list: Omit<CloseStep, 'state'>[] = [
            {
                key: 'fuentes',
                title: 'Fuentes completas',
                detail: srcBlockers.length
                    ? `${srcReady} de ${srcTotal} listas`
                    : 'Todo cargado',
                progress: srcTotal ? srcReady / srcTotal : 1,
                done: srcBlockers.length === 0,
                blockers: srcBlockers,
                go: { tab: 'fuentes', label: 'Ver fuentes' },
            },
            {
                key: 'procesar',
                title: 'Conciliación procesada',
                detail: procBlockers.length
                    ? proc.last_run
                        ? 'Requiere reprocesar'
                        : 'Sin procesar'
                    : `Última: ${proc.last_run?.at ?? '—'}`,
                progress: procBlockers.length ? 0 : 1,
                done: procBlockers.length === 0,
                blockers: procBlockers,
                go: { tab: 'control', label: 'Ver procesamiento' },
            },
            {
                key: 'revisar',
                title: 'Movimientos revisados',
                detail: pend
                    ? `${pend.toLocaleString('es-MX')} por clasificar`
                    : 'Nada pendiente',
                progress: inPeriod ? (inPeriod - pend) / inPeriod : 1,
                done: pend === 0,
                blockers: pend
                    ? [
                          `${pend.toLocaleString('es-MX')} movimiento(s) del banco sin clasificar; no suman a ningún resultado.`,
                      ]
                    : [],
                go: { tab: 'revision', label: 'Revisar ahora' },
            },
            {
                key: 'ajustes',
                title: 'Ajustes contables',
                detail: noInfo.length
                    ? `${noInfo.length} sin información`
                    : 'Capturados o confirmados',
                progress: affecting
                    ? (affecting - noInfo.length) / affecting
                    : 1,
                done: noInfo.length === 0,
                blockers: noInfo.length
                    ? [
                          `Ajustes sin información: ${noInfo.map((a) => a.label).join(', ')}.`,
                      ]
                    : [],
                go: { tab: 'ajustes', label: 'Capturar ajustes' },
            },
            {
                key: 'validar',
                title: 'Validaciones cuadran',
                detail: failed.length
                    ? `${failed.length} no cuadran`
                    : `${d.checks.length} de ${d.checks.length} en $0.00`,
                progress: d.checks.length
                    ? (d.checks.length - failed.length) / d.checks.length
                    : 1,
                done: failed.length === 0,
                blockers: failed.map((c) => `Validación: ${c.label}`),
                go: { tab: 'control', label: 'Ver validaciones' },
            },
        ];

        const firstOpen = list.findIndex((s) => !s.done);

        return list.map((s, i) => ({
            ...s,
            state: s.done ? 'done' : i === firstOpen ? 'current' : 'todo',
        }));
    });

    const blockers = computed(() => steps.value.flatMap((s) => s.blockers));
    const complete = computed(
        () => steps.value.length > 0 && blockers.value.length === 0,
    );
    const doneCount = computed(() => steps.value.filter((s) => s.done).length);
    const current = computed(
        () => steps.value.find((s) => s.state === 'current') ?? null,
    );

    return { steps, blockers, complete, doneCount, current };
}
