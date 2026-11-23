<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Detecta y cuantifica las filas de Savehearts (seguros) en Lendus Ingresos Cobranza.
 *
 * Reglas de negocio:
 * - Savehearts que NO es CRECE: se excluye por completo de todas las métricas.
 * - Savehearts CRECE: se excluye de la recuperación normal; solo el 30% del Total
 *   entra al EBITDA como componente aparte (ingreso_savehearts_crece_30).
 *
 * Lo usan los importadores de Lendus y los cálculos de recuperación/EBITDA. No toca BD.
 */
class SaveheartsRuleService
{
    // Palabras clave (normalizadas) que identifican Savehearts/seguro en la columna Operación
    private const OPERATION_KEYWORDS = ['seguro', 'seguros', 'poliza', 'polizas', 'cobertura'];

    // Palabras clave (normalizadas) que identifican Savehearts en la columna Concepto
    private const CONCEPT_KEYWORDS = ['savehearts', 'cobertura savehearts', 'cobertura credito grupal', 'cobertura'];

    // Palabras clave (normalizadas) que identifican Savehearts en la columna Producto
    private const PRODUCT_KEYWORDS = ['seguro', 'seguros', 'savehearts', 'cobertura'];

    // Palabra clave del producto CRECE
    private const CRECE_KEYWORD = 'crece';

    /**
     * Indica si una fila ya mapeada es un movimiento de Savehearts/seguro.
     *
     * Revisa, en este orden, si contiene alguna palabra clave:
     *   - row['operation'] (SEGURO, SEGUROS, POLIZA, COBERTURA…)
     *   - row['concept'] (SAVEHEARTS, COBERTURA SAVEHEARTS, COBERTURA…)
     *   - row['product_name'] (SEGURO, SAVEHEARTS, COBERTURA…)
     *
     * Sin distinguir mayúsculas, acentos ni espacios extra.
     *
     * @param  array{operation?: string|null, concept?: string|null, product_name?: string|null}  $row  Fila normalizada del importador.
     * @return bool true si es fila de seguro/Savehearts.
     */
    public function isSaveheartsRow(array $row): bool
    {
        $op = $this->normalize($row['operation'] ?? '');
        $concept = $this->normalize($row['concept'] ?? '');
        $product = $this->normalize($row['product_name'] ?? '');

        foreach (self::OPERATION_KEYWORDS as $kw) {
            if ($op !== '' && str_contains($op, $this->normalize($kw))) {
                return true;
            }
        }

        foreach (self::CONCEPT_KEYWORDS as $kw) {
            if ($concept !== '' && str_contains($concept, $this->normalize($kw))) {
                return true;
            }
        }

        foreach (self::PRODUCT_KEYWORDS as $kw) {
            if ($product !== '' && str_contains($product, $this->normalize($kw))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si el producto es cualquier variante de CRECE.
     * Cubre: CRECE, CRECE12, CRECE 12, CRECE SAC, CRECE12 SAC,
     * CRECE CON/SIN ACTIVIDAD COMERCIAL, CRECE24, CRECE 24.
     *
     * @param  string  $product  Nombre del producto tal como viene de Lendus.
     * @param  int|null  $duration  Plazo de otra columna; aquí no se usa (solo para subclasificar con creceProductLabel()).
     * @return bool true si el nombre normalizado contiene "crece".
     */
    public function isCreceProduct(string $product, ?int $duration = null): bool
    {
        $norm = $this->normalize($product);
        if (! str_contains($norm, self::CRECE_KEYWORD)) {
            return false;
        }

        // Any product whose normalized form contains 'crece' is a CRECE product.
        // The duration parameter is used externally for sub-classification only.
        return true;
    }

    /**
     * Devuelve la etiqueta canónica del producto CRECE ("CRECE 24", "CRECE 12" o "CRECE").
     *
     * Usa el número dentro del nombre o, si no lo trae, el plazo de otra columna.
     *
     * @param  string  $product  Nombre del producto.
     * @param  int|null  $duration  Plazo (12 o 24) cuando viene en columna aparte.
     * @return string Etiqueta canónica; "CRECE" si no se reconoce plazo (o el producto no es CRECE).
     */
    public function creceProductLabel(string $product, ?int $duration = null): string
    {
        $norm = $this->normalize($product);

        if (str_contains($norm, 'crece') && (str_contains($norm, '24') || $duration === 24)) {
            return 'CRECE 24';
        }
        if (str_contains($norm, 'crece') && (str_contains($norm, '12') || $duration === 12)) {
            return 'CRECE 12';
        }

        return 'CRECE';
    }

    /**
     * Calcula la participación de la empresa (30% del Total) en filas Savehearts CRECE.
     * Devuelve 0.0 si la fila no es Savehearts o el producto no es CRECE.
     *
     * @param  array{operation?: string|null, concept?: string|null, product_name?: string|null, total?: float|null, total_amount?: float|null}  $row  Fila normalizada; usa 'total' o, en su defecto, 'total_amount'.
     * @return float Monto redondeado a 2 decimales que va al EBITDA.
     */
    public function saveheartsCompanyShare(array $row): float
    {
        if (! $this->isSaveheartsRow($row)) {
            return 0.0;
        }

        if (! $this->isCreceProduct((string) ($row['product_name'] ?? ''))) {
            return 0.0;
        }

        $total = (float) ($row['total'] ?? $row['total_amount'] ?? 0.0);

        return round($total * 0.30, 2);
    }

    /**
     * Normaliza texto para comparar palabras clave: ASCII sin acentos, minúsculas,
     * signos convertidos a espacio y espacios colapsados.
     *
     * @param  string  $s  Texto original.
     * @return string Texto normalizado.
     */
    private function normalize(string $s): string
    {
        return (string) Str::of($s)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }
}
