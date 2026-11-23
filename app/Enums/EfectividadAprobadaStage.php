<?php

namespace App\Enums;

/**
 * Etapas REALES del Job de Efectividad Aprobada (periodos sin fotografías
 * diarias — un solo corte de Saldos por Cliente + histórico de Ingresos
 * Cobranza). Más simple que el motor V4 porque no hay fotografías que
 * verificar día por día ni auditoría de incidencias excluidas: el motor
 * aprobado (`EfectividadCobranzaAprobadaCalculator`) no produce esa bitácora.
 */
enum EfectividadAprobadaStage: string
{
    case ReadSources = 'read_sources';
    case Calculate = 'calculate';
    case BuildExcel = 'build_excel';
    case Finish = 'finish';

    /**
     * Nombre corto de la etapa, en español, que se muestra en la barra de progreso.
     *
     * @return string Etiqueta visible para el usuario.
     */
    public function label(): string
    {
        return match ($this) {
            self::ReadSources => 'Lectura de cartera e ingresos',
            self::Calculate => 'Cálculo de efectividad',
            self::BuildExcel => 'Generación de Excel',
            self::Finish => 'Finalización',
        };
    }

    /**
     * Texto explicativo de lo que hace el Job durante esta etapa (se muestra bajo la etiqueta).
     *
     * @return string Descripción para el usuario.
     */
    public function description(): string
    {
        return match ($this) {
            self::ReadSources => 'Leyendo el corte de cartera (Saldos por Cliente) y el histórico de Ingresos Cobranza para verificar que haya información suficiente.',
            self::Calculate => 'Calculando Sano, Atraso y Vencido por oficina y promotor (metodología aprobada anterior).',
            self::BuildExcel => 'Preparando el archivo XLSX con el formato aprobado.',
            self::Finish => 'El reporte se generó correctamente y está disponible para descarga.',
        };
    }

    /**
     * Indica si la etapa reporta registros procesados/totales (barra determinada)
     * o si debe mostrarse con indicador indeterminado.
     *
     * Indeterminado: cartera e ingresos se leen en la misma etapa con totales distintos, nunca se mezclan en un solo porcentaje inventado.
     *
     * @return bool true si la etapa es medible.
     */
    public function measurable(): bool
    {
        return false;
    }

    /**
     * Posición 1-based de la etapa dentro del flujo (orden de declaración de los casos).
     *
     * @return int Número de etapa.
     */
    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /**
     * Catálogo completo de etapas para enviar al frontend (pantalla de progreso).
     *
     * @return array<int,array{key:string,label:string,description:string,measurable:bool,position:int}>
     */
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
