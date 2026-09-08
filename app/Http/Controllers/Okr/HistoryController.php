<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Okr\Concerns\ResolvesOperativeBranches;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrObjective;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Módulo OKR (08-sep-2026) — histórico de OKR cerrados (sección 33 del pedido). */
class HistoryController extends Controller
{
    use ResolvesOperativeBranches, AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('okr.history.view');

        $query = OkrObjective::query()
            ->with(['branch:id,name', 'employee:id,full_name', 'responsibleUser:id,name'])
            ->where('lifecycle_status', OkrObjective::STATUS_CLOSED);

        if ($branchId = $request->integer('branch_id')) {
            $query->where('branch_id', $branchId);
        }
        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
        }
        if ($finalStatus = $request->string('final_status')->toString()) {
            $query->where('final_status', $finalStatus);
        }

        $objectives = $query->orderByDesc('closed_at')->paginate(20)->withQueryString();

        return Inertia::render('Okr/History', [
            'objectives' => $objectives,
            'filters' => [
                'branches'  => Branch::whereIn('name', $this->operativeBranchNames())->orderBy('name')->get(['id', 'name']),
                'employees' => Employee::query()->where('is_active', true)->orderBy('full_name')->limit(500)->get(['id', 'full_name']),
            ],
            'query' => $request->only(['branch_id', 'employee_id', 'final_status']),
        ]);
    }
}
