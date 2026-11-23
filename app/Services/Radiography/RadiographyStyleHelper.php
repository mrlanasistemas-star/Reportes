<?php

namespace App\Services\Radiography;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalDataBar;
use PhpOffice\PhpSpreadsheet\Style\ConditionalFormatting\ConditionalFormatValueObject;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Estilos centralizados del libro/hojas de la Radiografía. Todo constructor de hojas
 * (GLOBAL, hojas por sucursal, hojas de detalle) pasa por esta clase para que todo el
 * libro comparta una sola paleta y un solo conjunto de reglas de formato.
 */
final class RadiographyStyleHelper
{
    /**
     * Lee los valores YA escritos en un rango del propio sheet (p.ej. "$F$101:$F$102")
     * para poblar el `<c:strCache>`/`<c:numCache>` de un chart nativo con datos REALES en
     * vez de dejarlos vacíos (ptCount sin puntos) — bug real confirmado por inspección
     * directa del XML de un .xlsx generado (cierre 17-sep-2026 ronda 3): sin esto, algunos
     * visores (Excel antes de recalcular, LibreOffice, Google Sheets) muestran la gráfica
     * en blanco o con marcadores de error en vez del dato real. Puramente de LECTURA — el
     * rango ya fue escrito por el caller antes de agregar el chart, esto nunca cambia
     * ninguna celda ni ningún cálculo.
     *
     * @param  Worksheet  $sheet  Hoja de donde se leen los valores.
     * @param  string  $range  Rango de la misma hoja (p. ej. "$F$101:$F$102").
     * @return array<int, mixed>
     */
    private static function readRangeValues(Worksheet $sheet, string $range): array
    {
        $flat = $sheet->rangeToArray(str_replace('$', '', $range), null, false, false);

        return array_map(fn ($row) => $row[0] ?? null, $flat);
    }

    // Paleta — estilo ejecutivo verde azulado/menta, igual al libro de referencia.
    public const BG_PRIMARY_DARK = 'FF106A59';

    public const BG_ACCENT = 'FF1DC1A2';

    public const BG_LIGHT_BLUE = 'FFE3EFFF';

    public const BG_SECTION_HDR = 'FF5B9BD5';

    public const BG_GRAY = 'FFF2F2F2';

    public const BG_WHITE = 'FFFFFFFF';

    public const BG_POSITIVE = 'FFC6EFCE';

    public const BG_ALERT_RED = 'FFFFC7CE';

    public const BG_ALERT_YELLOW = 'FFFFEB9C';

    public const FG_STRONG_GREEN = 'FF00B050';

    public const FG_DARK_TEXT = 'FF1F2937';

    public const FG_WHITE = 'FFFFFFFF';

    public const FG_RED = 'FFB91C1C';

    public const FG_AMBER = 'FF92400E';

    public const BG_AMBER = 'FFFEF3C7';

    public const HYPERLINK_BLUE = 'FF1D4ED8';

    public const BORDER_LT = 'FFD9E2EC';

    // Umbrales de categoría EBITDA — fuente única compartida por PDF y Excel.
    public const EBITDA_DIAMANTE_MIN = 1_000_000.0;

    public const EBITDA_MASTER_MIN = 600_000.0;

    public const EBITDA_SENIOR_MIN = 300_000.0;

    public const EBITDA_JUNIOR_MIN = 100_000.0;

    public const CURRENCY = '"$"#,##0.00';

    public const PERCENT = '0.00"%"';

    public const INTEGER = '#,##0';

    public const DATE_FMT = 'DD/MM/YYYY';

    /**
     * Escribe y estiliza el título principal de una hoja (fuente grande en negritas sobre fondo de marca, rango combinado).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango del título (se combina de forma segura).
     * @param  string  $text  Texto del título.
     */
    public static function applyTitleStyle(Worksheet $sheet, string $range, string $text): void
    {
        self::mergeCellsSafe($sheet, $range);
        self::setCellValueSafe($sheet, explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => self::FG_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_PRIMARY_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (! empty($m[1])) {
            $sheet->getRowDimension((int) $m[1])->setRowHeight(32);
        }
    }

    /**
     * Escribe y estiliza el subtítulo de una hoja (debajo del título).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango del subtítulo.
     * @param  string  $text  Texto del subtítulo.
     */
    public static function applySubtitleStyle(Worksheet $sheet, string $range, string $text): void
    {
        self::mergeCellsSafe($sheet, $range);
        self::setCellValueSafe($sheet, explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => self::FG_DARK_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_LIGHT_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (! empty($m[1])) {
            $sheet->getRowDimension((int) $m[1])->setRowHeight(22);
        }
    }

    /**
     * Estilo de texto secundario (metadatos: periodo, fecha de generación, notas).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango a estilizar.
     */
    public static function applyMetaStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['size' => 9, 'color' => ['argb' => self::FG_DARK_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GRAY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
    }

    /**
     * Escribe y estiliza el encabezado de una sección dentro de la hoja.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango del encabezado de sección.
     * @param  string  $text  Texto del encabezado.
     */
    public static function applySectionHeaderStyle(Worksheet $sheet, string $range, string $text): void
    {
        self::mergeCellsSafe($sheet, $range);
        self::setCellValueSafe($sheet, explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 13.5, 'color' => ['argb' => self::FG_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_SECTION_HDR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (! empty($m[1])) {
            $sheet->getRowDimension((int) $m[1])->setRowHeight(22);
        }
    }

    /**
     * Escribe y estiliza la fila de encabezados de una tabla.
     *
     * @param  array<string,string>  $cols  letra de columna => texto del encabezado
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  int  $row  Fila de encabezados.
     * @param  bool  $accent  true para usar el color de acento en lugar del principal.
     */
    public static function applyTableHeaderStyle(Worksheet $sheet, int $row, array $cols, bool $accent = false): void
    {
        foreach ($cols as $col => $label) {
            self::setCellValueSafe($sheet, "{$col}{$row}", $label);
        }
        $keys = array_keys($cols);
        $range = reset($keys).$row.':'.end($keys).$row;
        $bg = $accent ? self::BG_ACCENT : self::BG_LIGHT_BLUE;
        $fg = $accent ? self::FG_WHITE : self::FG_DARK_TEXT;
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => $fg]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::BG_PRIMARY_DARK]]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
    }

    /**
     * Estilo de una fila de datos con bandas alternadas (pares/impares).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango de la fila.
     * @param  bool  $even  true si es fila par (fondo alterno).
     */
    public static function applyDataRowStyle(Worksheet $sheet, string $range, bool $even): void
    {
        $bg = $even ? self::BG_WHITE : self::BG_GRAY;
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::BORDER_LT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($range)->getFont()->setSize(9);
        preg_match('/[A-Z]+(\d+)/', $range, $m);
        if (! empty($m[1])) {
            $row = (int) $m[1];
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(17);
        }
    }

    /**
     * Estilo de la fila de totales de una tabla (negritas y fondo destacado).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango de la fila de totales.
     */
    public static function applyTotalRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9.5, 'color' => ['argb' => self::FG_WHITE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_PRIMARY_DARK]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::BG_ACCENT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/[A-Z]+(\d+)/', $range, $m);
        if (! empty($m[1])) {
            $sheet->getRowDimension((int) $m[1])->setRowHeight(20);
        }
    }

    /**
     * Formato de moneda alineado a la derecha.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $cell  Celda o rango.
     */
    public static function applyCurrencyFormat(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * Formato de porcentaje alineado a la derecha; si se pasa un valor mayor a 25
     * (p. ej. mora alta) lo resalta en rojo y negritas.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $cell  Celda o rango.
     * @param  float|null  $value  Valor para decidir el resaltado (opcional).
     */
    public static function applyPercentFormat(Worksheet $sheet, string $cell, ?float $value = null): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::PERCENT);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($value !== null && $value > 25) {
            $font = $sheet->getStyle($cell)->getFont();
            $font->setBold(true);
            $font->getColor()->setARGB(self::FG_RED);
        }
    }

    /**
     * Formato de número entero alineado a la derecha.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $cell  Celda o rango.
     */
    public static function applyIntegerFormat(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::INTEGER);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * Formato de fecha alineado a la derecha.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $cell  Celda o rango.
     */
    public static function applyDateFormat(Worksheet $sheet, string $cell): void
    {
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::DATE_FMT);
        $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * Fondo amarillo de advertencia.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango a resaltar.
     */
    public static function applyWarningStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_ALERT_YELLOW]],
            'font' => ['color' => ['argb' => self::FG_DARK_TEXT]],
        ]);
    }

    /**
     * Fondo y texto rojos de alerta.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango a resaltar.
     */
    public static function applyAlertStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_ALERT_RED]],
            'font' => ['color' => ['argb' => self::FG_RED]],
        ]);
    }

    /**
     * Verde si el valor es ≥ 0, rojo si es negativo (fondo y fuente en negritas).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $cell  Celda a estilizar.
     * @param  float  $value  Valor que decide el color.
     */
    public static function applyPositiveNegativeStyle(Worksheet $sheet, string $cell, float $value): void
    {
        $sheet->getStyle($cell)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => $value >= 0 ? self::BG_POSITIVE : self::BG_ALERT_RED],
            ],
            'font' => ['color' => ['argb' => $value >= 0 ? self::FG_STRONG_GREEN : self::FG_RED], 'bold' => true],
        ]);
    }

    /**
     * Escribe un valor de celda de forma segura: los textos que empiezan con "=" se escriben como
     * texto literal (nunca se interpretan como fórmula), números/booleanos pasan
     * tal cual y null se convierte en cadena vacía para mostrarse.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $cell  Celda.
     * @param  mixed  $value  Valor a escribir.
     */
    public static function setCellValueSafe(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null) {
            $sheet->setCellValue($cell, '');

            return;
        }
        if (is_string($value) && str_starts_with($value, '=')) {
            $sheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_STRING);

            return;
        }
        $sheet->setCellValue($cell, $value);
    }

    /**
     * Limpia/recorta un nombre de hoja al límite de 31 caracteres de Excel (usa 28 como
     * corte de trabajo para dejar espacio a sufijos por colisión) y garantiza
     * que no se repita con los nombres ya usados en este libro.
     *
     * @param  array<int,string>  $alreadyUsed  nombres de hoja ya asignados (sin distinguir mayúsculas)
     * @param  string  $rawName  Nombre deseado (p. ej. nombre de sucursal).
     * @return string Nombre válido y único para Excel.
     */
    public static function safeSheetName(string $rawName, array $alreadyUsed = []): string
    {
        $clean = preg_replace('/[\/\\\\\?\*\[\]:]/', '-', trim($rawName));
        $clean = trim((string) $clean) ?: 'Hoja';
        $base = mb_substr($clean, 0, 28);

        $used = array_map('mb_strtolower', $alreadyUsed);
        if (! in_array(mb_strtolower($base), $used, true)) {
            return $base;
        }

        for ($i = 2; $i < 100; $i++) {
            $suffix = " ({$i})";
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($suffix)).$suffix;
            if (! in_array(mb_strtolower($candidate), $used, true)) {
                return $candidate;
            }
        }

        return mb_substr($clean, 0, 28).'-'.substr(md5($rawName), 0, 2);
    }

    /**
     * Entrecomilla un nombre de hoja para usarlo en un hipervínculo interno "sheet://" de Excel.
     * Excel exige comillas simples en cualquier nombre de hoja con espacios u
     * otros caracteres especiales; las comillas internas se duplican según la convención de Excel.
     *
     * @param  string  $sheetName  Nombre de la hoja.
     * @return string Nombre entrecomillado.
     */
    public static function quoteSheetForHyperlink(string $sheetName): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $sheetName) === 1) {
            return $sheetName;
        }

        return "'".str_replace("'", "''", $sheetName)."'";
    }

    /**
     * Escribe un hipervínculo interno con estilo (texto + subrayado azul) que apunta
     * a otra hoja del mismo libro.
     *
     * @param  Worksheet  $sheet  Hoja donde va el vínculo.
     */
    public static function applyHyperlinkStyle(
        Worksheet $sheet,
        string $cell,
        string $label,
        string $targetSheetName,
        string $targetCell = 'A1'
    ): void {
        self::setCellValueSafe($sheet, $cell, $label);
        $quoted = self::quoteSheetForHyperlink($targetSheetName);
        $sheet->getCell($cell)->getHyperlink()->setUrl("sheet://{$quoted}!{$targetCell}");
        $sheet->getStyle($cell)->applyFromArray([
            'font' => [
                'color' => ['argb' => self::HYPERLINK_BLUE],
                'underline' => true,
            ],
        ]);
    }

    /**
     * Asigna anchos de columna.
     *
     * @param  array<string,float|int>  $widths  letra de columna => ancho
     * @param  Worksheet  $sheet  Hoja destino.
     */
    public static function setColWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Combina un rango de celdas de forma segura, negándose cuando corrompería el
     * libro: rangos de una sola celda, rangos que se traslapan con uno ya
     * combinado en esta hoja, o rangos que se traslapan con el encabezado del
     * autofiltro. Excel "repara" (borra) en silencio cualquier combinación que
     * viole estas reglas, que es justo la corrupción que esto evita.
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango a combinar.
     * @return bool true si se combinó, false si se omitió
     */
    public static function mergeCellsSafe(Worksheet $sheet, string $range): bool
    {
        $range = strtoupper($range);
        [$start, $end] = array_pad(explode(':', $range), 2, null);
        $end ??= $start;
        if ($start === $end) {
            return false; // combinar una sola celda no tiene sentido y puede chocar después con una combinación más amplia
        }
        $normalized = "{$start}:{$end}";

        foreach ($sheet->getMergeCells() as $existing) {
            if (self::rangesOverlap($normalized, $existing)) {
                return false;
            }
        }

        $autoFilterRange = $sheet->getAutoFilter()->getRange();
        if ($autoFilterRange !== '' && self::rangesOverlap($normalized, $autoFilterRange)) {
            return false;
        }

        $sheet->mergeCells($normalized);

        return true;
    }

    /**
     * Indica si dos rangos de celdas se traslapan.
     *
     * @param  string  $rangeA  Primer rango.
     * @param  string  $rangeB  Segundo rango.
     * @return bool true si comparten al menos una celda.
     */
    private static function rangesOverlap(string $rangeA, string $rangeB): bool
    {
        [[$aColStart, $aRowStart], [$aColEnd, $aRowEnd]] = Coordinate::rangeBoundaries($rangeA);
        [[$bColStart, $bRowStart], [$bColEnd, $bRowEnd]] = Coordinate::rangeBoundaries($rangeB);

        return $aColStart <= $bColEnd && $aColEnd >= $bColStart
            && $aRowStart <= $bRowEnd && $aRowEnd >= $bRowStart;
    }

    /**
     * Califica un rango de la misma hoja con el nombre de la hoja para usarlo en la
     * referencia de una serie de gráfica (p. ej. "'MORAS'!$A$6:$A$11").
     *
     * @param  Worksheet  $sheet  Hoja del rango.
     * @param  string  $range  Rango de la misma hoja.
     * @return string Rango calificado con el nombre de hoja.
     */
    public static function sheetQualifiedRange(Worksheet $sheet, string $range): string
    {
        return self::quoteSheetForHyperlink($sheet->getTitle()).'!'.$range;
    }

    /**
     * Agrega una gráfica de barras nativa y editable de Excel alimentada solo por rangos
     * reales de celdas ya escritos en la hoja (sin imágenes ni datos externos). Es seguro
     * en cualquier hoja — las gráficas no dependen de celdas combinadas/autofiltros,
     * así que nunca provoca la corrupción que causaría un mergeCells() mal hecho.
     *
     * @param  string  $categoryRange  rango de la misma hoja, p. ej. "$A$6:$A$11"
     * @param  string  $valueRange  rango de la misma hoja, p. ej. "$C$6:$C$11"
     */
    /**
     * @param  string|array<int,string>  $barColorArgb  un color ARGB hex, o uno por punto de datos
     * @param  array<int,string>  $categoryValues  valores REALES de categoría — sin esto, PhpSpreadsheet
     *                                             escribe `<c:strCache>`/`<c:numCache>` con `ptCount` pero CERO puntos cacheados, lo que
     *                                             algunos visores (y a veces el propio Excel antes de recalcular) muestran como #NOMBRE?/
     *                                             celdas de gráfica vacías en vez del dato real (bug real confirmado, cierre 17-sep-2026
     *                                             ronda 3 — inspección directa del XML de un .xlsx generado). Opcional para no romper
     *                                             llamadores existentes que no lo pasan.
     * @param  array<int,float>  $dataValues  valores REALES de la serie (mismo motivo que arriba).
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $chartTitle  Título de la gráfica.
     * @param  int  $dataPointCount  Número de puntos/categorías.
     * @param  string  $topLeftCell  Celda de anclaje superior izquierda.
     * @param  string  $bottomRightCell  Celda de anclaje inferior derecha.
     */
    public static function addBarChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $valueRange,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        string|array|null $barColorArgb = null,
        array $categoryValues = [],
        array $dataValues = []
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $valuesRef = self::sheetQualifiedRange($sheet, $valueRange);
        if (empty($categoryValues)) {
            $categoryValues = self::readRangeValues($sheet, $categoryRange);
        }
        if (empty($dataValues)) {
            $dataValues = self::readRangeValues($sheet, $valueRange);
        }

        $color = $barColorArgb ?? self::BG_ACCENT;
        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $dataPointCount, $dataValues);
        $values->setFillColor(is_array($color) ? array_map([self::class, 'stripAlpha'], $color) : self::stripAlpha($color));
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount, $categoryValues);

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0],
            [],
            [$categories],
            [$values]
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $title = new Title($chartTitle);

        // Sin leyenda: una gráfica de una sola serie no gana nada con un cuadro "Series1"
        // — el eje de categorías ya etiqueta cada barra.
        $chart = new Chart(uniqid('chart_', true), $title, null, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);
        // Etiquetas del eje en magnitud completa, sin escalar a miles (nunca "$39,200" en lugar de
        // $39,200,570.57) — solo se quitan decimales, lo normal en un eje de gráfica.
        $chart->getChartAxisY()->setAxisNumberProperties('"$"#,##0', true, false);

        $sheet->addChart($chart);
    }

    /**
     * Gráfica de barras agrupadas de dos series — un valor por categoría para "periodo comparado" y
     * otro para "periodo actual", lado a lado. Usado por el comparativo (mes/bimestre/
     * trimestre vs vs) para que cada métrica se vea gráficamente, no solo en tabla.
     *
     * @param  string  $categoryRange  rango de la misma hoja, p. ej. "$A$6:$A$11"
     * @param  string  $prevValueRange  rango de la misma hoja para el periodo comparado
     * @param  string  $currValueRange  rango de la misma hoja para el periodo actual
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $chartTitle  Título de la gráfica.
     * @param  string  $prevLabel  Etiqueta de la serie del periodo comparado.
     * @param  string  $currLabel  Etiqueta de la serie del periodo actual.
     * @param  int  $dataPointCount  Número de categorías.
     * @param  string  $topLeftCell  Celda de anclaje superior izquierda.
     * @param  string  $bottomRightCell  Celda de anclaje inferior derecha.
     * @param  string  $axisNumberFormat  Formato numérico del eje de valores.
     */
    public static function addComparativeBarChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $prevValueRange,
        string $currValueRange,
        string $prevLabel,
        string $currLabel,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        string $axisNumberFormat = '"$"#,##0'
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $prevRef = self::sheetQualifiedRange($sheet, $prevValueRange);
        $currRef = self::sheetQualifiedRange($sheet, $currValueRange);

        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount);

        $prevValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $prevRef, null, $dataPointCount);
        $prevValues->setFillColor(self::stripAlpha('FF94A3B8'));

        $currValues = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $currRef, null, $dataPointCount);
        $currValues->setFillColor(self::stripAlpha(self::BG_ACCENT));

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            [0, 1],
            [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$prevLabel]), new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$currLabel])],
            [$categories, $categories],
            [$prevValues, $currValues]
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(Legend::POSITION_BOTTOM, null, false);
        $title = new Title($chartTitle);

        $chart = new Chart(uniqid('chart_', true), $title, $legend, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);
        $chart->getChartAxisY()->setAxisNumberProperties($axisNumberFormat, true, false);

        $sheet->addChart($chart);
    }

    /**
     * Gráfica de varias series (barras agrupadas o líneas) con valores cacheados
     * reales en cada serie — mismo motivo que $categoryValues/$dataValues de
     * addBarChart(): sin caché algunos visores muestran la gráfica vacía. Usada por el
     * Excel del Dashboard (tendencia EBITDA vs OPEX, colocación vs recuperación).
     *
     * @param  array<int, array{label: string, range: string, color: string, values: array<int, float>}>  $series
     * @param  array<int, string>  $categoryValues
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $chartTitle  Título de la gráfica.
     * @param  string  $type  'line' para líneas; cualquier otro valor = barras agrupadas.
     * @param  string  $categoryRange  Rango de categorías en la misma hoja.
     * @param  string  $topLeftCell  Celda de anclaje superior izquierda.
     * @param  string  $bottomRightCell  Celda de anclaje inferior derecha.
     * @param  string  $axisNumberFormat  Formato numérico del eje de valores.
     */
    public static function addMultiSeriesChart(
        Worksheet $sheet,
        string $chartTitle,
        string $type,
        string $categoryRange,
        array $categoryValues,
        array $series,
        string $topLeftCell,
        string $bottomRightCell,
        string $axisNumberFormat = '"$"#,##0'
    ): void {
        $count = count($categoryValues);
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $labels = [];
        $categories = [];
        $values = [];
        foreach (array_values($series) as $s) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, null, null, 1, [$s['label']]);
            $categories[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $count, $categoryValues);
            $v = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, self::sheetQualifiedRange($sheet, $s['range']), null, $count, array_map('floatval', $s['values']));
            if ($type === 'line') {
                $v->setLineColorProperties(self::stripAlpha($s['color']));
                $v->setLineWidth(28575); // 2.25 pt
            } else {
                $v->setFillColor(self::stripAlpha($s['color']));
            }
            $values[] = $v;
        }

        $dataSeries = new DataSeries(
            $type === 'line' ? DataSeries::TYPE_LINECHART : DataSeries::TYPE_BARCHART,
            $type === 'line' ? DataSeries::GROUPING_STANDARD : DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            $labels,
            $categories,
            $values
        );
        if ($type !== 'line') {
            $dataSeries->setPlotDirection(DataSeries::DIRECTION_COL);
        }

        $legend = new Legend(Legend::POSITION_BOTTOM, null, false);
        $chart = new Chart(uniqid('chart_', true), new Title($chartTitle), $legend, new PlotArea(null, [$dataSeries]));
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);
        $chart->getChartAxisY()->setAxisNumberProperties($axisNumberFormat, true, false);

        $sheet->addChart($chart);
    }

    /**
     * Quita el canal alfa de un color ARGB de 8 caracteres (devuelve RGB).
     *
     * @param  string  $argb  Color ARGB o RGB.
     * @return string Color RGB.
     */
    private static function stripAlpha(string $argb): string
    {
        return mb_strlen($argb) === 8 ? mb_substr($argb, 2) : $argb;
    }

    /**
     * Aplica a un rango el formato condicional nativo de Excel "Barra de datos" — una función
     * real y editable de Excel (barra degradada dentro de la celda, escalada al
     * mín/máx del rango), no una imagen ni una gráfica. Se usa en el
     * tablero GLOBAL en lugar de gráficas de barras: el generador de gráficas de
     * PhpSpreadsheet no logra un estilo moderno/plano (siempre dibuja los ejes,
     * cuadrícula y bordes por defecto de Office), mientras que las barras de datos se ven limpias,
     * tipo tablero, y nunca pueden corromper el libro como
     * lo haría una mala combinación o un panel inmovilizado.
     */
    /**
     * @param  float|null  $min  mínimo explícito de la escala (tipo "num"); null = automático ("min" del rango)
     * @param  float|null  $max  máximo explícito de la escala (tipo "num"); null = automático ("max" del rango).
     *                           Pasar un $max compartido a dos rangos de una celda hace que dos
     *                           barras de distinto color sean comparables visualmente (p. ej. Recuperación vs Colocación).
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $range  Rango al que se aplica la barra de datos.
     * @param  string  $colorArgb  Color de la barra.
     * @param  bool  $showValue  Si se muestra el número junto a la barra.
     */
    public static function addDataBar(
        Worksheet $sheet,
        string $range,
        string $colorArgb,
        bool $showValue = true,
        ?float $min = null,
        ?float $max = null
    ): void {
        $dataBar = new ConditionalDataBar;
        $dataBar->setMinimumConditionalFormatValueObject(
            $min !== null ? new ConditionalFormatValueObject('num', $min) : new ConditionalFormatValueObject('min')
        );
        $dataBar->setMaximumConditionalFormatValueObject(
            $max !== null ? new ConditionalFormatValueObject('num', $max) : new ConditionalFormatValueObject('max')
        );
        $dataBar->setColor(self::stripAlpha($colorArgb));
        $dataBar->setShowValue($showValue);

        $conditional = new Conditional;
        $conditional->setConditionType(Conditional::CONDITION_DATABAR);
        $conditional->setDataBar($dataBar);

        $sheet->getStyle($range)->setConditionalStyles([$conditional]);
    }

    /**
     * EBITDA por sucursal — ÚNICA fórmula, compartida por UI/Excel/PDF. Regla final
     * (2026-07): Ingreso base EBITDA (intereses+impuestos+moratorios+comisión apertura+
     * cargos adicionales+excedentes+30% Seguro CRECE — NUNCA capital recuperado ni
     * recuperación/colocación completas) − Gastos Totales (OPEX + Nómina y Capital
     * Humano). Ver BranchRadiographyCalculator::ebitdaFinalFor() — este método es un
     * alias delgado que delega ahí para que exista una única fuente de verdad.
     *
     * @param  array<string,mixed>  $branch  fila de branch_radiography.branches
     * @return float EBITDA final de la sucursal.
     */
    public static function branchEbitdaEstimate(array $branch): float
    {
        return BranchRadiographyCalculator::ebitdaFinalFor($branch);
    }

    /**
     * Margen EBITDA por sucursal — ÚNICA fórmula, alias delgado de
     * BranchRadiographyCalculator::margenEbitdaFor().
     *
     * @param  array<string,mixed>  $branch  Fila de branch_radiography.branches.
     * @return float Margen EBITDA (%).
     */
    public static function branchMargenEbitda(array $branch): float
    {
        return BranchRadiographyCalculator::margenEbitdaFor($branch);
    }

    /**
     * Categoría de desempeño a partir del EBITDA estimado (ver branchEbitdaEstimate).
     * Umbrales: DIAMANTE ≥1M · MASTER ≥600K · SENIOR ≥300K · JUNIOR ≥100K · MANTENIDO <100K
     *
     * @param  float  $ebitdaEstimado  EBITDA de la sucursal.
     * @return string DIAMANTE, MASTER, SENIOR, JUNIOR o MANTENIDO.
     */
    public static function ebitdaCategory(float $ebitdaEstimado): string
    {
        return match (true) {
            $ebitdaEstimado >= self::EBITDA_DIAMANTE_MIN => 'DIAMANTE',
            $ebitdaEstimado >= self::EBITDA_MASTER_MIN => 'MASTER',
            $ebitdaEstimado >= self::EBITDA_SENIOR_MIN => 'SENIOR',
            $ebitdaEstimado >= self::EBITDA_JUNIOR_MIN => 'JUNIOR',
            default => 'MANTENIDO',
        };
    }

    /**
     * Colores de la etiqueta de categoría EBITDA.
     *
     * @param  string  $categoria  Categoría (DIAMANTE, MASTER, SENIOR, JUNIOR, MANTENIDO).
     * @return array{bg:string,fg:string} par ARGB de relleno/fuente para la etiqueta de categoría
     */
    public static function categoryColors(string $categoria): array
    {
        return match ($categoria) {
            'DIAMANTE' => ['bg' => 'FFE0F2FE', 'fg' => 'FF0369A1'],
            'MASTER' => ['bg' => 'FFEDE9FE', 'fg' => 'FF7C3AED'],
            'SENIOR' => ['bg' => self::BG_POSITIVE, 'fg' => self::FG_STRONG_GREEN],
            'JUNIOR' => ['bg' => self::BG_AMBER, 'fg' => self::FG_AMBER],
            default => ['bg' => self::BG_ALERT_RED, 'fg' => self::FG_RED],
        };
    }

    /**
     * Agrega una gráfica de dona — preferida sobre barras para mostrar
     * distribución/composición (cómo se reparte un total entre sus partes).
     *
     * @param  array<string>  $colors  colores ARGB hex opcionales por rebanada (sin prefijo alfa)
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $chartTitle  Título.
     * @param  string  $categoryRange  Rango de etiquetas en la misma hoja.
     * @param  string  $valueRange  Rango de valores en la misma hoja.
     * @param  int  $dataPointCount  Número de rebanadas.
     * @param  string  $topLeftCell  Celda de anclaje superior izquierda.
     * @param  string  $bottomRightCell  Celda de anclaje inferior derecha.
     */
    public static function addDonutChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $valueRange,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        array $colors = []
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $valuesRef = self::sheetQualifiedRange($sheet, $valueRange);

        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $dataPointCount, self::readRangeValues($sheet, $valueRange));
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount, self::readRangeValues($sheet, $categoryRange));

        if (! empty($colors)) {
            $values->setFillColor(array_map([self::class, 'stripAlpha'], $colors));
        }

        $series = new DataSeries(
            DataSeries::TYPE_DONUTCHART,
            null,
            [0],
            [],
            [$categories],
            [$values]
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(
            Legend::POSITION_RIGHT,
            null,
            false
        );
        $title = new Title($chartTitle);

        $chart = new Chart(uniqid('chart_', true), $title, $legend, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);

        $sheet->addChart($chart);
    }

    /**
     * Agrega una gráfica de pastel — úsese cuando cada rebanada es realmente exclusiva y hay
     * pocas categorías (≤6). Para ≥5 rebanadas se prefiere dona (se lee mejor).
     *
     * @param  Worksheet  $sheet  Hoja destino.
     * @param  string  $chartTitle  Título.
     * @param  string  $categoryRange  Rango de etiquetas en la misma hoja.
     * @param  string  $valueRange  Rango de valores en la misma hoja.
     * @param  int  $dataPointCount  Número de rebanadas.
     * @param  string  $topLeftCell  Celda de anclaje superior izquierda.
     * @param  string  $bottomRightCell  Celda de anclaje inferior derecha.
     * @param  array  $colors  Colores opcionales por rebanada.
     */
    public static function addPieChart(
        Worksheet $sheet,
        string $chartTitle,
        string $categoryRange,
        string $valueRange,
        int $dataPointCount,
        string $topLeftCell,
        string $bottomRightCell,
        array $colors = []
    ): void {
        $categoriesRef = self::sheetQualifiedRange($sheet, $categoryRange);
        $valuesRef = self::sheetQualifiedRange($sheet, $valueRange);

        $values = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $valuesRef, null, $dataPointCount, self::readRangeValues($sheet, $valueRange));
        $categories = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $categoriesRef, null, $dataPointCount, self::readRangeValues($sheet, $categoryRange));

        if (! empty($colors)) {
            $values->setFillColor(array_map([self::class, 'stripAlpha'], $colors));
        }

        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            [0],
            [],
            [$categories],
            [$values]
        );

        $plotArea = new PlotArea(null, [$series]);
        $legend = new Legend(
            Legend::POSITION_RIGHT,
            null,
            false
        );
        $title = new Title($chartTitle);

        $chart = new Chart(uniqid('chart_', true), $title, $legend, $plotArea);
        $chart->setTopLeftPosition($topLeftCell);
        $chart->setBottomRightPosition($bottomRightCell);

        $sheet->addChart($chart);
    }
}
