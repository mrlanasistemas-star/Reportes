<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstadoResultadosPayrollLine extends Model
{
    protected $table = 'estado_resultados_payroll_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'source_row' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosPayrollUpload::class, 'upload_id');
    }
}
