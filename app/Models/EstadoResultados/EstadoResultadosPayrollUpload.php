<?php

namespace App\Models\EstadoResultados;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga de nómina PROPIA del Estado de Resultados (hoy: NOI de corporativo).
 * Nunca se mezcla con report_uploads / fact_noi_movements (Radiografía).
 */
class EstadoResultadosPayrollUpload extends Model
{
    public const KIND_NOI_CORPORATIVO = 'noi_corporativo';

    public const STATUS_LOADED = 'cargado';

    public const STATUS_REPLACED = 'reemplazado';

    protected $table = 'estado_resultados_payroll_uploads';

    protected $guarded = ['id'];

    protected $casts = [
        'warnings' => 'array',
        'superseded_at' => 'datetime',
        'rows_read' => 'integer',
        'lines_count' => 'integer',
        'employees_count' => 'integer',
        'total_percepciones' => 'decimal:2',
        'total_deducciones' => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EstadoResultadosPayrollLine::class, 'upload_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_REPLACED);
    }
}
