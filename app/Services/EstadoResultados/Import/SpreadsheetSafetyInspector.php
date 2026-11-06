<?php

namespace App\Services\EstadoResultados\Import;

/**
 * Inspección de seguridad de un Excel ANTES de que PhpSpreadsheet lo cargue.
 *
 * .xlsx / .xlsm (contenedor ZIP):
 *   - el ZIP debe abrir y ser consistente (ZipArchive::CHECKCONS) → si no, archivo dañado;
 *   - límites del directorio central: número de entradas, tamaño descomprimido por
 *     entrada y total, y razón de compresión de las entradas grandes (bomba ZIP);
 *   - además se DESCOMPRIME en streaming cada entrada XML contando bytes reales (el
 *     tamaño declarado en el directorio central puede mentir): se aborta en cuanto
 *     supera lo declarado o el límite;
 *   - macros: xl/vbaProject.bin en un .xlsm se acepta SÓLO VALORES (PhpSpreadsheet no
 *     ejecuta VBA; además se lee en modo sólo datos) con aviso informativo. En un .xlsx
 *     es incoherente (Excel nunca guarda macros en .xlsx) ⇒ se rechaza como archivo alterado;
 *   - vínculos externos (xl/externalLinks/*): se informan; las celdas que los USAN se
 *     rechazan en el parser (nunca se evalúan).
 *
 * .xls (OLE): no hay directorio ZIP; aplica el límite de tamaño del archivo y los de
 * filas/columnas (listWorksheetInfo) del parser. Se detecta el flujo _VBA_PROJECT
 * para el mismo aviso de macros.
 */
class SpreadsheetSafetyInspector
{
    public const SEVERITY_INFO = 'informativa';

    public const SEVERITY_BLOCKING = 'bloqueante';

    /**
     * @return array{has_macros:bool, external_links:bool, entries:int, uncompressed_bytes:int, warnings:list<array{code:string,severity:string,message:string}>}
     *
     * @throws EstadoResultadosImportException
     */
    public function inspect(string $absolutePath, string $extension): array
    {
        $ext = mb_strtolower($extension);
        if ($ext === 'xls' && ! $this->isZip($absolutePath)) {
            return $this->inspectOle($absolutePath);
        }

        return $this->inspectZip($absolutePath, $ext);
    }

    private function inspectZip(string $path, string $ext): array
    {
        $cfg = (array) config('estado_resultados.import.zip', []);
        $maxEntries = (int) ($cfg['max_entries'] ?? 2000);
        $maxTotal = (int) round(((float) ($cfg['max_uncompressed_mb'] ?? 150)) * 1048576);
        $maxEntry = (int) round(((float) ($cfg['max_entry_mb'] ?? 100)) * 1048576);
        $maxRatio = (float) ($cfg['max_ratio'] ?? 100);
        $ratioFrom = (int) round(((float) ($cfg['ratio_min_mb'] ?? 1)) * 1048576);

        $zip = new \ZipArchive;
        $opened = $zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new EstadoResultadosImportException(
                'El archivo está dañado o no es un Excel válido (el contenedor del libro no se pudo abrir).',
                ['Ábrelo en Excel y guárdalo de nuevo como "Libro de Excel (.xlsx)".'],
            );
        }

        try {
            $count = $zip->numFiles;
            if ($count <= 0 || $count > $maxEntries) {
                throw new EstadoResultadosImportException(
                    $count <= 0 ? 'El archivo está dañado: el libro no contiene partes.' : 'El archivo contiene '.number_format($count).' partes internas (máximo '.number_format($maxEntries).'): no parece un estado de cuenta.',
                );
            }

            $total = 0;
            $names = [];
            for ($i = 0; $i < $count; $i++) {
                $s = $zip->statIndex($i);
                if ($s === false) {
                    throw new EstadoResultadosImportException('El archivo está dañado: no se pudo leer su índice interno.');
                }
                $name = (string) $s['name'];
                $size = (int) $s['size'];
                $comp = (int) $s['comp_size'];
                $names[$name] = true;
                $total += $size;
                if ($size > $maxEntry || $total > $maxTotal) {
                    throw new EstadoResultadosImportException(
                        'El archivo se expande a más de '.$this->mb($size > $maxEntry ? $maxEntry : $maxTotal).' al abrirlo: se rechaza por seguridad (posible archivo malicioso o corrupto).',
                        ['Un estado de cuenta mensual ocupa unos pocos MB. Verifica que sea el archivo correcto.'],
                    );
                }
                if ($size >= $ratioFrom && ($comp <= 0 || $size / $comp > $maxRatio)) {
                    throw new EstadoResultadosImportException(
                        'El archivo tiene una compresión anómala ('.($comp > 0 ? number_format($size / $comp, 0).':1' : 'sin datos comprimidos').' en «'.mb_substr($name, 0, 80).'»): se rechaza por seguridad (posible bomba ZIP).',
                    );
                }
            }

            if (! isset($names['[Content_Types].xml']) || ! isset($names['xl/workbook.xml'])) {
                throw new EstadoResultadosImportException(
                    'El archivo está dañado o no es un libro de Excel (faltan partes obligatorias).',
                    ['Ábrelo en Excel y guárdalo de nuevo como "Libro de Excel (.xlsx)".'],
                );
            }

            // Tamaño REAL descomprimido de las partes XML (las que PhpSpreadsheet va a leer).
            $real = 0;
            foreach (array_keys($names) as $name) {
                if (! preg_match('/\.(xml|rels|vml)$/i', $name)) {
                    continue;
                }
                $declared = (int) ($zip->statName($name)['size'] ?? 0);
                $read = $this->streamSize($zip, $name, min($maxEntry, $declared) + 1);
                if ($read === null) {
                    throw new EstadoResultadosImportException('El archivo está dañado: la parte «'.mb_substr($name, 0, 80).'» no se pudo descomprimir.');
                }
                if ($read > $declared) {
                    throw new EstadoResultadosImportException(
                        'El archivo se expande más de lo que declara («'.mb_substr($name, 0, 80).'»): se rechaza por seguridad (posible bomba ZIP).',
                    );
                }
                $real += $read;
            }

            $warnings = [];
            $hasMacros = isset($names['xl/vbaProject.bin']);
            if ($hasMacros && $ext !== 'xlsm') {
                throw new EstadoResultadosImportException(
                    'El archivo .'.$ext.' contiene macros (VBA), lo que no corresponde a ese formato: se rechaza por seguridad (archivo alterado o renombrado).',
                    ['Si el archivo debe llevar macros, guárdalo como .xlsm; si no, guárdalo de nuevo como "Libro de Excel (.xlsx)".'],
                );
            }
            if ($hasMacros) {
                $warnings[] = ['code' => 'macros', 'severity' => self::SEVERITY_INFO, 'message' => 'El archivo contiene macros: no se ejecutan, sólo se leen valores.'];
            }
            $external = false;
            foreach (array_keys($names) as $name) {
                if (str_starts_with($name, 'xl/externalLinks/')) {
                    $external = true;
                    break;
                }
            }

            return ['has_macros' => $hasMacros, 'external_links' => $external, 'entries' => $count, 'uncompressed_bytes' => max($total, $real), 'warnings' => $warnings];
        } finally {
            $zip->close();
        }
    }

    private function inspectOle(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $vba = str_contains($raw, mb_convert_encoding('_VBA_PROJECT', 'UTF-16LE', 'UTF-8'));

        return [
            'has_macros' => $vba,
            'external_links' => false,
            'entries' => 0,
            'uncompressed_bytes' => strlen($raw),
            'warnings' => $vba ? [['code' => 'macros', 'severity' => self::SEVERITY_INFO, 'message' => 'El archivo contiene macros: no se ejecutan, sólo se leen valores.']] : [],
        ];
    }

    /** Bytes reales descomprimidos de una parte, leyendo como máximo $limit (null = no se pudo leer). */
    private function streamSize(\ZipArchive $zip, string $name, int $limit): ?int
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }
        $read = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) {
                    return null;
                }
                $read += strlen($chunk);
                if ($read >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($stream);
        }

        return $read;
    }

    private function isZip(string $path): bool
    {
        return str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04");
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.').' MB';
    }
}
