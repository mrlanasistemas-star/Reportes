<?php

namespace App\Services\EstadoResultados\Export;

/**
 * Sección «Retiros DG y socios» de las exportaciones (PDF y Excel) del Estado de Resultados.
 * FUENTE ÚNICA: el bloque `withdrawals` del dataset (EstadoResultadosDatasetBuilder); aquí NO se
 * calcula ninguna cifra: sólo se ordena el payload y se agregan rótulos.
 *
 * Regla (25-nov-2026): lo que toman Dirección General y los socios no es OPEX, no cambia el
 * EBITDA y se resta al final, debajo de la Utilidad neta. Sólo aplica al CONSOLIDADO. Un dataset
 * anterior a la regla (o aprobado sin la clave `withdrawals`) no tiene la sección: devuelve null.
 */
final class ErWithdrawalsSection
{
    public const TITLE = 'Retiros DG y socios';

    public const RESTRICTED = 'Detalle restringido: tu rol no tiene el permiso «Consultar detalle de Dirección General». Sólo se muestran los totales.';

    /** Rótulos del origen de cada movimiento. */
    public const SOURCES = ['erp' => 'ERP', 'bank' => 'Banco', 'banco' => 'Banco', 'noi_corp' => 'NOI corporativo'];

    /**
     * Payload de la sección o null si no aplica (sucursal, dataset sin `withdrawals`).
     *
     * @param  array  $dataset  dataset resuelto del ER (ya redactado)
     * @return array<string,mixed>|null
     */
    public static function build(array $dataset): ?array
    {
        $w = $dataset['withdrawals'] ?? null;
        $consolidated = (bool) ($dataset['meta']['scope']['consolidated'] ?? true);
        if (! is_array($w) || ! $consolidated || ($w['applies'] ?? false) !== true) {
            return null;
        }
        $restricted = (bool) ($w['restricted'] ?? false) || (bool) ($dataset['meta']['redactions']['dg'] ?? false);
        $items = $restricted ? [] : array_values(array_map(fn ($i) => (array) $i + [
            'source_label' => self::sourceLabel($i),
        ], (array) ($w['items'] ?? [])));

        return [
            'title' => self::TITLE,
            'total' => $w['total'] ?? null,
            'count' => (int) ($w['count'] ?? count($items)),
            'base' => (string) ($w['base'] ?? 'utilidad_neta'),
            'base_label' => (string) ($w['base_label'] ?? 'Utilidad neta'),
            'base_amount' => $w['base_amount'] ?? null,
            'result' => $w['result'] ?? null,
            'result_label' => (string) ($w['result_label'] ?? 'RESULTADO DESPUÉS DE RETIROS'),
            'result_title' => ($w['base'] ?? 'utilidad_neta') === 'ebt' ? 'Resultado después de retiros (antes de ISR)' : 'Resultado después de retiros',
            'formula' => (string) ($w['formula'] ?? 'Utilidad neta − Retiros DG y socios'),
            'negative' => (bool) ($w['negative'] ?? (($w['result'] ?? null) !== null && (float) $w['result'] < 0)),
            'explain' => $w['explain'] ?? null,
            'rule' => (string) ($w['rule'] ?? 'Lo que toman Dirección General y los socios no es OPEX: no cambia el EBITDA; se resta al final, después de la Utilidad neta.'),
            'restricted' => $restricted,
            'restricted_note' => $restricted ? self::RESTRICTED : null,
            'by_concept' => $restricted ? [] : array_values((array) ($w['by_concept'] ?? [])),
            'items' => $items,
        ];
    }

    /** Origen legible: ERP o la cuenta bancaria (enmascarada). */
    public static function sourceLabel(array $i): string
    {
        $src = (string) ($i['source'] ?? '');
        if ($src === 'erp' || $src === 'noi_corp') {
            return self::SOURCES[$src];
        }
        $account = ErFormat::mask((string) ($i['account_label'] ?? $i['account_slot'] ?? ''));

        return $account !== '' ? 'Banco · '.$account : (self::SOURCES[$src] ?? ($src !== '' ? ucfirst($src) : '—'));
    }
}
