<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 100);

        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $branches->getCollection()->map(fn (Branch $branch) => [
                'id'   => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ])->values(),
            'meta' => [
                'current_page' => $branches->currentPage(),
                'per_page'     => $branches->perPage(),
                'total'        => $branches->total(),
                'last_page'    => $branches->lastPage(),
            ],
        ]);
    }
}
