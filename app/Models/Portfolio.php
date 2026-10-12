<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Portfolio extends Model {

    protected $table = 'fact_portfolios';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'branch_id',
        'client_name',
        'normalized_client_name',
        'contract',
        'promoter_name',
        'promoter_code',
        'product_name',
        'num_payments',
        'periodicity',
        'route_name',
        // D) valor cartera
        'balance',
        'past_due_balance',
        // C) préstamo activo — DISTINCT from balance (saldo_actual)
        'capital_activo',
        // B) mora components
        'capital_due',
        'interes_atrasado',
        'impuesto_atrasado',
        'saldo_interes_moratorio',
        'saldo_impuesto_interes_moratorio',
        'cargos_calendario_atrasado',
        'impuesto_cargos_calendario_atrasado',
        'days_past_due',
        'portfolio_date',
        // Campos de la macro Efectividad de Cobranza Aprobada
        'status',
        'substatus',
        'first_payment_date',
        'next_payment_date',
        'contract_due_date',
        'installment_amount',
        'overdue_installments',
        'total_overdue_raw',
        'total_pending_raw',
        'saldo_capital_vencido',
        'saldo_interes_vencido',
        'saldo_impuesto_vencido',
        'saldo_cargo_calendario_vencido',
        'saldo_impuesto_cargo_calendario_vencido',
        'importer_version',
        'raw_payload',
    ];

    protected $casts = [
        'balance'                            => 'decimal:2',
        'past_due_balance'                   => 'decimal:2',
        'capital_activo'                     => 'decimal:2',
        'capital_due'                        => 'decimal:2',
        'interes_atrasado'                   => 'decimal:2',
        'impuesto_atrasado'                  => 'decimal:2',
        'saldo_interes_moratorio'            => 'decimal:2',
        'saldo_impuesto_interes_moratorio'   => 'decimal:2',
        'cargos_calendario_atrasado'         => 'decimal:2',
        'impuesto_cargos_calendario_atrasado' => 'decimal:2',
        'days_past_due'                      => 'integer',
        'num_payments'                       => 'integer',
        'portfolio_date'                     => 'date',
        'first_payment_date'                 => 'date',
        'next_payment_date'                  => 'date',
        'contract_due_date'                  => 'date',
        'installment_amount'                 => 'decimal:2',
        'overdue_installments'               => 'integer',
        'total_overdue_raw'                  => 'decimal:2',
        'total_pending_raw'                  => 'decimal:2',
        'saldo_capital_vencido'               => 'decimal:2',
        'saldo_interes_vencido'               => 'decimal:2',
        'saldo_impuesto_vencido'              => 'decimal:2',
        'saldo_cargo_calendario_vencido'      => 'decimal:2',
        'saldo_impuesto_cargo_calendario_vencido' => 'decimal:2',
        'raw_payload'                        => 'array',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function reportUpload(): BelongsTo {
        return $this->belongsTo(ReportUpload::class);
    }

    public function branch(): BelongsTo {
        return $this->belongsTo(Branch::class);
    }

}
