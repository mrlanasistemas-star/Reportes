<?php

namespace App\Services\Roster;

/**
 * Categorías de movimiento laboral del roster por periodo (18-oct-2026).
 *
 * Reglas de fuentes (complementarias, nunca equivalentes):
 *   - NOI = nómina pagada en el periodo (presencia, no vigencia).
 *   - Directorio laboral (lendus_employee_directory) = ACTIVO / INACTIVO.
 *   - Lendus comercial (colocación) = actividad, nunca vigencia.
 * No aparecer en NOI o tener $0 de colocación NO es una baja. Solo
 * BAJA_CONFIRMADA lleva movement_type='baja' (lo que cuentan Rotación,
 * reportes y la elegibilidad OKR); toda ausencia sin evidencia queda como
 * movement_type='ausencia' y, si aplica, pendiente de conciliación.
 */
final class RosterMovementStatus
{
    // Filas presentes en la nómina del periodo (is_active_for_period = 1)
    public const ALTA_CONFIRMADA = 'alta_confirmada';

    public const REINGRESO = 'reingreso';

    public const PLANTILLA_INICIAL = 'plantilla_inicial';

    public const ACTIVO_CONFIRMADO = 'activo_confirmado';

    public const CAMBIO_SUCURSAL = 'cambio_sucursal';

    public const SIN_ACTIVIDAD_COMERCIAL = 'sin_actividad_comercial';

    public const RETORNO_TRAS_AUSENCIA = 'retorno_tras_ausencia';

    // Filas ausentes de la nómina del periodo (is_active_for_period = 0)
    public const BAJA_CONFIRMADA = 'baja_confirmada';

    public const POSIBLE_BAJA = 'posible_baja';

    public const NO_LOCALIZADO = 'no_localizado';

    public const VIGENCIA_DESCONOCIDA = 'vigencia_desconocida';

    public const AUSENCIA_JUSTIFICADA = 'ausencia_justificada';

    // Pueden aparecer en ambos lados
    public const REGISTRO_DUPLICADO = 'registro_duplicado';

    public const CONTRADICCION_FUENTES = 'contradiccion_fuentes';

    /** Fila antigua (antes de esta clasificación) que nunca se reclasificó. */
    public const SIN_CLASIFICAR = 'sin_clasificar';

    public const MOVEMENT_ALTA = 'alta';

    public const MOVEMENT_ACTIVO = 'activo';

    public const MOVEMENT_BAJA = 'baja';

    public const MOVEMENT_AUSENCIA = 'ausencia';

    public const DECISION_CONFIRMAR = 'confirmar_baja';

    public const DECISION_DESCARTAR = 'descartar_baja';

    /** Categorías que exigen conciliación autorizada antes de tratarse como hecho. */
    public const PENDING = [
        self::POSIBLE_BAJA,
        self::NO_LOCALIZADO,
        self::VIGENCIA_DESCONOCIDA,
        self::REGISTRO_DUPLICADO,
        self::CONTRADICCION_FUENTES,
        self::SIN_CLASIFICAR,
    ];

    public const LABELS = [
        self::ALTA_CONFIRMADA => 'Alta confirmada',
        self::REINGRESO => 'Reingreso',
        self::PLANTILLA_INICIAL => 'Plantilla inicial (sin periodo anterior)',
        self::ACTIVO_CONFIRMADO => 'Activo confirmado',
        self::CAMBIO_SUCURSAL => 'Cambio de sucursal',
        self::SIN_ACTIVIDAD_COMERCIAL => 'Sin actividad comercial',
        self::RETORNO_TRAS_AUSENCIA => 'Regresa tras una ausencia (no fue baja)',
        self::BAJA_CONFIRMADA => 'Baja confirmada',
        self::POSIBLE_BAJA => 'Posible baja',
        self::NO_LOCALIZADO => 'No localizado en el periodo',
        self::VIGENCIA_DESCONOCIDA => 'Vigencia desconocida',
        self::AUSENCIA_JUSTIFICADA => 'Ausencia descartada como baja',
        self::REGISTRO_DUPLICADO => 'Posible registro duplicado',
        self::CONTRADICCION_FUENTES => 'Fuentes se contradicen',
        self::SIN_CLASIFICAR => 'Sin clasificar (roster anterior a la conciliación)',
    ];

    /**
     * Valores que escribió la versión del 14-oct-2026 — ninguno era una
     * confirmación, así que se leen como pendientes.
     */
    private const LEGACY = [
        'sin_contradiccion' => self::POSIBLE_BAJA,
        'posible_coincidencia_nombre' => self::REGISTRO_DUPLICADO,
        'pendiente_conciliacion' => self::NO_LOCALIZADO,
    ];

    /** Categoría efectiva de una fila (incluye filas antiguas sin clasificar). */
    public static function resolve(?string $status, ?string $movementType, bool $isActive): string
    {
        if ($status !== null && $status !== '') {
            return self::LEGACY[$status] ?? $status;
        }

        if ($isActive) {
            return $movementType === self::MOVEMENT_ALTA ? self::ALTA_CONFIRMADA : self::ACTIVO_CONFIRMADO;
        }

        return self::SIN_CLASIFICAR;
    }

    public static function isPending(string $status): bool
    {
        return in_array($status, self::PENDING, true);
    }

    /** Una baja cuenta como hecho SOLO si fue confirmada con evidencia. */
    public static function isConfirmedBaja(?string $status, ?string $movementType): bool
    {
        return $movementType === self::MOVEMENT_BAJA && $status === self::BAJA_CONFIRMADA;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
