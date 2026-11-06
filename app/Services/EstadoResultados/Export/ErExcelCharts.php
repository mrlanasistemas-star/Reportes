<?php

namespace App\Services\EstadoResultados\Export;

use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\ChartColor;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Gráficas NATIVAS (editables) del Excel del Estado de Resultados. Cada serie apunta a un
 * rango real de celdas de la hoja (bloque «Datos de gráficas», con las mismas series del
 * contrato er-comparativas/1) y lleva además sus valores en caché (convención del proyecto:
 * sin caché, algunos visores muestran la gráfica vacía). Un valor pendiente se deja como
 * celda VACÍA: Excel la trata como hueco (displayBlanksAs = gap), nunca como cero.
 *
 * Los datos de las gráficas van en columnas AGRUPADAS y contraídas (hideColumns): la hoja
 * queda limpia para dirección y las gráficas los leen igual (plotVisOnly = 0).
 */
final class ErExcelCharts
{
    /** Referencia absoluta calificada con la hoja: 'Hoja'!$C$5:$C$9. */
    public static function ref(Worksheet $ws, string $col, int $r1, ?int $r2 = null): string
    {
        $sheet = "'".str_replace("'", "''", $ws->getTitle())."'";

        return $sheet.'!$'.$col.'$'.$r1.($r2 !== null && $r2 !== $r1 ? ':$'.$col.'$'.$r2 : '');
    }

    /** Agrupa (nivel 1) y contrae las columnas $from..$to: datos de gráficas fuera de la vista. */
    public static function hideColumns(Worksheet $ws, string $from, string $to): void
    {
        for ($c = $from; ; $c++) {
            $ws->getColumnDimension($c)->setOutlineLevel(1)->setVisible(false)->setCollapsed(true);
            if ($c === $to) {
                break;
            }
        }
        $ws->setShowSummaryRight(true);
    }

    /**
     * @param  list<array{col:string,color:string,transparent?:bool}>  $series  la fila $headerRow tiene el nombre de cada serie
     */
    public static function add(Worksheet $ws, string $type, string $title, string $catCol, int $headerRow, int $r1, int $r2, array $series, string $topLeft, string $bottomRight, string $axisFormat = '"$"#,##0', string $grouping = DataSeries::GROUPING_CLUSTERED, bool $horizontal = false): Chart
    {
        $count = $r2 - $r1 + 1;
        $cats = [];
        for ($r = $r1; $r <= $r2; $r++) {
            $cats[] = (string) $ws->getCell($catCol.$r)->getValue();
        }
        $labels = [];
        $values = [];
        foreach ($series as $s) {
            $name = (string) $ws->getCell($s['col'].$headerRow)->getValue();
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, self::ref($ws, $s['col'], $headerRow), null, 1, [$name]);
            $data = [];
            for ($r = $r1; $r <= $r2; $r++) {
                $v = $ws->getCell($s['col'].$r)->getValue();
                $data[] = is_numeric($v) ? (float) $v : null;
            }
            $dsv = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, self::ref($ws, $s['col'], $r1, $r2), null, $count, $data, $type === DataSeries::TYPE_LINECHART ? 'circle' : null);
            if ($type === DataSeries::TYPE_LINECHART) {
                $dsv->setLineColorProperties($s['color'], null, ChartColor::EXCEL_COLOR_TYPE_RGB);
                $dsv->setLineStyleProperties(2.25);
                $dsv->getMarkerFillColor()->setColorProperties($s['color'], null, ChartColor::EXCEL_COLOR_TYPE_RGB);
                $dsv->getMarkerBorderColor()->setColorProperties($s['color'], null, ChartColor::EXCEL_COLOR_TYPE_RGB);
            } elseif (! empty($s['transparent'])) {
                $dsv->setFillColor(new ChartColor('FFFFFF', 100, ChartColor::EXCEL_COLOR_TYPE_RGB));
            } else {
                $dsv->setFillColor($s['color']);
            }
            $values[] = $dsv;
        }
        $categories = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, self::ref($ws, $catCol, $r1, $r2), null, $count, $cats)];

        $ds = new DataSeries(
            $type,
            $type === DataSeries::TYPE_LINECHART ? DataSeries::GROUPING_STANDARD : $grouping,
            range(0, count($values) - 1),
            $labels,
            $categories,
            $values,
        );
        if ($type === DataSeries::TYPE_BARCHART) {
            $ds->setPlotDirection($horizontal ? DataSeries::DIRECTION_BAR : DataSeries::DIRECTION_COL);
        }
        $chart = new Chart(
            'er_'.preg_replace('/\W+/', '_', mb_strtolower($title)).'_'.substr(md5($title.$topLeft), 0, 6),
            new Title($title),
            count($series) > 1 ? new Legend(Legend::POSITION_BOTTOM, null, false) : null,
            new PlotArea(null, [$ds]),
            false, // plotVisOnly=0: los datos viven en columnas agrupadas y ocultas (se pueden desplegar con «+»).
            DataSeries::EMPTY_AS_GAP,
        );
        $chart->setTopLeftPosition($topLeft);
        $chart->setBottomRightPosition($bottomRight);
        $chart->getChartAxisY()->setAxisNumberProperties($axisFormat);
        $ws->addChart($chart);

        return $chart;
    }
}
