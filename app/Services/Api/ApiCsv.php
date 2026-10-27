<?php

namespace App\Services\Api;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Salida CSV opcional (`format=csv`) de la API v1. Aplana cada fila con
 * claves "a.b.c"; las LISTAS anidadas (detalles por concepto/producto/ruta) se
 * serializan como JSON en una sola celda para no perder información. UTF-8 con
 * BOM para que Excel abra acentos correctamente.
 */
class ApiCsv
{
    public static function wantsCsv(Request $request): bool
    {
        return ApiValidation::enumValue($request, 'format', ['json', 'csv'], 'json') === 'csv';
    }

    /** @param list<array> $rows */
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
