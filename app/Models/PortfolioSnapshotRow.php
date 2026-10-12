<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioSnapshotRow extends Model
{
    protected $table = 'fact_portfolio_snapshot_rows';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_date' => 'date',
        'first_payment_date' => 'date',
        'contract_due_date' => 'date',
        'next_payment_date' => 'date',
    ];
}
