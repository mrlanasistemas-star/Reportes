<?php

namespace App\Services\Radiography;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Adaptador delgado sobre RadiographyStyleHelper::addBarChart() (el helper de charts
 * nativos YA existente y en uso — ver NÓMINA/addDonutChart, comparativo/
 * addComparativeBarChart) — escribe un bloque de datos [label, value] pequeño y
 * contiguo con los MISMOS valores ya calculados por el snapshot canónico (nunca
 * recalculados aquí), y delega la construcción del Chart en sí a ese helper.
 *
 * Corrección 2026-08-25: esta clase originalmente construía su propio Chart/
 * DataSeries/PlotArea, duplicando exactamente lo que RadiographyStyleHelper::
 * addBarChart() ya hacía. Se simplificó a un adaptador para no tener dos motores de
 * gráficas — RadiographyStyleHelper sigue siendo la ÚNICA fuente de construcción de
 * charts nativos en este proyecto.
 */
class RadiographyExcelChartHelper
{
    /**
     * Escribe en la hoja un bloque [etiqueta, valor] (solo valores > 0, máximo 8
     * categorías) a partir de la celda indicada y agrega una gráfica de barras
     * nativa de Excel anclada en $anchorCell, delegando en RadiographyStyleHelper::addBarChart().
     * Efecto: modifica la hoja (celdas de datos y gráfica).
     *
     * @param  array<int, array{label: string, value: float}>  $data
     * @param  Worksheet  $sheet  Hoja donde se escribe.
     * @param  string  $title  Título de la gráfica.
     * @param  string  $chartName  Nombre interno de la gráfica (no se usa al delegar).
     * @param  int  $dataStartCol  Columna (1-based) donde se escriben las etiquetas; los valores van en la siguiente.
     * @param  int  $dataStartRow  Fila inicial del bloque de datos.
     * @param  string  $anchorCell  Celda superior izquierda de la gráfica (p. ej. 'H5').
     * @param  int  $widthCells  Ancho de la gráfica en columnas.
     * @param  int  $heightRows  Alto de la gráfica en filas.
     * @return bool false si no había datos positivos (no se agrega gráfica).
     */
    public function addBarChartFromData(
        Worksheet $sheet,
        array $data,
        string $title,
        string $chartName,
        int $dataStartCol,
        int $dataStartRow,
        string $anchorCell,
        int $widthCells = 6,
        int $heightRows = 12,
    ): bool {
        $data = array_values(array_filter($data, fn ($d) => (float) $d['value'] > 0));
        if (empty($data)) {
            return false;
        }
        // Máximo 8 categorías — más que eso deja de ser legible en una gráfica de barras.
        $data = array_slice($data, 0, 8);

        $labelCol = Coordinate::stringFromColumnIndex($dataStartCol);
        $valueCol = Coordinate::stringFromColumnIndex($dataStartCol + 1);
        $endRow = $dataStartRow + count($data) - 1;

        foreach ($data as $i => $row) {
            $r = $dataStartRow + $i;
            $sheet->setCellValue("{$labelCol}{$r}", $row['label']);
            $sheet->setCellValue("{$valueCol}{$r}", (float) $row['value']);
        }

        [$anchorColIdx, $anchorRow] = Coordinate::indexesFromString($anchorCell);
        $bottomRightCol = Coordinate::stringFromColumnIndex($anchorColIdx + $widthCells);
        $bottomRightRow = $anchorRow + $heightRows;

        RadiographyStyleHelper::addBarChart(
            $sheet,
            $title,
            "\${$labelCol}\${$dataStartRow}:\${$labelCol}\${$endRow}",
            "\${$valueCol}\${$dataStartRow}:\${$valueCol}\${$endRow}",
            count($data),
            $anchorCell,
            $bottomRightCol.$bottomRightRow,
            null,
            array_column($data, 'label'),
            array_map(fn ($d) => (float) $d['value'], $data),
        );

        return true;
    }
}
