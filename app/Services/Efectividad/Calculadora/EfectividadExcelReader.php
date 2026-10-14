<?php

namespace App\Services\Efectividad\Calculadora;

use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4ColumnMap;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

/**
 * Lee un Excel de Lendus subido a la calculadora de Efectividad y lo clasifica:
 *  - CARTERA: "Saldos por Cliente" (hoja Saldos). La fecha de corte sale del
 *    CONTENIDO ("ANALÍTICO DE SALDOS DE CLIENTES AL AAAA-MM-DD", A4), nunca del
 *    nombre del archivo. Encabezados en la fila donde aparecen Contrato+Substatus
 *    (fila 5 en el exportador actual), datos a partir de la siguiente.
 *  - INGRESOS: "Ingresos Cobranza". Encabezados en la fila donde aparecen
 *    Contrato+Fecha transacción; rango = mín/máx de Fecha transacción.
 *
 * Solo se leen VALORES (OpenSpout no evalúa fórmulas ni ejecuta VBA). Antes de
 * abrirlo se revisa el ZIP para frenar archivos inflados (ZIP bombs).
 */
class EfectividadExcelReader
{
    public const TIPO_CARTERA = 'cartera';

    public const TIPO_INGRESOS = 'ingresos';

    private const MAX_DESCOMPRIMIDO = 300 * 1024 * 1024;

    private const MAX_RATIO = 200;

    private const MAX_ENTRADAS = 5000;

    private const FILAS_BUSQUEDA_ENCABEZADO = 20;

    public function __construct(private readonly EfectividadV4Calculator $calc = new EfectividadV4Calculator) {}

    /**
     * @return array{tipo:string, fecha:?string, desde:?string, hasta:?string, columnas:list<string>, filas:list<array<string,mixed>>, resumen:array<string,mixed>}
     *
     * @throws EfectividadArchivoException
     */
    public function leer(string $ruta): array
    {
        $this->validarZip($ruta);

        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
        try {
            $reader->open($ruta);
        } catch (Throwable) {
            throw new EfectividadArchivoException('El archivo está dañado o no es un Excel válido.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $titulo = [];
                $encabezados = null;
                $tipo = null;
                $mapa = [];
                $filas = [];
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $valores = array_map(fn ($c) => $this->plano($c->getValue()), $row->cells);
                    if ($encabezados === null) {
                        if ($n > self::FILAS_BUSQUEDA_ENCABEZADO) {
                            break;
                        }
                        $tipo = $this->tipoEncabezado($valores);
                        if ($tipo === null) {
                            $titulo[] = implode(' ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $valores)));

                            continue;
                        }
                        $encabezados = $valores;
                        $mapa = EfectividadV4ColumnMap::resolver($valores, $tipo === self::TIPO_CARTERA ? EfectividadV4ColumnMap::FOTO : EfectividadV4ColumnMap::INGRESOS);

                        continue;
                    }
                    $fila = [];
                    $vacia = true;
                    foreach ($mapa as $campo => $i) {
                        $v = $valores[$i] ?? null;
                        $fila[$campo] = $v;
                        if ($v !== null && $v !== '') {
                            $vacia = false;
                        }
                    }
                    if ($vacia) {
                        continue;
                    }
                    if ($tipo === self::TIPO_INGRESOS) {
                        $fila['ref'] = (string) $n;
                    }
                    $filas[] = $fila;
                }
                if ($encabezados !== null) {
                    return $this->resultado($tipo, $titulo, array_keys($mapa), $filas, count($encabezados));
                }
            }
        } catch (EfectividadArchivoException $e) {
            throw $e;
        } catch (Throwable) {
            throw new EfectividadArchivoException('No se pudo leer el contenido del Excel.');
        } finally {
            $reader->close();
        }

        throw new EfectividadArchivoException('No parece un reporte de Lendus: no se encontró el encabezado de "Saldos por Cliente" ni de "Ingresos Cobranza".');
    }

    private function resultado(string $tipo, array $titulo, array $columnas, array $filas, int $totalColumnas): array
    {
        $contratos = [];
        $oficinas = [];
        foreach ($filas as $f) {
            if (($f['contrato'] ?? '') !== '' && $f['contrato'] !== null) {
                $contratos[$this->calc->normalizar($f['contrato'])] = true;
            }
            if (($f['oficina'] ?? '') !== '') {
                $oficinas[trim((string) $f['oficina'])] = true;
            }
        }

        if ($tipo === self::TIPO_CARTERA) {
            $faltantes = array_values(array_diff([...EfectividadV4Calculator::COLUMNAS_FOTO_OBLIGATORIAS, 'contrato'], $columnas));
            if ($faltantes) {
                throw new EfectividadArchivoException('A la cartera le faltan columnas obligatorias: '.implode(', ', $faltantes).'.');
            }
            $fecha = $this->fechaTitulo($titulo);
            if ($fecha === null) {
                throw new EfectividadArchivoException('No se encontró la fecha del reporte ("ANALÍTICO DE SALDOS DE CLIENTES AL …") en el encabezado del archivo.');
            }
            if (! $contratos) {
                throw new EfectividadArchivoException('La cartera no tiene contratos.');
            }

            return [
                'tipo' => $tipo, 'fecha' => $fecha, 'desde' => $fecha, 'hasta' => $fecha,
                'columnas' => $columnas, 'filas' => $filas,
                'resumen' => ['registros' => count($filas), 'contratos' => count($contratos), 'oficinas' => count($oficinas), 'rutas' => array_keys($oficinas), 'columnas_archivo' => $totalColumnas],
            ];
        }

        $faltantes = array_values(array_diff(EfectividadV4Calculator::COLUMNAS_INGRESOS_OBLIGATORIAS, $columnas));
        if ($faltantes) {
            throw new EfectividadArchivoException('Al archivo de ingresos le faltan columnas obligatorias: '.implode(', ', $faltantes).'.');
        }
        $min = null;
        $max = null;
        foreach ($filas as $f) {
            $d = $this->calc->leerFecha($f['fecha_transaccion'] ?? null);
            if ($d !== null) {
                $min = $min === null ? $d : min($min, $d);
                $max = $max === null ? $d : max($max, $d);
            }
        }
        if ($min === null) {
            throw new EfectividadArchivoException('El archivo de ingresos no tiene movimientos con fecha de transacción válida.');
        }
        $desde = $this->calc->ymd($min);
        $hasta = $this->calc->ymd($max);
        // Rango declarado en el encabezado de Lendus ("… 2026-10-05 A 2026-10-11"): un día
        // sin cobros sigue estando cubierto por el archivo.
        foreach ($titulo as $linea) {
            if (preg_match('/(\d{4}-\d{2}-\d{2})\s+(?:A|AL)\s+(\d{4}-\d{2}-\d{2})/', $this->calc->normalizar($linea), $m) && $m[1] <= $desde && $m[2] >= $hasta) {
                [$desde, $hasta] = [$m[1], $m[2]];
                break;
            }
        }

        return [
            'tipo' => $tipo, 'fecha' => null, 'desde' => $desde, 'hasta' => $hasta,
            'columnas' => $columnas, 'filas' => $filas,
            'resumen' => ['registros' => count($filas), 'contratos' => count($contratos), 'oficinas' => count($oficinas), 'rutas' => array_keys($oficinas), 'columnas_archivo' => $totalColumnas],
        ];
    }

    private function tipoEncabezado(array $valores): ?string
    {
        $norm = array_map(fn ($v) => $this->calc->normalizar($v), $valores);
        if (! in_array('CONTRATO', $norm, true)) {
            return null;
        }
        if (in_array('SUBSTATUS', $norm, true) || in_array('SUBESTATUS', $norm, true)) {
            return self::TIPO_CARTERA;
        }
        if (in_array('FECHA TRANSACCION', $norm, true)) {
            return self::TIPO_INGRESOS;
        }

        return null;
    }

    /** "ANALÍTICO DE SALDOS DE CLIENTES AL 2026-10-04" (acepta también dd/mm/aaaa). */
    private function fechaTitulo(array $titulo): ?string
    {
        foreach ($titulo as $linea) {
            $t = $this->calc->normalizar($linea);
            if (! str_contains($t, 'SALDOS')) {
                continue;
            }
            if (preg_match('/\bAL\s+(\d{4})-(\d{2})-(\d{2})/', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
            if (preg_match('/\bAL\s+(\d{2})\/(\d{2})\/(\d{4})/', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }
        }

        return null;
    }

    private function plano(mixed $v): mixed
    {
        return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : $v;
    }

    private function validarZip(string $ruta): void
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new EfectividadArchivoException('El archivo está dañado o no es un Excel (.xlsx) válido.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRADAS || $zip->locateName('[Content_Types].xml') === false) {
                throw new EfectividadArchivoException('El archivo no tiene la estructura de un Excel válido.');
            }
            $total = 0;
            $comprimido = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $total += (int) ($st['size'] ?? 0);
                $comprimido += (int) ($st['comp_size'] ?? 0);
            }
            if ($total > self::MAX_DESCOMPRIMIDO || ($comprimido > 0 && $total / $comprimido > self::MAX_RATIO)) {
                throw new EfectividadArchivoException('El archivo es demasiado grande al descomprimirse; no se procesará.');
            }
        } finally {
            $zip->close();
        }
    }
}
