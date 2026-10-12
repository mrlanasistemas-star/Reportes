<?php

namespace App\Models;

use App\Models\Scopes\ExcludePortfolioSnapshotUploadsScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\ReportUploadStatus;

class ReportUpload extends Model {

    protected $fillable = [
        'period_id',
        'covered_period_ids',
        'data_source_id',
        'original_name',
        'stored_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
        'status',
        'notes',
        'snapshot_date',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'file_size' => 'integer',
        'status' => ReportUploadStatus::class,
        'covered_period_ids' => 'array',
        'snapshot_date' => 'date',
    ];

    protected static function booted(): void {
        static::addGlobalScope(ExcludePortfolioSnapshotUploadsScope::NAME, new ExcludePortfolioSnapshotUploadsScope);
    }

    /**
     * Fotografías diarias de cartera (Efectividad V4). El scope global las oculta
     * del flujo mensual; este es el ÚNICO punto de acceso a ellas.
     */
    public static function snapshots(): Builder {
        return static::query()
            ->withoutGlobalScope(ExcludePortfolioSnapshotUploadsScope::NAME)
            ->whereNotNull('snapshot_date');
    }

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function dataSource(): BelongsTo {
        return $this->belongsTo(DataSource::class);
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function processRuns() {
        return $this->hasMany(ProcessRun::class);
    }

    public function noiMovements(): HasMany {
        return $this->hasMany(NoiMovement::class);
    }

    public function placements(): HasMany {
        return $this->hasMany(Placement::class);
    }

    public function recoveries(): HasMany {
        return $this->hasMany(Recovery::class);
    }

    public function portfolios(): HasMany {
        return $this->hasMany(Portfolio::class);
    }

    public function expenses(): HasMany {
        return $this->hasMany(Expense::class);
    }

}
