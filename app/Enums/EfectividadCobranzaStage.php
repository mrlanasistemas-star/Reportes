<?php

namespace App\Enums;

/**
 * Etapas REALES del Job de Efectividad de Cobranza (motor V4), en orden.
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
    case Audit = 'audit';
    case BuildExcel = 'build_excel';
    case Finish = 'finish';

    public function label(): string
    {
        return match ($this) {
            self::ValidateFiles => 'Validación de fotografías e ingresos',
            self::VerifyColumns => 'Verificación de encabezados y registros',
            self::PreparePortfolio => 'Preparación de fotografías diarias',
            self::ProcessIncome => 'Procesamiento de ingresos',
            self::Calculate => 'Cálculo de efectividad',
            self::Reconcile => 'Comparación de totales',
            self::Audit => 'Auditoría de incidencias',
            self::BuildExcel => 'Generación de Excel',
            self::Finish => 'Finalización',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ValidateFiles => 'Verificando que existan las fotografías de cartera de cada día (domingo base a corte) y los Ingresos Cobranza del periodo.',
            self::VerifyColumns => 'Comprobando los encabezados que exige la calculadora V4 en fotografías e ingresos.',
            self::PreparePortfolio => 'Leyendo las fotografías diarias de cartera, identificadas por fecha.',
            self::ProcessIncome => 'Leyendo Ingresos Cobranza del corte y el histórico necesario para anticipos.',
            self::Calculate => 'Calculando Sano, Vigente con atraso y Vencido por oficina y promotor (motor V4).',
            self::Reconcile => 'Verificando que promotores, oficinas y GRAN TOTAL sumen lo mismo.',
            self::Audit => 'Registrando las incidencias de cálculo (movimientos excluidos y su motivo).',
            self::BuildExcel => 'Preparando el archivo XLSX con el formato de la calculadora V4.',
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
