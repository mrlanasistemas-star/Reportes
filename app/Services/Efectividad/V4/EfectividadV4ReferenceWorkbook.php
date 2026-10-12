<?php

namespace App\Services\Efectividad\V4;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * HERRAMIENTA DE DESARROLLO/VALIDACIÓN — no forma parte del flujo productivo.
 *
 * Lee el libro de referencia `MR_LANA_EFECTIVIDAD.xlsm` (solo valores; las
 * macros jamás se ejecutan: OpenSpout no interpreta VBA) y lo convierte en:
 *  - la entrada exacta que la macro usó (8 fotografías, INGRESOS,
 *    _HIST_INGRESOS ya actualizado y el control de corte de EFECTIVIDAD!Q2:Q6);
 *  - los resultados esperados (hoja EFECTIVIDAD) y las incidencias esperadas
 *    (hoja _AUDITORIA_EFECTIVIDAD).
 *
 * `_HIST_INGRESOS` guardado en el libro es el estado POSTERIOR a la última
 * ejecución de la macro (ActualizarHistorialIngresos ya reemplazó la semana y
 * agregó INGRESOS), es decir, exactamente la hoja que leyó `ProcesarCobros`.
 * La referencia de cada movimiento es su número de fila en esa hoja, como en
 * los ID de incidencia de la macro (`NO-CARTERA|contrato|fila`).
 */
class EfectividadV4ReferenceWorkbook
{
    public function load(string $path, ?string $cacheDir = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("No existe el libro de referencia: {$path}");
        }
        $cacheFile = null;
        if ($cacheDir) {
            $cacheFile = rtrim($cacheDir, '/\\').'/efectividad-v4-ref-v2-'.sha1_file($path).'.json';
            if (is_file($cacheFile)) {
                return json_decode(file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $sheets = $this->readSheets($path);
        $data = $this->build($sheets);

        if ($cacheFile) {
            @mkdir(dirname($cacheFile), 0775, true);
            file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $data;
    }

    /** @return array<string, list<list<mixed>>> */
    private function readSheets(string $path): array
    {
        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $vals = [];
                foreach ($row->cells as $cell) {
                    $v = $cell->getValue();
                    // Fechas marcadas para poder reescribir el archivo con su tipo real (pruebas de importación).
                    $vals[] = $v instanceof DateTimeInterface ? ['__d' => $v->format('Y-m-d')] : $v;
                }
                $rows[] = $vals;
            }
            $out[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $out;
    }

    private function build(array $sheets): array
    {
        foreach (array_merge(EfectividadV4Calculator::NOMBRES_FOTO, ['INGRESOS', '_HIST_INGRESOS', 'EFECTIVIDAD']) as $req) {
            if (! isset($sheets[$req])) {
                throw new RuntimeException("El libro no contiene la hoja {$req}.");
            }
        }

        $fotos = [];
        $inventario = [];
        foreach (EfectividadV4Calculator::NOMBRES_FOTO as $i => $nombre) {
            $fotos[$i] = $this->tabla($sheets[$nombre], EfectividadV4ColumnMap::FOTO, false) + ['nombre' => $nombre];
            $inventario[$nombre] = ['filas' => count($fotos[$i]['filas']), 'columnas_archivo' => count($sheets[$nombre][0] ?? [])];
        }
        $ingresos = $this->tabla($sheets['INGRESOS'], EfectividadV4ColumnMap::INGRESOS, true);
        $historial = $this->tabla($sheets['_HIST_INGRESOS'], EfectividadV4ColumnMap::INGRESOS, true);
        $inventario['INGRESOS'] = ['filas' => count($ingresos['filas']), 'columnas_archivo' => count($sheets['INGRESOS'][0] ?? [])];
        $inventario['_HIST_INGRESOS'] = ['filas' => count($historial['filas']), 'columnas_archivo' => count($sheets['_HIST_INGRESOS'][0] ?? [])];

        $ef = $sheets['EFECTIVIDAD'];
        $q = fn (int $fila) => $this->plano($ef[$fila - 1][16] ?? null); // columna Q
        $control = [
            'modo' => (string) $q(2),
            'domingo_base' => $q(3),
            'fecha_corte' => $q(4),
            'inicio_ingresos' => $q(5),
            'fin_ingresos' => $q(6),
            'periodo' => (string) ($ef[1][0] ?? ''),
            'leyenda' => (string) ($ef[2][0] ?? ''),
        ];

        return [
            'control' => $control,
            'fotos' => $fotos,
            'ingresos' => $ingresos,
            'historial' => $historial,
            'esperado' => $this->esperado($ef),
            'incidencias_esperadas' => $this->incidencias($sheets['_AUDITORIA_EFECTIVIDAD'] ?? []),
            'inventario' => $inventario,
            // Hojas fuente tal cual (todas sus columnas) para reconstruir los Excel de Lendus en pruebas.
            'crudo' => array_intersect_key($sheets, array_flip([...EfectividadV4Calculator::NOMBRES_FOTO, '_HIST_INGRESOS', 'INGRESOS'])),
        ];
    }

    /** Fila 1 = encabezados (la macro exige eliminar las filas de título del exportado). */
    private function tabla(array $rows, array $mapa, bool $conRef): array
    {
        $idx = EfectividadV4ColumnMap::resolver($rows[0] ?? [], $mapa);
        $filas = [];
        $n = count($rows);
        for ($r = 1; $r < $n; $r++) {
            $row = $rows[$r];
            $fila = [];
            foreach ($idx as $campo => $c) {
                $fila[$campo] = $this->plano($row[$c] ?? null);
            }
            if ($conRef) {
                $fila['ref'] = (string) ($r + 1);
            }
            $filas[] = $fila;
        }

        return ['columnas' => array_keys($idx), 'filas' => $filas];
    }

    private function plano(mixed $v): mixed
    {
        return is_array($v) && isset($v['__d']) ? $v['__d'] : $v;
    }

    /** Hoja EFECTIVIDAD → filas A-M tipificadas (encabezado de oficina, promotor, subtotal, gran total). */
    private function esperado(array $ef): array
    {
        $campos = ['sano_pc', 'sano_cob', 'sano_pct', 'atraso_pc', 'atraso_cob', 'atraso_pct', 'vencido_pc', 'vencido_cob', 'vencido_pct', 'cobranza_total', 'pct_total', 'mora'];
        $out = [];
        $oficina = null;
        for ($r = 5; $r < count($ef); $r++) {
            $row = $ef[$r];
            $a = trim((string) ($row[0] ?? ''));
            if ($a === '') {
                continue;
            }
            $b = $row[1] ?? '';
            if ($b === '' || $b === null) {
                $oficina = $a; // fila de oficina (A:M combinada)

                continue;
            }
            $tipo = $a === 'GRAN TOTAL' ? 'gran_total' : (str_starts_with($a, 'TOTAL ') && $oficina !== null && $a === 'TOTAL '.$oficina ? 'subtotal' : 'promotor');
            $fila = ['fila_excel' => $r + 1, 'tipo' => $tipo, 'oficina' => $tipo === 'gran_total' ? null : $oficina, 'promotor' => $a];
            foreach ($campos as $i => $c) {
                $fila[$c] = is_numeric($row[$i + 1] ?? null) ? (float) $row[$i + 1] : null;
            }
            $out[] = $fila;
        }

        return $out;
    }

    private function incidencias(array $rows): array
    {
        $out = [];
        for ($r = 5; $r < count($rows); $r++) {
            $id = trim((string) ($rows[$r][0] ?? ''));
            if ($id !== '') {
                $out[] = ['id' => $id, 'descripcion' => (string) ($rows[$r][1] ?? '')];
            }
        }

        return $out;
    }
}
