<?php

namespace App\Services\EstadoResultados\Reconciliation;

use App\Services\EstadoResultados\Support\Money;
use Illuminate\Support\Str;

/**
 * Vincula ABONOS de reembolso/devolución con el gasto que reembolsan (función pura).
 * Evidencia exigida: mismo folio normalizado y abono ≤ importe del gasto. Con un
 * único gasto candidato → vinculado; con varios → ambiguo; sin folio o sin
 * candidato → sin vínculo. Nunca se convierte un abono en ingreso.
 */
class RefundMatcher
{
    public function __construct(private readonly string $pattern = 'DEVOLUCION|REEMBOLSO|REINTEGRO') {}

    public static function fromConfig(): self
    {
        return new self((string) config('estado_resultados.reembolsos.patron', 'DEVOLUCION|REEMBOLSO|REINTEGRO'));
    }

    public function isRefund(array $m): bool
    {
        return Money::cents($m['abono'] ?? 0) > 0
            && (bool) preg_match('/'.$this->pattern.'/', mb_strtoupper(Str::ascii(($m['concept'] ?? '').' '.($m['observations'] ?? ''))));
    }

    /**
     * @param  array{fingerprint:string, folio_normalized:?string, abono:string}  $refund
     * @param  list<array{fingerprint:string, folio_normalized:?string, amount:string, source:string, universe:?string}>  $targets  gastos candidatos
     * @return array{status:'vinculado'|'ambiguo'|'sin_vinculo'|'radiografia', target:?array, candidates:list<string>}
     */
    public function match(array $refund, array $targets): array
    {
        $folio = $refund['folio_normalized'] ?? null;
        if (! $folio) {
            return ['status' => 'sin_vinculo', 'target' => null, 'candidates' => []];
        }
        $cents = Money::cents($refund['abono']);
        $cands = array_values(array_filter($targets, fn ($t) => $t['folio_normalized'] === $folio && Money::cents($t['amount']) >= $cents));
        usort($cands, fn ($a, $b) => strcmp($a['fingerprint'], $b['fingerprint']));
        $fps = array_column($cands, 'fingerprint');

        return match (true) {
            $cands === [] => ['status' => 'sin_vinculo', 'target' => null, 'candidates' => []],
            count($cands) > 1 => ['status' => 'ambiguo', 'target' => null, 'candidates' => $fps],
            ($cands[0]['universe'] ?? null) === 'radiografia' => ['status' => 'radiografia', 'target' => $cands[0], 'candidates' => $fps],
            default => ['status' => 'vinculado', 'target' => $cands[0], 'candidates' => $fps],
        };
    }
}
