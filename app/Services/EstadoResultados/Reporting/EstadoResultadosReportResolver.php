<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\EstadoResultados\EstadoResultadosVersionSnapshot;
use App\Models\Period;
use App\Models\User;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;

/**
 * Punto ÚNICO desde el que web (JSON), Excel y PDF obtienen el Estado de Resultados.
 *
 *  - Periodo con versión aprobada vigente → dataset del SNAPSHOT congelado (aunque
 *    después cambien Radiografía, ERP, archivos, clasificaciones o ajustes).
 *  - Sin versión vigente (nunca aprobado o nueva versión abierta) → dataset EN VIVO
 *    del builder, con su estado derivado (borrador / pendiente / listo).
 *  - ?version=N → esa versión exacta (aprobada o sustituida).
 *
 * Agrega `meta.report` (gobierno) y aplica la redacción por permisos del usuario.
 * No modifica cifras ni el `meta.dataset_hash` del builder.
 */
class EstadoResultadosReportResolver
{
    public function __construct(
        private readonly EstadoResultadosDatasetBuilder $builder,
        private readonly ReportStatusEvaluator $evaluator,
        private readonly PeriodLockGuard $guard,
        private readonly EstadoResultadosRedactor $redactor,
        private readonly EstadoResultadosAbilities $abilities,
    ) {}

    /**
     * Dataset listo para el usuario (redactado según sus permisos).
     *
     * @throws \InvalidArgumentException alcance no válido / no incluido en la versión
     */
    public function resolve(Period $period, string $scope, ?User $user, ?int $versionNumber = null): array
    {
        $dataset = $this->unredacted($period, $scope, $versionNumber);

        return $this->redactor->apply(
            $dataset,
            $this->abilities->can($user, 'ver_nomina'),
            $this->abilities->can($user, 'ver_dg'),
        );
    }

    /** Dataset con gobierno, SIN redacción (uso interno: p. ej. buscar movimientos para clasificar). */
    public function unredacted(Period $period, string $scope, ?int $versionNumber = null): array
    {
        $version = $this->servedVersion($period, $versionNumber);
        if ($version) {
            return $this->fromSnapshot($version, $scope);
        }

        return $this->withLiveMeta($period, $this->builder->build($period, $scope), $this->guard->latest($period));
    }

    /**
     * Recorre VARIOS alcances SIN redacción con una sola foto de datos (comparativos, series).
     *  - Periodo con versión servida (aprobada vigente o ?version) → itera sus snapshots.
     *  - En vivo → una sola sesión de Radiografía + EstadoResultadosDatasetBuilder::buildEach.
     * Cada dataset trae `meta.report` igual que unredacted().
     *
     * @param  list<string>|null  $scopes  null = todos los alcances disponibles
     * @param  callable(string, array): void  $each  ($scopeKey, $dataset)
     */
    public function eachUnredacted(Period $period, ?array $scopes, callable $each, ?int $versionNumber = null): void
    {
        $version = $this->servedVersion($period, $versionNumber);
        if ($version) {
            foreach ((array) $version->scopes as $s) {
                $key = (string) ($s['key'] ?? '');
                if ($scopes === null || in_array($key, $scopes, true)) {
                    $each($key, $this->fromSnapshot($version, $key));
                }
            }

            return;
        }
        $latest = $this->guard->latest($period);
        $this->builder->session(function () use ($period, $scopes, $each, $latest) {
            $this->builder->buildEach($period, $scopes, function (string $key, array $dataset) use ($period, $each, $latest) {
                $each($key, $this->withLiveMeta($period, $dataset, $latest));
            });
        });
    }

    private function withLiveMeta(Period $period, array $dataset, ?EstadoResultadosVersion $latest): array
    {
        $eval = $this->evaluator->evaluate($dataset);
        $dataset['meta']['report'] = $this->reportMeta($dataset, [
            'status' => $eval['status'],
            'source' => ReportStatus::SOURCE_LIVE,
            'version' => ($latest?->version ?? 0) + 1,
            'version_id' => null,
            'locked' => false,
            'current' => true,
            'preliminary' => $eval['preliminary'],
            'preliminary_reasons' => $eval['preliminary_reasons'],
            'blockers' => $eval['blockers'],
            'approvable' => $eval['status'] === ReportStatus::LISTO_REVISION,
            'previous_version' => $latest ? EstadoResultadosApprovalService::summary($latest->loadMissing(['approver:id,name', 'reopener:id,name'])) : null,
        ]);

        return $dataset;
    }

    /** @return list<array{key:string,label:string}> */
    public function scopes(Period $period, ?int $versionNumber = null): array
    {
        $version = $this->servedVersion($period, $versionNumber);

        return $version ? array_values((array) $version->scopes) : $this->builder->scopes($period);
    }

    /** Versión que se sirve: la pedida explícitamente o la aprobada vigente (null = en vivo). */
    public function servedVersion(Period $period, ?int $versionNumber = null): ?EstadoResultadosVersion
    {
        if ($versionNumber !== null) {
            $v = EstadoResultadosVersion::query()->where('period_id', $period->id)->where('version', $versionNumber)->first();
            if (! $v) {
                throw new EstadoResultadosGovernanceException('La versión '.$versionNumber.' no existe para '.$period->label.'.', 404);
            }

            return $v;
        }

        return $this->guard->lockedVersion($period);
    }

    private function fromSnapshot(EstadoResultadosVersion $version, string $scope): array
    {
        $snapshot = EstadoResultadosVersionSnapshot::query()->where('version_id', $version->id)->where('scope_key', $scope)->first();
        if (! $snapshot) {
            throw new \InvalidArgumentException('El alcance «'.$scope.'» no forma parte de la versión aprobada '.$version->version.'.');
        }
        $dataset = $snapshot->datasetVerified();
        $eval = (array) ($version->evaluation['scopes'][$scope] ?? []);
        $status = $version->status === EstadoResultadosVersion::SUSTITUIDO ? ReportStatus::SUSTITUIDO : ReportStatus::APROBADO;
        $version->loadMissing(['approver:id,name', 'reopener:id,name']);

        $dataset['meta']['report'] = $this->reportMeta($dataset, [
            'status' => $status,
            'source' => ReportStatus::SOURCE_SNAPSHOT,
            'version' => $version->version,
            'version_id' => $version->id,
            'locked' => $version->isLocked(),
            'current' => $version->isLocked(),
            'preliminary' => (bool) ($eval['preliminary'] ?? false),
            'preliminary_reasons' => array_values((array) ($eval['preliminary_reasons'] ?? [])),
            'blockers' => [],
            'approvable' => false,
            'approved_by' => $version->approver?->name,
            'approved_at' => $version->approved_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            'snapshot_hash' => $version->snapshot_hash,
            'criterion' => $version->criterion ?: ReportStatus::DEFAULT_CRITERION,
            'rules_version' => $version->rules_version,
            'reopened' => $version->reopened_at ? [
                'at' => $version->reopened_at->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                'by' => $version->reopener?->name,
                'reason' => $version->reopen_reason,
            ] : null,
            'superseded_at' => $version->superseded_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
        ]);

        return $dataset;
    }

    private function reportMeta(array $dataset, array $values): array
    {
        $status = $values['status'];

        return array_replace([
            'status' => $status,
            'status_label' => ReportStatus::label($status),
            'source' => ReportStatus::SOURCE_LIVE,
            'version' => 1,
            'version_id' => null,
            'locked' => false,
            'current' => true,
            'preliminary' => false,
            'preliminary_reasons' => [],
            'blockers' => [],
            'approvable' => false,
            'approved_by' => null,
            'approved_at' => null,
            'snapshot_hash' => null,
            'criterion' => (string) ($dataset['meta']['criterion'] ?? ReportStatus::DEFAULT_CRITERION),
            'rules_version' => (string) ($dataset['meta']['rules_version'] ?? config('estado_resultados.rules_version')),
            'generated_at' => (string) ($dataset['meta']['generated_at'] ?? ''),
            'issued_at' => now('America/Mexico_City')->format('d/m/Y H:i'),
            'reopened' => null,
            'previous_version' => null,
            'superseded_at' => null,
        ], $values, ['status_label' => ReportStatus::label($status)]);
    }
}
