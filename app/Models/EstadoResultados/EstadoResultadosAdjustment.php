<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstadoResultadosAdjustment extends Model
{
    protected $table = 'estado_resultados_adjustments';

    protected $guarded = ['id'];

    public function logs(): HasMany
    {
        return $this->hasMany(EstadoResultadosAdjustmentLog::class, 'adjustment_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
