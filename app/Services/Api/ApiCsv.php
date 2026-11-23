<?php

namespace App\Services\Api;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Salida CSV opcional (`format=csv`) de la API v1. Aplana cada fila con
 * claves "a.b.c"; las LISTAS anidadas (detalles por concepto/producto/ruta) se
 * serializan como JSON en una sola celda para no perder información. UTF-8 con
 * BOM para que Excel abra acentos correctamente.
 *
 * La usan los controladores de la API v1 (colaboradores, KPIs por periodo,
 * actividad semanal/mensual) para ofrecer la misma respuesta en CSV.
 */
class ApiCsv
{
    /**
     * Determina si el consumidor pidió la respuesta en CSV (`?format=csv`).
     *
     * Valida el parámetro `format` contra los valores permitidos (json|csv);
     * por defecto es json.
     *
     * @param  Request  $request  Petición HTTP de la API.
     * @return bool true si se solicitó CSV.
     *
     * @throws HttpException 422 si `format` no es json ni csv.
     */
    public static function wantsCsv(Request $request): bool
    {
        return ApiValidation::enumValue($request, 'format', ['json', 'csv'], 'json') === 'csv';
    }

    /**
     * Genera la descarga CSV a partir de las filas de la respuesta.
     *
     * Aplana cada fila, construye el encabezado como la UNIÓN de todas las
     * claves encontradas (en orden de aparición) y escribe el archivo por
     * streaming a php://output, con BOM UTF-8 al inicio. Las celdas faltantes
     * en una fila quedan vacías.
     *
     * @param  list<array>  $rows  Filas (arreglos asociativos, posiblemente anidados) a exportar.
     * @param  string  $filename  Nombre del archivo que verá el usuario al descargar.
     * @return StreamedResponse Respuesta de descarga con Content-Type text/csv.
     */
    public static function response(array $rows, string $filename): StreamedResponse
    {
        $flat = array_map(fn (array $row) => self::flatten($row), $rows);

        $headers = [];
        foreach ($flat as $row) {
            foreach (array_keys($row) as $key) {
                $headers[$key] = true;
            }
        }
        $headers = array_keys($headers);

        return response()->streamDownload(function () use ($flat, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($flat as $row) {
                fputcsv($out, array_map(fn ($h) => self::cell($row[$h] ?? null), $headers));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Aplana recursivamente un arreglo asociativo usando claves con puntos
     * ("a.b.c").
     *
     * Solo se descienden los arreglos asociativos no vacíos; las listas y los
     * arreglos vacíos se conservan tal cual como valor de la clave (después
     * cell() los serializa como JSON).
     *
     * @param  array  $row  Fila a aplanar.
     * @param  string  $prefix  Prefijo acumulado de la ruta de claves (vacío en la raíz).
     * @return array<string, mixed> Mapa clave-con-puntos => valor escalar o lista.
     */
    public static function flatten(array $row, string $prefix = ''): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += self::flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /**
     * Convierte un valor a texto para una celda CSV.
     *
     * null → cadena vacía; booleanos → "true"/"false"; arreglos (listas
     * anidadas) → JSON sin escapar Unicode; cualquier otro valor → cast a string.
     *
     * @param  mixed  $value  Valor de la celda.
     * @return string Texto a escribir en el CSV.
     */
    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
