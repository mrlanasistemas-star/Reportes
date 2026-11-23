<?php

namespace App\Services\Okr;

/**
 * Folio legible y estable para documentos OKR (Carta Compromiso / Warning) —
 * se calcula DESPUÉS de insertar la fila (usa su id autoincremental, nunca
 * un contador aparte que pudiera desincronizarse).
 *
 * Lo usan OkrDocumentIssuer / OkrCommitmentLetterService / OkrWarningService al
 * emitir el PDF definitivo.
 */
class OkrDocumentFolioGenerator
{
    /**
     * Construye el folio con formato `PREFIJO-AÑO-000123`.
     *
     * Función pura: no toca BD ni estado.
     *
     * @param  string  $prefix  Prefijo del tipo de documento (p. ej. carta compromiso o warning).
     * @param  int  $id  Id autoincremental de la fila del documento ya insertada.
     * @param  \DateTimeInterface|null  $date  Fecha de la que se toma el año; si es null se usa now().
     * @return string Folio legible (id rellenado a 6 dígitos).
     */
    public function forId(string $prefix, int $id, ?\DateTimeInterface $date = null): string
    {
        $year = ($date ?? now())->format('Y');

        return sprintf('%s-%s-%06d', $prefix, $year, $id);
    }
}
