<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model {

    protected $fillable = [
        'employee_code',
        'full_name',
        'position',
        'normalized_name',
        'first_name',
        'paternal_last_name',
        'maternal_last_name',
        'is_active',
        'source_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employeeBranchAssignments(): HasMany {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    public function noiMovements(): HasMany {
        return $this->hasMany(NoiMovement::class);
    }

    public function expenses(): HasMany {
        return $this->hasMany(Expense::class);
    }

    public function monthlyEmployeeSummaries(): HasMany {
        return $this->hasMany(MonthlyEmployeeSummary::class);
    }

    /** 05-oct-2026 — identidad persistente User↔Employee (0/1 User por Employee, ver migración users.employee_id). */
    public function user(): HasOne {
        return $this->hasOne(User::class);
    }

}
