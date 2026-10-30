<?php

namespace App\Services\Efectividad\Calculadora;

use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * XLSX de la calculadora con el MISMO formato que el PDF/imagen
 * (`efectividad.calculadora-reporte`): logo, encabezado, KPI, tabla con bandas de
 * sucursal y ruta, totales, semáforo, notas y control. Recibe el resultado ya
 * calculado y nunca recalcula importes. La hoja de la macro V4 sigue siendo
 * responsabilidad de EfectividadV4ExcelExporter.
 */
class EfectividadCalculadoraExcelExporter
{
    private const MONEDA = '$#,##0.00;-$#,##0.00';

    private const PCT = '0.00%';

    private const FUENTE = 'Montserrat';

    // Colores de la plantilla PDF/imagen.
    private const TINTA = '0F172A';

    private const PIZARRA = '1E293B';

    private const TEAL = '0F766E';

    private const GRIS = '475569';

    private const OK = '047857';

    private const WARN = 'B45309';

    private const BAD = 'B91C1C';

    private const BLANCO = 'FFFFFF';

    private const CAMPOS = ['sano_pc', 'sano_cob', 'sano_pct', 'atraso_pc', 'atraso_cob', 'atraso_pct', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'];

    private const MODOS = ['DIARIO' => 'Diario', 'ACUMULADO' => 'Acumulado', 'SEMANAL' => 'Semanal', 'MENSUAL' => 'Mensual'];

    private const PRIMERA_FILA_DATOS = 10;

    public function __construct(private readonly EfectividadV4ExcelExporter $macro) {}

    /** @return string ruta del XLSX generado */
    public function export(array $r, string $outputPath): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Reportes MR LANA')->setTitle('Efectividad de Cobranza')->setSubject((string) $r['titulo']);
        $book->getDefaultStyle()->getFont()->setName(self::FUENTE);

        $ws = $book->getActiveSheet();
        $ws->setTitle('EFECTIVIDAD');
        $ws->getTabColor()->setRGB(self::TEAL);
        $ws->setShowGridlines(false);
        foreach (['A' => 36, 'B' => 14, 'C' => 14, 'D' => 10, 'E' => 14, 'F' => 14, 'G' => 10, 'H' => 14, 'I' => 14, 'J' => 10, 'K' => 15, 'L' => 10, 'M' => 18] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }

        $this->encabezado($ws, $r);
        $this->kpis($ws, $r);
        $this->encabezadoTabla($ws);
        $ultima = $this->filas($ws, $r['filas']);
        $this->cierre($ws, $r, $ultima + 2);

        $ws->freezePane('A'.self::PRIMERA_FILA_DATOS);
        $ws->setSelectedCell('A1');
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(8, 9);
        $ws->getPageMargins()->setTop(0.39)->setRight(0.31)->setBottom(0.55)->setLeft(0.31);
        $pie = str_replace('&', '&&', 'MR LANA · Efectividad de Cobranza — '.$r['titulo']);
        $ws->getHeaderFooter()->setOddFooter("&L&8{$pie}&R&8Página &P de &N");

        $auditoria = $book->createSheet();
        $this->macro->hojaAuditoria($auditoria, $r['incidencias'] ?? [], $r['parametros']);
        $this->estiloAuditoria($auditoria);

        $book->setActiveSheetIndex(0);
        // Igual que el exportador V4: conserva la representación exacta de cada importe.
        $precision = ini_get('precision');
        ini_set('precision', '-1');
        try {
            (new Xlsx($book))->save($outputPath);
        } finally {
            ini_set('precision', (string) $precision);
        }

        return $outputPath;
    }

    private function encabezado(Worksheet $ws, array $r): void
    {
        foreach ([1 => 16, 2 => 26, 3 => 16, 4 => 16, 5 => 8] as $fila => $alto) {
            $ws->getRowDimension($fila)->setRowHeight($alto);
        }
        $ws->mergeCells('A1:A4');
        $this->logo($ws);

        $ws->mergeCells('B1:H1');
        $ws->setCellValue('B1', 'MR LANA');
        $ws->getStyle('B1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::TEAL);

        $ws->mergeCells('B2:H2');
        $ws->setCellValue('B2', 'Efectividad de Cobranza');
        $ws->getStyle('B2')->getFont()->setBold(true)->setSize(17)->getColor()->setRGB(self::TINTA);

        $ws->mergeCells('B3:H4');
        $metodologia = ! empty($r['metodologia']) ? ' · Metodología '.$r['metodologia'] : '';
        $ws->setCellValueExplicit('B3', $r['titulo'].' · '.($r['leyenda'] ?? '').$metodologia, DataType::TYPE_STRING);
        $ws->getStyle('B3')->getFont()->setSize(8)->getColor()->setRGB(self::GRIS);
        $ws->getStyle('B3')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        $p = $r['parametros'];
        $lineas = [
            [['Modalidad: ', false], [self::MODOS[$r['modo']] ?? (string) $r['modo'], true]],
            [['Periodo de ingresos: ', false], [$this->dmy($p['inicio_ingresos']).' al '.$this->dmy($p['fin_ingresos']), true]],
            [['Fecha de corte: ', false], [$this->dmy($p['fecha_corte']), true], [' · Domingo base: ', false], [$this->dmy($p['domingo_base']), true]],
            [[$this->alcance($r), false]],
        ];
        foreach ($lineas as $i => $partes) {
            $fila = $i + 1;
            $ws->mergeCells("I{$fila}:M{$fila}");
            $ws->setCellValue("I{$fila}", $this->rico($partes, 8, self::GRIS));
            $ws->getStyle("I{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
        }

        $ws->getStyle('A4:M4')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK)->getColor()->setRGB(self::TEAL);
    }

    /** Esquina superior izquierda; si falta el archivo, queda la marca de texto (como el PDF). */
    private function logo(Worksheet $ws): void
    {
        $ruta = public_path('logo-mr-lana.png');
        if (! is_file($ruta)) {
            return;
        }
        $logo = new Drawing;
        $logo->setName('MR LANA')->setDescription('Logo MR LANA')->setPath($ruta);
        $logo->setHeight(72);
        $logo->setCoordinates('A1')->setOffsetX(6)->setOffsetY(12);
        $logo->setWorksheet($ws);
    }

    private function kpis(Worksheet $ws, array $r): void
    {
        $gt = collect($r['filas'])->firstWhere('tipo', 'gran_total') ?? [];
        $mora = (float) ($gt['mora'] ?? 0);
        $kpis = [
            ['A', 'A', 'SANO POR COBRAR', (float) ($gt['sano_pc'] ?? 0), self::MONEDA, self::TINTA],
            ['B', 'D', 'SANO COBRADO', (float) ($gt['sano_cob'] ?? 0), self::MONEDA, self::TINTA],
            ['E', 'G', 'ATRASO + VENCIDO COBRADO', (float) ($gt['atraso_cob'] ?? 0) + (float) ($gt['vencido_cob'] ?? 0), self::MONEDA, self::TINTA],
            ['H', 'J', 'COBRANZA TOTAL', (float) ($gt['cobranza_total'] ?? 0), self::MONEDA, self::TINTA],
            ['K', 'L', '% COBRANZA TOTAL', (float) ($gt['pct_total'] ?? 0), self::PCT, $this->semaforo($gt) ?? self::TINTA],
            ['M', 'M', 'MORA', $mora, self::MONEDA, $mora > 0 ? self::BAD : self::OK],
        ];
        foreach ($kpis as [$desde, $hasta, $etiqueta, $valor, $formato, $color]) {
            if ($desde !== $hasta) {
                $ws->mergeCells("{$desde}6:{$hasta}6");
                $ws->mergeCells("{$desde}7:{$hasta}7");
            }
            $ws->setCellValue("{$desde}6", $etiqueta);
            $ws->setCellValue("{$desde}7", $valor);
            $ws->getStyle("{$desde}6")->getFont()->setSize(7)->getColor()->setRGB('64748B');
            $ws->getStyle("{$desde}7")->getFont()->setBold(true)->setSize(11)->getColor()->setRGB($color);
            $ws->getStyle("{$desde}7")->getNumberFormat()->setFormatCode($formato);
            $tarjeta = $ws->getStyle("{$desde}6:{$hasta}7");
            $tarjeta->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8FAFC');
            $tarjeta->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $tarjeta->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        }
        $ws->getRowDimension(6)->setRowHeight(15);
        $ws->getRowDimension(7)->setRowHeight(22);
    }

    private function encabezadoTabla(Worksheet $ws): void
    {
        $ws->mergeCells('A8:A9');
        $ws->setCellValue('A8', 'Ruta / Promotor');
        $this->estiloEncabezado($ws, 'A8:A9', self::TINTA, self::BLANCO, Alignment::HORIZONTAL_LEFT);

        foreach ([['B8:D8', 'Sano', '059669', self::BLANCO], ['E8:G8', 'Vigente con atraso', 'FBBF24', self::TINTA], ['H8:J8', 'Vencido con atraso', 'DC2626', self::BLANCO], ['K8:M8', 'Total', self::PIZARRA, self::BLANCO]] as [$rango, $texto, $bg, $fg]) {
            $ws->mergeCells($rango);
            $ws->setCellValue(explode(':', $rango)[0], $texto);
            $this->estiloEncabezado($ws, $rango, $bg, $fg, Alignment::HORIZONTAL_CENTER);
        }

        foreach (['Por cobrar', 'Cobrado', '%', 'Por cobrar', 'Cobrado', '%', 'Por cobrar', 'Cobrado', '%', 'Cobranza', '%', 'Mora'] as $i => $texto) {
            $ws->setCellValue(chr(ord('B') + $i).'9', $texto);
        }
        $this->estiloEncabezado($ws, 'B9:M9', self::TINTA, self::BLANCO, Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension(8)->setRowHeight(20);
        $ws->getRowDimension(9)->setRowHeight(18);
    }

    /** @return int última fila escrita */
    private function filas(Worksheet $ws, array $filas): int
    {
        $fila = self::PRIMERA_FILA_DATOS;
        if (! $filas) {
            $ws->mergeCells("A{$fila}:M{$fila}");
            $ws->setCellValue("A{$fila}", 'Sin movimientos para el periodo');

            return $fila;
        }

        $sucursal = null;
        $oficina = null;
        foreach ($filas as $f) {
            if ($f['tipo'] === 'promotor' && ($f['sucursal'] ?? null) && $f['sucursal'] !== $sucursal) {
                $sucursal = $f['sucursal'];
                $oficina = null;
                $this->banda($ws, $fila, 'SUCURSAL '.mb_strtoupper((string) $sucursal), self::PIZARRA, self::BLANCO);
                $fila++;
            }
            if ($f['tipo'] === 'promotor' && $f['oficina'] !== $oficina) {
                $oficina = $f['oficina'];
                $ws->mergeCells("A{$fila}:M{$fila}");
                // RichText: una ruta que empiece con «=» nunca se interpreta como fórmula.
                $ws->setCellValue("A{$fila}", $this->rico([['Ruta · ', false, '64748B'], [mb_strtoupper((string) $f['oficina_titulo']), true, self::TEAL]], 9, self::TEAL));
                $s = $ws->getStyle("A{$fila}:M{$fila}");
                $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
                $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $s->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
                $ws->getRowDimension($fila)->setRowHeight(18);
                $fila++;
            }
            $this->filaDatos($ws, $fila, $f);
            $fila++;
        }

        return $fila - 1;
    }

    private function filaDatos(Worksheet $ws, int $fila, array $f): void
    {
        $tipo = $f['tipo'];
        $total = $tipo === 'gran_total';
        $ws->setCellValueExplicit("A{$fila}", (string) $f['promotor'], DataType::TYPE_STRING);
        foreach (self::CAMPOS as $i => $campo) {
            $col = chr(ord('B') + $i);
            $ws->setCellValue("{$col}{$fila}", (float) $f[$campo]);
            $ws->getStyle("{$col}{$fila}")->getNumberFormat()->setFormatCode(str_ends_with($campo, 'pct') || $campo === 'pct_total' ? self::PCT : self::MONEDA);
        }

        $fondo = ['subtotal' => 'E2E8F0', 'subtotal_sucursal' => 'CBD5E1', 'gran_total' => self::TEAL][$tipo] ?? self::BLANCO;
        $s = $ws->getStyle("A{$fila}:M{$fila}");
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fondo);
        $s->getFont()->setBold($tipo !== 'promotor')->setSize($total ? 10 : 9)->getColor()->setRGB($total ? self::BLANCO : self::TINTA);
        $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);
        $ws->getStyle("B{$fila}:M{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $s->getBorders()->getBottom()
            ->setBorderStyle($tipo === 'subtotal_sucursal' ? Border::BORDER_MEDIUM : Border::BORDER_THIN)
            ->getColor()->setRGB(['subtotal' => '94A3B8', 'subtotal_sucursal' => '64748B'][$tipo] ?? 'EEF2F7');
        foreach (['E', 'H', 'K'] as $c) {
            $ws->getStyle("{$c}{$fila}")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
        }
        $ws->getRowDimension($fila)->setRowHeight($total ? 22 : ($tipo === 'promotor' ? 16 : 18));

        // Mismos colores condicionales que el PDF; en GRAN TOTAL solo los negativos se distinguen.
        foreach (['sano_pc' => 'B', 'sano_cob' => 'C', 'atraso_pc' => 'E', 'atraso_cob' => 'F', 'vencido_pc' => 'H', 'vencido_cob' => 'I', 'cobranza_total' => 'K'] as $campo => $c) {
            if ((float) $f[$campo] < 0) {
                $ws->getStyle("{$c}{$fila}")->getFont()->getColor()->setRGB($total ? 'FECACA' : self::BAD);
            }
        }
        $mora = (float) $f['mora'];
        if ($total) {
            if ($mora > 0) {
                $ws->getStyle("M{$fila}")->getFont()->getColor()->setRGB('FECACA');
            }

            return;
        }
        if ($mora !== 0.0) {
            $ws->getStyle("M{$fila}")->getFont()->getColor()->setRGB($mora > 0 ? self::BAD : self::OK);
        }
        if ($color = $this->semaforo($f)) {
            $ws->getStyle("L{$fila}")->getFont()->setBold(true)->getColor()->setRGB($color);
        }
    }

    private function cierre(Worksheet $ws, array $r, int $fila): void
    {
        $fila = $this->bloque($ws, $fila, 'Notas metodológicas', array_map(fn ($n) => '• '.$n, $r['notas'] ?? []));

        $incidencias = collect($r['incidencias'] ?? []);
        $control = ['Incidencias de auditoría: '.number_format($incidencias->count())];
        foreach ($incidencias->countBy('codigo') as $codigo => $n) {
            $control[] = $codigo.': '.number_format($n);
        }
        foreach ($r['avisos'] ?? [] as $aviso) {
            $control[] = '⚠ '.$aviso;
        }
        $control[] = 'Semáforo % total: ≥95% verde · ≥90% ámbar · resto rojo.';
        $fila = $this->bloque($ws, $fila + 1, 'Control', $control, ! empty($r['avisos']));

        $ws->mergeCells("A{$fila}:M{$fila}");
        $ws->setCellValue("A{$fila}", 'MR LANA · Reportes — generado '.($r['generado'] ?? ''));
        $ws->getStyle("A{$fila}")->getFont()->setSize(7)->getColor()->setRGB('94A3B8');
    }

    /** @return int primera fila libre después del bloque */
    private function bloque(Worksheet $ws, int $fila, string $titulo, array $lineas, bool $aviso = false): int
    {
        $inicio = $fila;
        $ws->mergeCells("A{$fila}:M{$fila}");
        $ws->setCellValue("A{$fila}", $titulo);
        $ws->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::TINTA);
        $ws->getRowDimension($fila)->setRowHeight(16);
        foreach ($lineas as $linea) {
            $fila++;
            $ws->mergeCells("A{$fila}:M{$fila}");
            $ws->setCellValueExplicit("A{$fila}", (string) $linea, DataType::TYPE_STRING);
            $ws->getStyle("A{$fila}")->getFont()->setSize(8)->getColor()->setRGB('334155');
            $ws->getStyle("A{$fila}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            // Excel no autoajusta el alto de celdas combinadas.
            $ws->getRowDimension($fila)->setRowHeight(12 * max(1, (int) ceil(mb_strlen((string) $linea) / 150)) + 2);
        }
        $caja = $ws->getStyle("A{$inicio}:M{$fila}");
        $caja->getAlignment()->setIndent(1);
        $caja->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($aviso ? 'FCD34D' : 'E2E8F0');
        if ($aviso) {
            $caja->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFBEB');
        }

        return $fila + 1;
    }

    private function estiloAuditoria(Worksheet $ws): void
    {
        $ws->getTabColor()->setRGB('94A3B8');
        $ws->getStyle('A1')->getFont()->setSize(12)->getColor()->setRGB(self::TEAL);
        $encabezado = $ws->getStyle('A5:G5');
        $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::TINTA);
        $encabezado->getFont()->setSize(9)->getColor()->setRGB(self::BLANCO);
        $encabezado->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension(5)->setRowHeight(18);
        $ws->setShowGridlines(false);
    }

    private function banda(Worksheet $ws, int $fila, string $texto, string $bg, string $fg): void
    {
        $ws->mergeCells("A{$fila}:M{$fila}");
        $ws->setCellValueExplicit("A{$fila}", $texto, DataType::TYPE_STRING);
        $s = $ws->getStyle("A{$fila}:M{$fila}");
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
        $s->getFont()->setBold(true)->setSize(9)->getColor()->setRGB($fg);
        $s->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension($fila)->setRowHeight(18);
    }

    private function estiloEncabezado(Worksheet $ws, string $rango, string $bg, string $fg, string $h): void
    {
        $s = $ws->getStyle($rango);
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
        $s->getFont()->setBold(true)->setSize(8)->getColor()->setRGB($fg);
        $s->getAlignment()->setHorizontal($h)->setVertical(Alignment::VERTICAL_CENTER);
    }

    /** @param list<array{0:string,1:bool,2?:string}> $partes texto, negrita y color opcional */
    private function rico(array $partes, int $tamano, string $color): RichText
    {
        $rico = new RichText;
        foreach ($partes as $parte) {
            $fuente = $rico->createTextRun($parte[0])->getFont();
            $fuente->setName(self::FUENTE)->setSize($tamano)->setBold($parte[1]);
            $fuente->getColor()->setRGB($parte[2] ?? ($parte[1] ? self::TINTA : $color));
        }

        return $rico;
    }

    private function semaforo(array $f): ?string
    {
        if ((float) ($f['sano_pc'] ?? 0) <= 0) {
            return null;
        }
        $p = (float) $f['pct_total'];

        return $p >= 0.95 ? self::OK : ($p >= 0.90 ? self::WARN : self::BAD);
    }

    private function alcance(array $r): string
    {
        $f = $r['filtro'] ?? [];
        $sucursales = collect($r['filas'])->where('tipo', 'promotor')->pluck('sucursal')->filter()->unique()->values();

        return collect([
            'Sucursal' => $f['sucursal'] ?? ($sucursales->count() === 1 ? $sucursales->first() : null),
            'Ruta' => $f['oficina'] ?? null,
            'Promotor' => $f['promotor'] ?? null,
        ])->filter()->map(fn ($v, $k) => "$k: $v")->implode(' · ') ?: 'Todas las sucursales';
    }

    private function dmy(string $ymd): string
    {
        return date('d/m/Y', strtotime($ymd));
    }
}
