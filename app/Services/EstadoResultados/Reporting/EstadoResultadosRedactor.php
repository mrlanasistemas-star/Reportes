<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Services\EstadoResultados\Support\Treatment as T;

/**
 * Redacción en BACKEND del detalle sensible del Estado de Resultados.
 *
 *  - Sin `ver_nomina`: las líneas de las secciones de nómina se reemplazan por una
 *    sola línea con el TOTAL (el EBITDA no cambia) y se retiran los movimientos de
 *    nómina (importes individuales, beneficiarios, conceptos).
 *  - Sin `ver_dg`: igual para la sección y los movimientos de Dirección General.
 *
 * Secciones (modelo anterior) o categorías de la sección «opex» (modelo de EBITDA único) con
 * clave nomina_sucursales / nomina_corporativa / dg se colapsan igual.
 *
 * Totales, resultados, validaciones, conciliaciones agregadas y el hash del
 * dataset NO cambian: web, Excel y PDF del mismo usuario siguen siendo idénticos.
 */
class EstadoResultadosRedactor
{
    public const NOMINA_SECTIONS = ['nomina_sucursales', 'nomina_corporativa'];

    public const DG_SECTIONS = ['dg'];

    private const NOMINA_TREATMENTS = [T::NOMINA_SUCURSAL, T::NOMINA_CORPORATIVA];

    private const DG_TREATMENTS = [T::DG_OPERATIVO, T::DG_REMUNERACION];

    private const DG_RULES = ['dg_requiere_revision', 'erp_dg'];

    public function apply(array $d, bool $canNomina, bool $canDg): array
    {
        $hidden = ['nomina' => 0, 'dg' => 0];
        $scrubbed = 0;

        if (! $canNomina || ! $canDg) {
            foreach ($d['statement'] ?? [] as $i => $block) {
                if (($block['type'] ?? null) !== 'section') {
                    continue;
                }
                // Modelo de EBITDA único: nómina y DG son CATEGORÍAS dentro de la sección «opex».
                foreach ((array) ($block['categories'] ?? []) as $ci => $cat) {
                    if (! $canNomina && in_array($cat['key'] ?? null, self::NOMINA_SECTIONS, true)) {
                        $d['statement'][$i]['categories'][$ci] = $this->collapse($cat, 'Consultar detalle de nómina');
                    }
                    if (! $canDg && in_array($cat['key'] ?? null, self::DG_SECTIONS, true)) {
                        $d['statement'][$i]['categories'][$ci] = $this->collapse($cat, 'Consultar detalle de Dirección General');
                    }
                }
                if (! $canNomina && in_array($block['key'], self::NOMINA_SECTIONS, true)) {
                    $d['statement'][$i] = $this->collapse($block, 'Consultar detalle de nómina');
                }
                if (! $canDg && in_array($block['key'], self::DG_SECTIONS, true)) {
                    $d['statement'][$i] = $this->collapse($block, 'Consultar detalle de Dirección General');
                }
            }

            $kept = [];
            $hiddenFingerprints = [];
            foreach ($d['movements'] ?? [] as $m) {
                if (! $canNomina && self::isPayroll($m)) {
                    $hidden['nomina']++;
                    $hiddenFingerprints[$m['fingerprint'] ?? ''] = true;

                    continue;
                }
                if (! $canDg && self::isDg($m)) {
                    $hidden['dg']++;
                    $hiddenFingerprints[$m['fingerprint'] ?? ''] = true;

                    continue;
                }
                $kept[] = $m;
            }
            $d['movements'] = $kept;

            // Todo lo demás (validation.*, conciliation.*, outside.*, vínculos y evidencia de los
            // movimientos visibles, claves futuras del builder): se recorre completo. Conteos e
            // importes agregados se conservan; renglones de movimientos ocultos se anonimizan y la
            // evidencia de nómina NOI (nombre del colaborador, sucursal, total) se retira.
            foreach ($d as $key => $value) {
                if (in_array($key, ['meta', 'statement', 'treatments', 'reconciliation_labels', 'dimensions'], true) || ! is_array($value)) {
                    continue;
                }
                $d[$key] = $this->scrub($value, $hiddenFingerprints, $canNomina, $canDg, $scrubbed);
            }
        }
        $d['meta']['redactions'] = [
            'nomina' => ! $canNomina,
            'dg' => ! $canDg,
            'hidden_movements' => $hidden,
            'redacted_references' => $scrubbed,
            'message' => $this->message($canNomina, $canDg),
        ];

        return $d;
    }

    /** ¿El movimiento es detalle de nómina (por tratamiento vigente, automático, sugerido o regla)? */
    public static function isPayroll(array $m): bool
    {
        foreach (['treatment', 'auto_treatment', 'suggested_treatment'] as $k) {
            if (in_array($m[$k] ?? null, self::NOMINA_TREATMENTS, true)) {
                return true;
            }
        }
        if (in_array($m['override']['previous'] ?? null, self::NOMINA_TREATMENTS, true)) {
            return true;
        }

        if (in_array($m['dimensions']['category']['key'] ?? null, self::NOMINA_SECTIONS, true) || ! empty($m['noi_employee_id'])) {
            return true;
        }
        foreach ((array) ($m['links'] ?? []) as $l) {
            if (($l['kind'] ?? null) === 'evidencia_noi') {
                return true;
            }
        }

        return str_contains((string) ($m['rule_code'] ?? ''), 'nomina');
    }

    public static function isDg(array $m): bool
    {
        foreach (['treatment', 'auto_treatment', 'suggested_treatment'] as $k) {
            if (in_array($m[$k] ?? null, self::DG_TREATMENTS, true)) {
                return true;
            }
        }
        if (in_array($m['override']['previous'] ?? null, self::DG_TREATMENTS, true)) {
            return true;
        }

        if (($m['dimensions']['category']['key'] ?? null) === 'opex_dg') {
            return true;
        }

        return in_array($m['rule_code'] ?? null, self::DG_RULES, true);
    }

    /**
     * Recorre una estructura y anonimiza:
     *  - renglones con `fingerprint` de un movimiento oculto (se conservan importes/fechas/estado),
     *  - vínculos cuyo `target` es un movimiento oculto o que son evidencia NOI sin `ver_nomina`,
     *  - cualquier `evidence` que nombre a un colaborador sin `ver_nomina`.
     */
    private function scrub(array $node, array $hiddenFp, bool $canNomina, bool $canDg, int &$count): array
    {
        $hiddenRow = isset($node['fingerprint']) && is_string($node['fingerprint']) && isset($hiddenFp[$node['fingerprint']]);
        $hiddenTarget = isset($node['target']) && is_string($node['target']) && isset($hiddenFp[$node['target']]);
        $noiEvidence = ! $canNomina && (($node['kind'] ?? null) === 'evidencia_noi' || (is_array($node['evidence'] ?? null) && $this->namesEmployee($node['evidence'])));

        if ($hiddenRow) {
            $count++;
            foreach (['concept', 'reference', 'beneficiary', 'observations', 'folio', 'uuid', 'category', 'sucursal_raw', 'message', 'reconciliation_message', 'rule_reason', 'file'] as $f) {
                if (array_key_exists($f, $node)) {
                    $node[$f] = $f === 'concept' ? 'Detalle restringido' : null;
                }
            }
            $node['fingerprint'] = null;
            $node['redacted'] = true;
        }
        if ($hiddenTarget || $noiEvidence) {
            $count++;
            if ($hiddenTarget) {
                $node['target'] = null;
            }
            if (array_key_exists('evidence', $node)) {
                $node['evidence'] = [];
            }
            $node['redacted'] = true;
        }
        if (! $canNomina && isset($node['noi_employee_id'])) {
            $node['noi_employee_id'] = null;
        }

        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = $this->scrub($v, $hiddenFp, $canNomina, $canDg, $count);
            }
        }

        return $node;
    }

    private function namesEmployee(array $evidence): bool
    {
        foreach (['employee', 'employee_name', 'empleado', 'colaborador', 'noi_total'] as $k) {
            if (array_key_exists($k, $evidence)) {
                return true;
            }
        }

        return false;
    }

    private function collapse(array $block, string $permission): array
    {
        if (($block['lines'] ?? []) === [] || ! ($block['applies'] ?? true)) {
            return $block + ['restricted' => true];
        }
        $block['lines'] = [[
            'key' => $block['key'].'_restringido',
            'label' => 'Detalle restringido (requiere el permiso «'.$permission.'»)',
            'amount' => $block['total']['amount'],
            'pct' => $block['total']['pct'],
            'source' => 'Acceso restringido',
            'hint' => 'El total sí está incluido en el resultado; el desglose sólo lo ve quien tiene el permiso.',
            'state' => 'restringido',
        ]];
        $block['restricted'] = true;

        return $block;
    }

    private function message(bool $nomina, bool $dg): ?string
    {
        $parts = array_filter([$nomina ? null : 'nómina', $dg ? null : 'Dirección General']);

        return $parts === [] ? null : 'El detalle de '.implode(' y ', $parts).' está restringido para tu rol: se muestran sólo los totales.';
    }
}
