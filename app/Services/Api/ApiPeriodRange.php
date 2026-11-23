<?php

namespace App\Services\Api;

use App\Models\Period;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rango de periodos para exportaciones de la API v1. Dos formas, excluyentes:
 *
 *   ?period_ids=12,13,14                      — lista explícita
 *   ?from_period_id=12&to_period_id=14        — todos los periodos del MISMO
 *                                               tipo que `from` cuyo inicio cae
 *                                               entre el inicio de `from` y el de `to`
 *
 * Nunca adivina "el periodo más reciente": sin parámetros → 422.
 */
class ApiPeriodRange
{
    /**
     * Resuelve los periodos solicitados por la petición.
     *
     * Con `period_ids`: valida enteros positivos, quita duplicados, aplica el
     * máximo y exige que todos existan. Con `from_period_id`/`to_period_id`:
     * ambos deben existir, ser del mismo tipo y `from` iniciar antes o igual
     * que `to`; devuelve todos los periodos de ese tipo cuyo inicio cae en el rango.
     *
     * Solo lectura sobre la tabla periods.
     *
     * @param  Request  $request  Petición HTTP con los parámetros de rango.
     * @param  int  $max  Número máximo de periodos permitidos por petición.
     * @return Collection<int, Period> ordenados por fecha de inicio ascendente
     *
     * @throws HttpException 422 por parámetros inválidos/combinados/excedidos; 404 si algún id de `period_ids` no existe.
     * @throws ModelNotFoundException si `from_period_id` o `to_period_id` no existen.
     */
    public static function resolve(Request $request, int $max): Collection
    {
        $rawIds = trim((string) $request->query('period_ids', ''));
        $from = ApiValidation::nullableId($request, 'from_period_id');
        $to = ApiValidation::nullableId($request, 'to_period_id');

        if ($rawIds !== '' && ($from !== null || $to !== null)) {
            throw new HttpException(422, "Usa 'period_ids' o 'from_period_id'/'to_period_id', no ambos.");
        }

        if ($rawIds !== '') {
            $ids = [];
            foreach (explode(',', $rawIds) as $part) {
                $part = trim($part);
                if ($part === '' || ! ctype_digit($part) || (int) $part <= 0) {
                    throw new HttpException(422, "El parámetro 'period_ids' debe ser una lista de enteros positivos separados por coma.");
                }
                $ids[(int) $part] = true;
            }
            $ids = array_keys($ids);
            if (count($ids) > $max) {
                throw new HttpException(422, "Máximo {$max} periodos por petición.");
            }
            $periods = Period::query()->whereIn('id', $ids)->get();
            if ($periods->count() !== count($ids)) {
                throw new HttpException(404, 'Uno o más periodos solicitados no existen.');
            }

            return $periods->sortBy(fn (Period $p) => optional($p->start_date)->timestamp)->values();
        }

        if ($from === null || $to === null) {
            throw new HttpException(422, "Indica 'period_ids' o ambos 'from_period_id' y 'to_period_id'.");
        }

        $fromPeriod = Period::query()->findOrFail($from);
        $toPeriod = Period::query()->findOrFail($to);

        if ($fromPeriod->type !== $toPeriod->type) {
            throw new HttpException(422, 'from_period_id y to_period_id deben ser del mismo tipo de periodo.');
        }
        if ($fromPeriod->start_date && $toPeriod->start_date && $fromPeriod->start_date->gt($toPeriod->start_date)) {
            throw new HttpException(422, 'from_period_id debe iniciar antes (o igual) que to_period_id.');
        }

        $periods = Period::query()
            ->where('type', $fromPeriod->type)
            ->whereDate('start_date', '>=', $fromPeriod->start_date)
            ->whereDate('start_date', '<=', $toPeriod->start_date)
            ->orderBy('start_date')
            ->get();

        if ($periods->count() > $max) {
            throw new HttpException(422, "El rango abarca {$periods->count()} periodos; máximo {$max} por petición.");
        }

        return $periods->values();
    }

    /**
     * Ficha corta del periodo, misma forma que /periods/{id}.
     *
     * @param  Period  $period  Periodo a describir.
     * @return array<string, mixed> id, code, type, label (visible), custom_name, system_label, fechas (Y-m-d) e is_closed.
     */
    public static function describe(Period $period): array
    {
        return [
            'id' => $period->id,
            'code' => $period->code,
            'type' => $period->type,
            'label' => $period->label,
            // Aditivo (23-nov-2026): nombre manual y nombre del sistema. `label` ya es el visible.
            'custom_name' => $period->custom_name,
            'system_label' => $period->systemLabel(),
            'start_date' => optional($period->start_date)->toDateString(),
            'end_date' => optional($period->end_date)->toDateString(),
            'is_closed' => (bool) $period->is_closed,
        ];
    }
}
