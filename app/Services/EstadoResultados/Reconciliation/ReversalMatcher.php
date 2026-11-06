<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Services\EstadoResultados\Support\Money;
use Illuminate\Support\Str;

/**
 * Pares de REVERSO (función pura, determinista): un cargo y un abono de la MISMA
 * cuenta, mismo importe al centavo, a ≤ N días y con evidencia textual
 * (mismo folio normalizado, o texto de reverso/cancelación en el abono).
 * Ambos se anulan entre sí fuera del ER (neto $0) con la misma clave RV-xxx.
 */
class ReversalMatcher
{
    public function __construct(
        private readonly int $days = 3,
        private readonly string $pattern = 'REVERS|CANCELACION',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            max(0, (int) config('estado_resultados.reversos.dias', 3)),
            (string) config('estado_resultados.reversos.patron', 'REVERS|CANCELACION'),
        );
    }

    /**
     * @param  list<array{fingerprint:string, account_slot:string, cargo:string, abono:string, movement_date:string, source_row:int, folio_normalized:?string, concept:?string, bank_reference:?string}>  $movements  movimientos en periodo
     * @return array<string, array{key:string, counterpart:string, evidence:string}>
     */
    public function match(array $movements): array
    {
        usort($movements, fn ($a, $b) => [$a['movement_date'], $a['account_slot'], $a['source_row'], $a['fingerprint']] <=> [$b['movement_date'], $b['account_slot'], $b['source_row'], $b['fingerprint']]);
        $isTransfer = fn ($m) => str_contains($this->text($m), 'TRASPASO');
        $cargos = array_values(array_filter($movements, fn ($m) => Money::cents($m['cargo']) > 0 && ! $isTransfer($m)));
        $abonos = array_values(array_filter($movements, fn ($m) => Money::cents($m['abono']) > 0 && ! $isTransfer($m)));

        $out = [];
        $used = [];
        $n = 0;
        foreach ($cargos as $c) {
            foreach ($abonos as $a) {
                if (isset($used[$a['fingerprint']]) || $a['account_slot'] !== $c['account_slot'] || Money::cents($a['abono']) !== Money::cents($c['cargo'])) {
                    continue;
                }
                $delta = abs((new \DateTimeImmutable($a['movement_date']))->diff(new \DateTimeImmutable($c['movement_date']))->days);
                if ($delta > $this->days) {
                    continue;
                }
                $sameFolio = $c['folio_normalized'] !== null && $c['folio_normalized'] === $a['folio_normalized'];
                $keyword = (bool) preg_match('/'.$this->pattern.'/', $this->text($a));
                if (! $sameFolio && ! $keyword) {
                    continue;
                }
                $n++;
                $key = 'RV-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
                $evidence = ($sameFolio ? 'mismo folio' : 'texto de reverso en el abono').", mismo importe y cuenta, {$delta} día(s) de diferencia";
                $out[$c['fingerprint']] = ['key' => $key, 'counterpart' => $a['fingerprint'], 'evidence' => $evidence];
                $out[$a['fingerprint']] = ['key' => $key, 'counterpart' => $c['fingerprint'], 'evidence' => $evidence];
                $used[$a['fingerprint']] = true;
                break;
            }
        }

        return $out;
    }

    private function text(array $m): string
    {
        return mb_strtoupper(Str::ascii(($m['concept'] ?? '').' '.($m['bank_reference'] ?? '')));
    }
}
