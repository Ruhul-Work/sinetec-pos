<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Facades\DB;

final class MonthlyPartProductionReportService
{
    public function report(?int $periodId, ?int $branchId, ?int $warehouseId, ?int $partId): array
    {
        $period = $periodId
            ? ReportingPeriod::query()->whereKey($periodId)->first()
            : ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->first();

        $query = DB::table('part_production_batches as batch')
            ->join('part_boms as bom', 'bom.id', '=', 'batch.part_bom_id')
            ->join('parts as part', 'part.id', '=', 'bom.part_id')
            ->join('branches as branch', 'branch.id', '=', 'batch.branch_id')
            ->join('warehouses as input_warehouse', 'input_warehouse.id', '=', 'batch.input_warehouse_id')
            ->join('warehouses as output_warehouse', 'output_warehouse.id', '=', 'batch.output_warehouse_id')
            ->leftJoinSub(
                DB::table('part_production_consumptions')
                    ->groupBy('part_production_batch_id')
                    ->selectRaw('part_production_batch_id, SUM(total_cost) as material_cost'),
                'material_cost',
                'material_cost.part_production_batch_id',
                '=',
                'batch.id',
            )
            ->leftJoinSub(
                DB::table('part_production_expenses')
                    ->groupBy('part_production_batch_id')
                    ->selectRaw('part_production_batch_id, SUM(amount) as direct_cost'),
                'direct_cost',
                'direct_cost.part_production_batch_id',
                '=',
                'batch.id',
            )
            ->where('batch.status', 'COMPLETED');

        if ($period) {
            $query->whereBetween('batch.production_date', [
                $period->starts_on->toDateString(),
                $period->ends_on->toDateString(),
            ]);
        }
        if ($branchId) {
            $query->where('batch.branch_id', $branchId);
        } elseif (current_branch_id()) {
            $query->where('batch.branch_id', current_branch_id());
        }
        if ($warehouseId) {
            $query->where(function ($builder) use ($warehouseId): void {
                $builder->where('batch.input_warehouse_id', $warehouseId)
                    ->orWhere('batch.output_warehouse_id', $warehouseId);
            });
        }
        if ($partId) {
            $query->where('part.id', $partId);
        }

        $rows = $query
            ->select([
                'batch.id',
                'batch.branch_id',
                'batch.input_warehouse_id',
                'batch.output_warehouse_id',
                'batch.batch_no',
                'batch.production_date',
                'batch.planned_quantity',
                'batch.good_quantity',
                'batch.reject_quantity',
                'batch.qc_status',
                'batch.status',
                'bom.version as bom_version',
                'part.id as part_id',
                'part.name as part_name',
                'part.part_code',
                'branch.name as branch_name',
                'branch.code as branch_code',
                'input_warehouse.name as input_warehouse_name',
                'input_warehouse.code as input_warehouse_code',
                'output_warehouse.name as output_warehouse_name',
                'output_warehouse.code as output_warehouse_code',
                'material_cost.material_cost',
                'direct_cost.direct_cost',
            ])
            ->orderByDesc('batch.production_date')
            ->orderByDesc('batch.id')
            ->get();

        $planned = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->planned_quantity, 6), '0.000000');
        $good = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->good_quantity, 6), '0.000000');
        $reject = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->reject_quantity, 6), '0.000000');
        $material = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) ($row->material_cost ?? 0), 4), '0.0000');
        $direct = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) ($row->direct_cost ?? 0), 4), '0.0000');
        $totalCost = bcadd($material, $direct, 4);

        return [
            'period' => $period,
            'rows' => $rows,
            'planned' => $planned,
            'good' => $good,
            'reject' => $reject,
            'material_cost' => $material,
            'direct_cost' => $direct,
            'total_cost' => $totalCost,
        ];
    }
}
