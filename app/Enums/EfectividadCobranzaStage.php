<?php

namespace App\Enums;

/**
 * Etapas REALES del Job de Efectividad de Cobranza Aprobada, en orden.
 * Cada una corresponde a un bloque de código identificable en
 * GenerateEfectividadCobranzaAprobadaJob — nunca a un porcentaje arbitrario.
 *
 * `measurable` indica si la etapa reporta registros procesados/totales
 * (barra determinada). Las demás se muestran con indicador indeterminado.
 */
enum EfectividadCobranzaStage: string
{
    case ValidateFiles = 'validate_files';
    case VerifyColumns = 'verify_columns';
    case PreparePortfolio = 'prepare_portfolio';
    case ProcessIncome = 'process_income';
    case Calculate = 'calculate';
    case Reconcile = 'reconcile';
    case BuildExcel = 'build_excel';
    case Finish = 'finish';

    public function label(): string
    {
        return match ($this) {
            self::ValidateFiles => 'Validación de archivos',
            self::VerifyColumns => 'Verificación de columnas',
            self::PreparePortfolio => 'Preparación de cartera',
            self::ProcessIncome => 'Procesamiento de ingresos',
            self::Calculate => 'Cálculo de efectividad',
            self::Reconcile => 'Conciliación',
            self::BuildExcel => 'Generación de Excel',
            self::Finish => 'Finalización',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ValidateFiles => 'Verificando que los archivos de Ingresos Cobranza y Saldos por Cliente estén disponibles y procesados.',
            self::VerifyColumns => 'Comprobando campos necesarios para calcular la efectividad aprobada.',
            self::PreparePortfolio => 'Preparando la cartera correspondiente al corte seleccionado.',
            self::ProcessIncome => 'Revisando movimientos históricos y clasificando pagos válidos.',
            self::Calculate => 'Calculando Sano, Atraso y Vencido, por oficina y promotor.',
            self::Reconcile => 'Verificando totales por sucursal y resultado general.',
            self::BuildExcel => 'Preparando el archivo XLSX con los resultados aprobados.',
            self::Finish => 'El reporte se generó correctamente y está disponible para descarga.',
        };
    }

    public function measurable(): bool
    {
        return in_array($this, [self::PreparePortfolio, self::ProcessIncome], true);
    }

    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /** @return array<int,array{key:string,label:string,description:string,measurable:bool,position:int}> */
    public static function catalog(): array
    {
        return array_map(fn (self $s) => [
            'key' => $s->value,
            'label' => $s->label(),
            'description' => $s->description(),
            'measurable' => $s->measurable(),
            'position' => $s->position(),
        ], self::cases());
    }
}
