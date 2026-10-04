<?php

namespace App\Services\Okr;

/**
 * Folio legible y estable para documentos OKR (Carta Compromiso / Warning) —
 * se calcula DESPUÉS de insertar la fila (usa su id autoincremental, nunca
 * un contador aparte que pudiera desincronizarse).
 */
class OkrDocumentFolioGenerator
{
    public function forId(string $prefix, int $id, ?\DateTimeInterface $date = null): string
    {
        $year = ($date ?? now())->format('Y');

        return sprintf('%s-%s-%06d', $prefix, $year, $id);
    }
}
