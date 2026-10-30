<?php

namespace App\Services\Efectividad\Calculadora;

use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Exporta el resultado YA calculado (mismo arreglo que ve la pantalla) a PDF o PNG
 * con Browsershot y una sola plantilla (`efectividad.calculadora-reporte`). Los
 * archivos se generan en un directorio temporal privado y el controlador los
 * borra tras enviarlos (`deleteFileAfterSend`); el directorio auxiliar se borra
 * aquí mismo.
 */
class EfectividadExportService
{
    /** Filas de tabla por imagen PNG: más largas se dividen en varias imágenes numeradas. */
    private const FILAS_POR_IMAGEN = 34;

    public function __construct(
        private readonly BrowsershotPdfRenderer $renderer,
        private readonly EfectividadV4ExcelExporter $excel,
    ) {}

    /** @return string ruta absoluta del PDF temporal */
    public function pdf(array $r): string
    {
        $html = view('efectividad.calculadora-reporte', [
            'r' => $r, 'filas' => $r['filas'], 'imagen' => false, 'conCierre' => true,
        ])->render();
        $salida = $this->tmp('pdf');
        $this->renderer->renderHtmlToFile($html, $salida, [
            'format' => 'A4',
            'landscape' => true,
            'margins' => ['top' => 10, 'right' => 8, 'bottom' => 14, 'left' => 8],
            'footer_left' => 'MR LANA · Efectividad de Cobranza — '.$r['titulo'],
        ]);

        return $salida;
    }

    /**
     * XLSX con EXACTAMENTE el mismo resultado y formato que el PDF/imagen: reutiliza el
     * exportador de la macro V4 (hoja EFECTIVIDAD + bitácora + fuentes). La forma de
     * `filas`, `parametros` e `incidencias` que produce la calculadora es la misma que
     * consume este exportador (es el motor V4), por eso no se transforma nada.
     *
     * @return string ruta absoluta del XLSX temporal
     */
    public function xlsx(array $r): string
    {
        return $this->excel->export(
            $r['filas'],
            $r['parametros'],
            $r['incidencias'] ?? [],
            $this->tmp('xlsx'),
            [],
            EfectividadV4Calculator::ENGINE_VERSION,
        );
    }

    /** @return array{ruta:string, tipo:string} PNG único o ZIP con imágenes numeradas */
    public function png(array $r): array
    {
        $paginas = $this->paginar($r['filas']);
        $total = count($paginas);
        $dir = dirname($this->tmp('d')).DIRECTORY_SEPARATOR.Str::random(24);
        File::ensureDirectoryExists($dir, 0700);
        $imagenes = [];
        try {
            foreach ($paginas as $i => $filas) {
                $html = view('efectividad.calculadora-reporte', [
                    'r' => $r, 'filas' => $filas, 'imagen' => true,
                    'pagina' => $i + 1, 'paginas' => $total, 'conCierre' => $i === $total - 1,
                ])->render();
                $ruta = $dir.DIRECTORY_SEPARATOR.sprintf('efectividad-%02d-de-%02d.png', $i + 1, $total);
                $this->renderer->renderHtmlToImage($html, $ruta, ['width' => 1464, 'scale' => 2]);
                $imagenes[] = $ruta;
            }
            if ($total === 1) {
                $salida = $this->tmp('png');
                rename($imagenes[0], $salida);

                return ['ruta' => $salida, 'tipo' => 'png'];
            }
            $salida = $this->tmp('zip');
            $zip = new ZipArchive;
            $zip->open($salida, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($imagenes as $img) {
                $zip->addFile($img, basename($img));
            }
            $zip->close();

            return ['ruta' => $salida, 'tipo' => 'zip'];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Corta por bloques sin separar un subtotal de su oficina cuando es posible. */
    private function paginar(array $filas): array
    {
        $paginas = [];
        $actual = [];
        foreach ($filas as $f) {
            $actual[] = $f;
            if (count($actual) >= self::FILAS_POR_IMAGEN && $f['tipo'] !== 'promotor') {
                $paginas[] = $actual;
                $actual = [];
            } elseif (count($actual) >= self::FILAS_POR_IMAGEN + 12) {
                $paginas[] = $actual;
                $actual = [];
            }
        }
        if ($actual) {
            $paginas[] = $actual;
        }

        return $paginas ?: [[]];
    }

    private function tmp(string $ext): string
    {
        $dir = EfectividadWorkspace::raiz().DIRECTORY_SEPARATOR.'exports';
        File::ensureDirectoryExists($dir, 0700);

        return $dir.DIRECTORY_SEPARATOR.Str::random(32).'.'.$ext;
    }
}
