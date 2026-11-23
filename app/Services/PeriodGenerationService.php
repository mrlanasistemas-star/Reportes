<?php

namespace App\Services;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Genera y sincroniza el calendario de periodos: semanas base, meses operativos
 * (que agrupan semanas) y periodos derivados (bimestre, trimestre, semestre, año)
 * que solo existen cuando TODOS sus meses ya existen.
 *
 * Lo usa PeriodController al crear periodos desde la UI. Escribe y BORRA filas de
 * `periods` para mantener el calendario consistente.
 */
class PeriodGenerationService
{
    // ── Punto de entrada principal ────────────────────────────────────

    /**
     * Genera periodos del tipo pedido y resincroniza los derivados del año.
     *
     * - weekly: genera las semanas del mes y luego los derivados.
     * - monthly: crea/actualiza el mes (con las semanas indicadas o las del mes) y los derivados.
     * - otro tipo: solo resincroniza los derivados del año.
     *
     * @param  int  $year  Año.
     * @param  int  $month  Mes (1-12).
     * @param  string  $type  'weekly', 'monthly' u otro (solo derivados).
     * @param  int[]  $weekIds  Semanas a agrupar en el mes (solo monthly); vacío = todas las del mes.
     * @return Collection<int, Period> Periodos generados/sincronizados.
     *
     * @throws \RuntimeException Si se pide mensual y no hay semanas para el mes.
     */
    public function generate(int $year, int $month, string $type, array $weekIds = []): Collection
    {
        if ($type === 'weekly') {
            $weekly = $this->generateWeekly($year, $month);
            $derived = $this->syncDerivedPeriods($year);

            return $weekly->concat($derived)->values();
        }

        if ($type === 'monthly') {
            $monthly = $this->generateMonthly($year, $month, $weekIds);
            $derived = $this->syncDerivedPeriods($year);

            return collect([$monthly])->concat($derived)->values();
        }

        return $this->syncDerivedPeriods($year);
    }

    // ── Periodos semanales (base) ─────────────────────────────────────

    /**
     * Calcula el número de semana del año con el que arranca $start.
     *
     * Regla: la semana 1 va del 1 de enero al primer domingo; desde ahí cada semana
     * es lunes a domingo (2, 3, …).
     *
     * @param  int  $year  Año de referencia.
     * @param  Carbon  $start  Fecha de inicio de la semana.
     * @return int Número de semana en el año.
     */
    private function getWeeklySequenceStart(int $year, Carbon $start): int
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $firstSunday = $yearStart->copy()->endOfWeek(Carbon::SUNDAY);

        if ($start->betweenIncluded($yearStart, $firstSunday)) {
            return 1;
        }

        $firstMondayAfterOpeningWeek = $firstSunday->copy()->addDay()->startOfDay();

        return 2 + (int) floor($firstMondayAfterOpeningWeek->diffInDays($start) / 7);
    }

    /**
     * Crea/actualiza las semanas (lunes a domingo) que empiezan dentro del mes.
     *
     * En enero arranca el día 1; en los demás meses, el primer lunes. La última semana
     * puede terminar en el mes siguiente. Código W-AAAA-NN con numeración anual.
     * Efectos en BD: updateOrCreate en `periods` (deja is_closed = false) y BORRA las
     * semanas del mes que ya no correspondan al calendario.
     *
     * @param  int  $year  Año.
     * @param  int  $month  Mes (1-12).
     * @return Collection<int, Period> Semanas del mes.
     */
    private function generateWeekly(int $year, int $month): Collection
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        $cursor = $month === 1
            ? $monthStart->copy()
            : ($monthStart->dayOfWeek === Carbon::MONDAY
                ? $monthStart->copy()
                : $monthStart->copy()->next(Carbon::MONDAY));

        if ($cursor->gt($monthEnd)) {
            return collect();
        }

        $sequence = $this->getWeeklySequenceStart($year, $cursor);
        $periods = collect();
        $keptIds = [];

        while ($cursor->lte($monthEnd)) {
            $start = $cursor->copy();
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);
            $periodMonth = (int) $start->month;
            $periodYear = (int) $start->year;

            $name = sprintf(
                'Semana %d - %s %d',
                $sequence,
                ucfirst($start->translatedFormat('F')),
                $periodYear
            );
            $code = sprintf('W-%04d-%02d', $year, $sequence);

            $period = Period::query()->updateOrCreate(
                [
                    'type' => 'weekly',
                    'year' => $periodYear,
                    'month' => $periodMonth,
                    'sequence' => $sequence,
                ],
                [
                    'name' => $name,
                    'code' => $code,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'is_closed' => false,
                ],
            );

            $periods->push($period);
            $keptIds[] = $period->id;
            $cursor = $end->copy()->addDay()->startOfDay();
            $sequence++;
        }

        Period::query()
            ->where('type', 'weekly')
            ->where('year', $year)
            ->where('month', $month)
            ->when(! empty($keptIds), fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();

        return $periods->values();
    }

    // ── Periodos mensuales (operativos) ───────────────────────────────
    // Crea un periodo mensual explícito que agrupa las semanas de ese mes.
    // Si ya existen semanas para ese mes las incluye todas como componentes.

    /**
     * Crea o actualiza el periodo mensual (sequence 1) que agrupa semanas.
     *
     * Nombre "Mes AAAA", código M-AAAA-MM, fechas de la primera a la última semana y
     * component_period_ids con las semanas ordenadas. Efectos: updateOrCreate en
     * `periods` (sobrescribe nombre y deja is_closed = false).
     *
     * @param  int  $year  Año.
     * @param  int  $month  Mes (1-12).
     * @param  int[]  $weekIds  Semanas a incluir; vacío = las semanas base del mes.
     * @return Period Periodo mensual.
     *
     * @throws \RuntimeException Si no hay semanas para el mes.
     */
    public function generateMonthly(int $year, int $month, array $weekIds = []): Period
    {
        if (! empty($weekIds)) {
            $weeks = Period::query()
                ->whereIn('id', $weekIds)
                ->orderBy('start_date')
                ->get();
        } else {
            $weeks = $this->getBasePeriodsForMonth($year, $month);
        }

        if ($weeks->isEmpty()) {
            throw new \RuntimeException(
                "No hay semanas generadas para {$year}-{$month}. Genera primero los periodos semanales del mes."
            );
        }

        $monthNames = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo',    4 => 'Abril',
            5 => 'Mayo',  6 => 'Junio',   7 => 'Julio',    8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $monthName = $monthNames[$month];
        $componentIds = $weeks->sortBy('start_date')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $firstWeek = $weeks->sortBy('start_date')->first();
        $lastWeek = $weeks->sortByDesc('end_date')->first();

        return Period::query()->updateOrCreate(
            [
                'type' => 'monthly',
                'year' => $year,
                'month' => $month,
                'sequence' => 1,
            ],
            [
                'name' => "{$monthName} {$year}",
                'code' => sprintf('M-%04d-%02d', $year, $month),
                'start_date' => $firstWeek->start_date->toDateString(),
                'end_date' => $lastWeek->end_date->toDateString(),
                'component_period_ids' => $componentIds,
                'is_closed' => false,
            ]
        );
    }

    // ── Periodos derivados automáticos ────────────────────────────────

    /**
     * Sincroniza los periodos derivados del año: bimestres, trimestres, semestres y anual.
     *
     * Efectos: crea/actualiza los bloques completos y borra los que ya no lo están.
     *
     * @param  int  $year  Año.
     * @return Collection<int, Period> Periodos derivados vigentes.
     */
    public function syncDerivedPeriods(int $year): Collection
    {
        return collect()
            ->concat($this->syncBimonthlyPeriods($year))
            ->concat($this->syncQuarterlyPeriods($year))
            ->concat($this->syncSemiannualPeriods($year))
            ->concat($this->syncAnnualPeriods($year))
            ->values();
    }

    /**
     * Sincroniza los 6 bimestres del año (código BI-AAAA-NN).
     *
     * @param  int  $year  Año.
     * @return Collection<int, Period> Bimestres completos.
     */
    private function syncBimonthlyPeriods(int $year): Collection
    {
        $blocks = [
            1 => [1, 2], 2 => [3, 4],  3 => [5, 6],
            4 => [7, 8], 5 => [9, 10], 6 => [11, 12],
        ];

        return $this->syncGroupedBlocks('bimonthly', $year, $blocks, 'Bimestre', 'BI');
    }

    /**
     * Sincroniza los 4 trimestres del año (código TRI-AAAA-NN).
     *
     * @param  int  $year  Año.
     * @return Collection<int, Period> Trimestres completos.
     */
    private function syncQuarterlyPeriods(int $year): Collection
    {
        $blocks = [
            1 => [1, 2, 3], 2 => [4, 5, 6],
            3 => [7, 8, 9], 4 => [10, 11, 12],
        ];

        return $this->syncGroupedBlocks('quarterly', $year, $blocks, 'Trimestre', 'TRI');
    }

    /**
     * Sincroniza los 2 semestres del año (código SEM-AAAA-NN).
     *
     * @param  int  $year  Año.
     * @return Collection<int, Period> Semestres completos.
     */
    private function syncSemiannualPeriods(int $year): Collection
    {
        $blocks = [
            1 => [1, 2, 3, 4, 5, 6],
            2 => [7, 8, 9, 10, 11, 12],
        ];

        return $this->syncGroupedBlocks('semiannual', $year, $blocks, 'Semestre', 'SEM');
    }

    /**
     * Sincroniza el periodo anual (código AN-AAAA); solo existe con los 12 meses.
     * Si no está completo, borra cualquier anual del año.
     *
     * @param  int  $year  Año.
     * @return Collection<int, Period> El anual, o vacía.
     */
    private function syncAnnualPeriods(int $year): Collection
    {
        $blocks = [1 => range(1, 12)];
        $periods = $this->syncGroupedBlocks('annual', $year, $blocks, 'Anual', 'AN', includeYearInCode: true);

        if ($periods->isEmpty()) {
            Period::query()->where('type', 'annual')->where('year', $year)->delete();
        }

        return $periods;
    }

    /**
     * Crea/actualiza cada bloque de meses de un tipo derivado y borra los periodos de
     * ese tipo y año que ya no estén completos.
     *
     * @param  string  $type  Tipo de periodo (bimonthly, quarterly, semiannual, annual).
     * @param  int  $year  Año.
     * @param  array<int, int[]>  $blocks  secuencia => meses que la forman.
     * @param  string  $label  Etiqueta para el nombre ("Bimestre", "Trimestre"…).
     * @param  string  $codePrefix  Prefijo del código (BI, TRI, SEM, AN).
     * @param  bool  $includeYearInCode  true = código solo con año (anual), sin secuencia.
     * @return Collection<int, Period> Periodos vigentes del tipo.
     */
    private function syncGroupedBlocks(
        string $type,
        int $year,
        array $blocks,
        string $label,
        string $codePrefix,
        bool $includeYearInCode = false,
    ): Collection {
        $periods = collect();
        $keptIds = [];

        foreach ($blocks as $sequence => $months) {
            $periodName = $type === 'annual'
                ? "{$label} {$year}"
                : sprintf('%s %d - %d', $label, $sequence, $year);

            $code = $includeYearInCode
                ? sprintf('%s-%04d', $codePrefix, $year)
                : sprintf('%s-%04d-%02d', $codePrefix, $year, $sequence);

            $period = $this->syncGroupedPeriod(
                type: $type,
                year: $year,
                sequence: $sequence,
                months: $months,
                name: $periodName,
                code: $code,
            );

            if ($period) {
                $periods->push($period);
                $keptIds[] = $period->id;
            }
        }

        Period::query()
            ->where('type', $type)
            ->where('year', $year)
            ->when(! empty($keptIds), fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();

        return $periods->values();
    }

    // ── Resolución de un bloque compuesto ────────────────────────────
    // Solo se genera si TODOS los meses operativos del bloque existen.
    // Nunca usa semanas calendario directamente como componentes.

    /**
     * Crea/actualiza un periodo compuesto si TODOS sus meses operativos existen.
     *
     * Fechas: inicio del primer mes a fin del último; component_period_ids = ids de los
     * meses en orden. Efectos: updateOrCreate en `periods` (is_closed = false).
     *
     * @param  string  $type  Tipo derivado.
     * @param  int  $year  Año.
     * @param  int  $sequence  Número del bloque en el año.
     * @param  int[]  $months  Meses que componen el bloque.
     * @param  string  $name  Nombre del periodo.
     * @param  string  $code  Código del periodo.
     * @return Period|null El periodo, o null si falta algún mes.
     */
    private function syncGroupedPeriod(
        string $type,
        int $year,
        int $sequence,
        array $months,
        string $name,
        string $code,
    ): ?Period {
        $monthlyPeriods = $this->getMonthlyPeriodsForMonths($year, $months);

        if ($monthlyPeriods->count() !== count($months)) {
            return null;
        }

        $first = $monthlyPeriods->sortBy('start_date')->first();
        $last = $monthlyPeriods->sortByDesc('end_date')->first();
        $componentIds = $monthlyPeriods->sortBy('month')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        if (! $first || ! $last) {
            return null;
        }

        return Period::query()->updateOrCreate(
            [
                'type' => $type,
                'year' => $year,
                'month' => (int) min($months),
                'sequence' => $sequence,
            ],
            [
                'name' => $name,
                'code' => $code,
                'start_date' => $first->start_date->toDateString(),
                'end_date' => $last->end_date->toDateString(),
                'component_period_ids' => $componentIds,
                'is_closed' => false,
            ],
        );
    }

    // ── Helpers de consulta ───────────────────────────────────────────

    /**
     * Obtiene los periodos base (semanas o periodos sin componentes, nunca agregados)
     * de un mes, ordenados por fecha de inicio. Solo lectura.
     *
     * @param  int  $year  Año.
     * @param  int  $month  Mes (1-12).
     * @return Collection<int, Period> Periodos base del mes.
     */
    public function getBasePeriodsForMonth(int $year, int $month): Collection
    {
        return Period::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where(function ($q) {
                $q->whereIn('type', ['weekly', 'base', 'week'])
                    ->orWhereNull('component_period_ids');
            })
            ->whereNotIn('type', ['monthly', 'bimonthly', 'quarterly', 'semiannual', 'annual'])
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Obtiene los periodos mensuales existentes del año para los meses indicados. Solo lectura.
     *
     * @param  int  $year  Año.
     * @param  int[]  $months  Meses buscados.
     * @return Collection<int, Period> Meses encontrados, ordenados.
     */
    private function getMonthlyPeriodsForMonths(int $year, array $months): Collection
    {
        return Period::query()
            ->where('type', 'monthly')
            ->where('year', $year)
            ->whereIn('month', $months)
            ->orderBy('month')
            ->get();
    }
}
