<?php

namespace App\Enums;

/**
 * Forma en que se resolvió la asignación empleado → sucursal.
 *
 * Lo usan EmployeeBranchAutoMatchService (asignación automática),
 * EmployeeBranchAssignment (columna `match_type`) y las pantallas de
 * validación/asignación manual para mostrar qué tan confiable es el vínculo:
 * - exact: el nombre coincide exactamente.
 * - normalized: coincide tras normalizar el nombre (acentos, mayúsculas, espacios).
 * - manual: lo asignó una persona.
 * - unmatched: no se encontró sucursal.
 * - canonical_same_name: mismo nombre normalizado, pero otro registro de empleado.
 * - historical: heredado de una asignación confirmada en un periodo anterior.
 * - majority: sucursal dominante entre varios candidatos, sin empate.
 * - fuzzy: coincidencia aproximada de nombre sobre un grupo reducido de candidatos (último recurso).
 */
enum MatchType: string
{
    case Exact = 'exact';
    case Normalized = 'normalized';
    case Manual = 'manual';
    case Unmatched = 'unmatched';
    case CanonicalSameName = 'canonical_same_name'; // mismo nombre normalizado, distinto registro de empleado
    case Historical = 'historical'; // heredado de una asignación confirmada en un periodo anterior
    case Majority = 'majority'; // sucursal dominante entre varios candidatos, sin empate
    case Fuzzy = 'fuzzy'; // coincidencia aproximada sobre candidatos reducidos, último recurso

}
