<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conciliación autorizada de la sucursal vigente de un colaborador (solo módulo OKR). */
class OkrBranchReconciliation extends Model
{
    protected $fillable = [
        'employee_id', 'normalized_name', 'branch_id', 'evidence_date',
        'chosen_source', 'sources', 'notes', 'resolved_by',
    ];

    protected $casts = [
        'evidence_date' => 'date',
        'sources' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
