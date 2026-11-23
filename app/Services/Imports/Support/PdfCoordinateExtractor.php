<?php

namespace App\Services\Imports\Support;

use Smalot\PdfParser\Page;

/**
 * Reconstruye filas/columnas visuales de una página PDF usando las coordenadas
 * de cada palabra, en lugar de confiar en el orden lineal que devuelve getText().
 *
 * Algunos generadores de PDF dibujan los textos fuera del orden visual (p. ej.
 * los encabezados de sección se emiten después del cuerpo de las tablas), lo que
 * rompe un parseo ingenuo línea por línea. Agrupar palabras por su posición Y
 * (con una pequeña tolerancia para desfases de subíndice/superíndice) y ordenar
 * por X dentro de cada fila reconstruye la tabla tal como la leería una persona.
 * Lo usan los importadores de PDF (gastos Lendus, IMSS).
 */
class PdfCoordinateExtractor
{
    /**
     * Extrae las filas visuales de la página: toma cada texto con su matriz de
     * transformación (getDataTm), lo asigna a la primera fila cuya Y esté dentro de
     * la tolerancia y ordena tokens y filas.
     *
     * @param  Page  $page  Página PDF (smalot/pdfparser).
     * @param  float  $yTolerance  Diferencia máxima de Y (puntos PDF) para considerar dos palabras en la misma fila.
     * @return array<int, array{y: float, tokens: array<int, array{x: float, text: string}>, text: string}>
     *                                                                                                      Filas de arriba hacia abajo (Y del PDF descendente); tokens de izquierda a derecha.
     */
    public static function extractRows(Page $page, float $yTolerance = 1.5): array
    {
        $words = [];
        foreach ($page->getDataTm() as $entry) {
            $tm = $entry[0] ?? null;
            $text = trim((string) ($entry[1] ?? ''));
            if ($text === '' || ! is_array($tm)) {
                continue;
            }
            $words[] = [
                'x' => (float) ($tm[4] ?? 0),
                'y' => (float) ($tm[5] ?? 0),
                'text' => $text,
            ];
        }

        if (empty($words)) {
            return [];
        }

        usort($words, static fn (array $a, array $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);

        $rows = [];
        foreach ($words as $w) {
            $target = null;
            foreach ($rows as $idx => $row) {
                if (abs($row['y'] - $w['y']) <= $yTolerance) {
                    $target = $idx;
                    break;
                }
            }
            if ($target === null) {
                $rows[] = ['y' => $w['y'], 'tokens' => []];
                $target = array_key_last($rows);
            }
            $rows[$target]['tokens'][] = ['x' => $w['x'], 'text' => $w['text']];
        }

        foreach ($rows as &$row) {
            usort($row['tokens'], static fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $row['text'] = implode(' ', array_column($row['tokens'], 'text'));
        }
        unset($row);

        usort($rows, static fn (array $a, array $b) => $b['y'] <=> $a['y']);

        return array_values($rows);
    }

    /**
     * Devuelve el texto del token cuya X está más cerca de $targetX, o null si la
     * fila está vacía o el token más cercano está más lejos que $maxDistance.
     * Sirve para leer la celda de una columna conocida por su posición X.
     *
     * @param  array  $row  Fila devuelta por extractRows().
     * @param  float  $targetX  Posición X de la columna buscada.
     * @param  float  $maxDistance  Distancia máxima aceptada.
     * @return string|null Texto de la celda o null.
     */
    public static function nearestToken(array $row, float $targetX, float $maxDistance = 8.0): ?string
    {
        $best = null;
        $bestDist = INF;
        foreach ($row['tokens'] ?? [] as $tok) {
            $d = abs($tok['x'] - $targetX);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $tok['text'];
            }
        }

        return ($best !== null && $bestDist <= $maxDistance) ? $best : null;
    }

    /**
     * Convierte a mayúsculas y quita acentos/Ñ para comparar textos de encabezados.
     *
     * @param  string  $value  Texto original.
     * @return string Texto normalizado.
     */
    public static function normalizeUpper(string $value): string
    {
        $value = trim(mb_strtoupper($value, 'UTF-8'));

        return strtr($value, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
}
