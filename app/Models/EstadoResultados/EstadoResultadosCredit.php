<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Crédito de la empresa (LEROM, BBVA…) cuyo INTERÉS va entre EBIT y EBT. */
class EstadoResultadosCredit extends Model
{
    protected $table = 'estado_resultados_credits';

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean'];

    public const SEMANAL = 'semanal';

    public const MENSUAL = 'mensual';

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

    public function rows(): HasMany
    {
        return $this->hasMany(EstadoResultadosAmortizationRow::class, 'credit_id');
    }
}
