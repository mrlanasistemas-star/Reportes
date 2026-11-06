<?php

namespace App\Services\EstadoResultados\Support;

/**
 * Normaliza folios de requisición (REQ) para compararlos entre el ERP
 * (fact_expenses.raw_payload->folio) y los estados de cuenta complementarios.
 *
 *   "REQ-R5DQK" / "req r5dqk" / "R5DQK" / " REQ_R5DQK " → "R5DQK"
 *
 * "S/R", "SR", "SIN REQ", "N/A" y vacíos → null (sin folio).
 */
final class FolioNormalizer
{
    private const NO_FOLIO = ['S/R', 'SR', 'S-R', 'SIN REQ', 'SIN FOLIO', 'N/A', 'NA', 'NO APLICA', '-', '0'];

    public static function normalize(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = mb_strtoupper(trim((string) $raw));
        if ($value === '' || in_array($value, self::NO_FOLIO, true)) {
            return null;
        }

        $value = preg_replace('/^REQ(?:UISICION)?[\s\-_.#:]*/u', '', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9]/u', '', $value) ?? $value;

        return $value === '' ? null : $value;
    }
}
