<?php

namespace Database\Seeders;

use App\Models\OkrKpi;
use Illuminate\Database\Seeder;

/**
 * Módulo OKR (08-sep-2026) — catálogo inicial de KPI. Idempotente: updateOrCreate
 * por `code` — correrlo dos veces nunca duplica (sección 50 del pedido).
 *
 * KPI sin fuente automática confirmada en Reportería (clientes/renovaciones/
 * ticket promedio/préstamo activo/efectividad de recuperación) quedan
 * automation='manual' — ver docs/OKR.md "KPI sin fuente automática" para el
 * porqué de cada uno (nunca se inventó un cálculo).
 */
class OkrKpiSeeder extends Seeder
{
    public function run(): void
    {
        $automatic = [
            ['code' => 'ebitda', 'name' => 'EBITDA', 'unit' => 'currency', 'type' => OkrKpi::TYPE_BALANCE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'provider_key' => 'reporteria.ebitda', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'ebitda_margin', 'name' => 'Margen EBITDA', 'unit' => 'percentage', 'type' => OkrKpi::TYPE_PERCENTAGE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'provider_key' => 'reporteria.ebitda_margin', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'placement', 'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'provider_key' => 'reporteria.placement', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'recovery', 'name' => 'Recuperación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'provider_key' => 'reporteria.recovery', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'portfolio', 'name' => 'Valor Cartera', 'unit' => 'currency', 'type' => OkrKpi::TYPE_BALANCE, 'direction' => OkrKpi::DIRECTION_INCREASE, 'provider_key' => 'reporteria.portfolio', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'past_due_portfolio', 'name' => 'Cartera Vencida', 'unit' => 'currency', 'type' => OkrKpi::TYPE_BALANCE, 'direction' => OkrKpi::DIRECTION_DECREASE, 'provider_key' => 'reporteria.overdue_portfolio', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'mora', 'name' => 'Mora', 'unit' => 'percentage', 'type' => OkrKpi::TYPE_PERCENTAGE, 'direction' => OkrKpi::DIRECTION_DECREASE, 'provider_key' => 'reporteria.mora', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'opex', 'name' => 'OPEX', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_DECREASE, 'provider_key' => 'reporteria.opex', 'scopes' => ['general', 'branch', 'employee']],
            ['code' => 'turnover', 'name' => 'Rotación de Personal', 'unit' => 'percentage', 'type' => OkrKpi::TYPE_PERCENTAGE, 'direction' => OkrKpi::DIRECTION_DECREASE, 'provider_key' => 'reporteria.turnover', 'scopes' => ['general', 'branch']],
        ];

        foreach ($automatic as $kpi) {
            OkrKpi::query()->updateOrCreate(
                ['code' => $kpi['code']],
                $kpi + ['automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'is_active' => true, 'description' => null, 'config' => null],
            );
        }

        // Manual — sin fuente canónica confirmada en Reportería hoy (ver
        // docs/OKR.md). Quedan activos para captura manual, nunca inventados.
        $manual = [
            ['code' => 'recovery_effectiveness', 'name' => 'Efectividad de Recuperación', 'unit' => 'percentage', 'type' => OkrKpi::TYPE_PERCENTAGE, 'direction' => OkrKpi::DIRECTION_INCREASE],
            ['code' => 'clients', 'name' => 'Número de Clientes', 'unit' => 'integer', 'type' => OkrKpi::TYPE_BALANCE, 'direction' => OkrKpi::DIRECTION_INCREASE],
            ['code' => 'new_clients', 'name' => 'Clientes Nuevos', 'unit' => 'integer', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE],
            ['code' => 'renewals', 'name' => 'Renovaciones', 'unit' => 'integer', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE],
            ['code' => 'active_loans', 'name' => 'Préstamo Activo', 'unit' => 'integer', 'type' => OkrKpi::TYPE_BALANCE, 'direction' => OkrKpi::DIRECTION_INCREASE],
            ['code' => 'average_ticket', 'name' => 'Ticket Promedio', 'unit' => 'currency', 'type' => OkrKpi::TYPE_BALANCE, 'direction' => OkrKpi::DIRECTION_INCREASE],
        ];

        foreach ($manual as $kpi) {
            OkrKpi::query()->updateOrCreate(
                ['code' => $kpi['code']],
                $kpi + ['automation' => OkrKpi::AUTOMATION_MANUAL, 'provider_key' => null, 'scopes' => ['general', 'branch', 'employee'], 'is_active' => true, 'description' => null, 'config' => null],
            );
        }
    }
}
