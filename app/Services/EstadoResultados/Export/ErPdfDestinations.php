<?php

namespace App\Services\EstadoResultados\Export;

/**
 * Lee del PDF que genera Chrome (Skia) la PÁGINA REAL de cada destino con nombre: cada ancla
 * HTML (id="…") a la que apunta una liga interna queda en el diccionario /Dests del catálogo
 * como «/id [obj 0 R /XYZ …]». Se recorre el árbol /Pages para traducir el objeto a número de
 * página (1 = portada). Sirve para imprimir números de página verdaderos en el índice del PDF.
 *
 * Es deliberadamente tolerante: si la estructura no es la esperada devuelve [] y el índice se
 * muestra sin números (nunca con números inventados).
 */
final class ErPdfDestinations
{
    /** @return array<string,int> id del ancla => número de página (base 1) */
    public static function pages(string $pdfPath): array
    {
        try {
            $pdf = (string) @file_get_contents($pdfPath);
            if ($pdf === '' || ! preg_match('#/Type\s*/Catalog\b(.*?)>>\s*endobj#s', $pdf, $cat)) {
                return [];
            }
            if (! preg_match('#/Pages\s+(\d+)\s+0\s+R#', $cat[1], $root)) {
                return [];
            }
            $order = [];
            self::walk($pdf, (int) $root[1], $order, 0);
            $index = array_flip($order);

            if (preg_match('#/Dests\s+(\d+)\s+0\s+R#', $cat[1], $d)) {
                $dests = self::object($pdf, (int) $d[1]) ?? '';
            } elseif (preg_match('#/Dests\s*(<<.*?>>)#s', $cat[1], $d)) {
                $dests = $d[1];
            } else {
                return [];
            }
            // Forma de diccionario (/id [n 0 R …]) o de árbol de nombres ((id) [n 0 R …]).
            preg_match_all('#(?:/([A-Za-z0-9_.\-]+)|\(([A-Za-z0-9_.\-]+)\))\s*\[\s*(\d+)\s+0\s+R#', $dests, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as $hit) {
                $name = $hit[1] !== '' ? $hit[1] : $hit[2];
                $obj = $hit[3];
                if (isset($index[(int) $obj])) {
                    $out[$name] = $index[(int) $obj] + 1;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function walk(string $pdf, int $obj, array &$order, int $depth): void
    {
        if ($depth > 32) {
            return;
        }
        $body = self::object($pdf, $obj);
        if ($body === null) {
            return;
        }
        if (preg_match('#/Type\s*/Pages\b#', $body) && preg_match('#/Kids\s*\[([^\]]*)\]#', $body, $k)) {
            preg_match_all('#(\d+)\s+0\s+R#', $k[1], $kids);
            foreach ($kids[1] as $kid) {
                self::walk($pdf, (int) $kid, $order, $depth + 1);
            }

            return;
        }
        if (preg_match('#/Type\s*/Page\b#', $body)) {
            $order[] = $obj;
        }
    }

    private static function object(string $pdf, int $obj): ?string
    {
        return preg_match('#(?:^|[\r\n])'.$obj.'\s+0\s+obj\s*(<<.*?)\s*(?:endobj|stream\b)#s', $pdf, $m) ? $m[1] : null;
    }
}
