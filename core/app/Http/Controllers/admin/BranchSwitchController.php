<?php

namespace App\Http\Controllers\admin;

use App\Http\Controllers\Controller;
use App\Models\backend\Branch;
use App\Models\backend\Warehouse;
use App\Support\BranchScope;
use App\Support\WarehouseScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function switch(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'mode' => ['required', 'in:all,one'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        if ($user?->isSuper()) {
            if ($data['mode'] === 'all') {
                BranchScope::setAll();
                WarehouseScope::clear();

                return $this->scopeResponse();
            }

            $branchId = (int) ($data['branch_id'] ?? 0);
            abort_unless(
                Branch::query()->whereKey($branchId)->where('is_active', true)->exists(),
                422,
                'Invalid branch.',
            );

            $this->selectBranch($branchId, 'No warehouse is configured for this branch.');

            return $this->scopeResponse();
        }

        $branchId = (int) $user?->branch_id;
        abort_if($branchId < 1, 422, 'Your user account is not assigned to a branch.');
        $this->selectBranch($branchId, 'No warehouse is configured for your branch.');

        return $this->scopeResponse();
    }

    private function selectBranch(int $branchId, string $missingWarehouseMessage): void
    {
        $warehouseId = Warehouse::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        abort_unless($warehouseId, 422, $missingWarehouseMessage);

        BranchScope::setBranch($branchId);
        WarehouseScope::set((int) $warehouseId);
    }

    private function scopeResponse(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'branch' => BranchScope::get(),
            'warehouse' => WarehouseScope::get(),
        ]);
    }
}
