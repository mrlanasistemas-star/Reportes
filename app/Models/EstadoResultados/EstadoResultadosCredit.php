<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Crédito de la empresa (LEROM, BBVA…) cuyo INTERÉS va entre EBIT y EBT
 * (tabla `estado_resultados_credits`; la migración siembra LEROM y BBVA).
 *
 * Columnas clave:
 *  - `key`: identificador único (lerom | bbva | …).
 *  - `name` / `creditor`: nombre visible y acreedor.
 *  - `periodicity`: diaria | semanal | mensual (frecuencia de pago de la tabla de amortización).
 *  - `adjustment_concept`: concepto de ajuste del ER donde se refleja el interés
 *    (intereses_lerom | intereses_bbva | otros_intereses).
 *  - `bank_pattern`: regex del concepto bancario con el que se identifica el pago.
 *  - `commission_concept`: concepto de ajuste donde se refleja la COMISIÓN financiera de la tabla
 *    (LEROM ⇒ comisiones_financieras; null = el crédito no tiene comisiones).
 *  - `active`: si el crédito participa en el cálculo.
 */
class EstadoResultadosCredit extends Model
{
    protected $table = 'estado_resultados_credits';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    /** Crédito con pagos diarios en días hábiles (LEROM, 24-nov-2026). */
    public const DIARIA = 'diaria';

    /** Crédito con pagos semanales. */
    public const SEMANAL = 'semanal';

    /** Crédito con pagos mensuales. */
    public const MENSUAL = 'mensual';

    /**
     * Todas las cargas de tablas de amortización del crédito (vigente y sustituidas).
     */
    public function uploads(): HasMany
    {
        return $this->hasMany(EstadoResultadosAmortizationUpload::class, 'credit_id');
    }

    /** Tabla de amortización vigente (la última carga no sustituida). */
    public function currentUpload(): HasOne
    {
        return $this->hasOne(EstadoResultadosAmortizationUpload::class, 'credit_id')
            ->where('status', EstadoResultadosAmortizationUpload::VIGENTE)
            ->latestOfMany();
    }

    /**
     * Todos los renglones de amortización del crédito (de cualquier carga; filtrar por
     * `upload_id` de la vigente cuando se requiera sólo la tabla actual).
     */
    public function rows(): HasMany
    {
        return $this->hasMany(EstadoResultadosAmortizationRow::class, 'credit_id');
    }
}
