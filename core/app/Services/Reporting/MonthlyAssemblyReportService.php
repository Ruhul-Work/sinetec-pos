<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Facades\DB;

final class MonthlyAssemblyReportService
{
    public function report(?int $periodId, ?int $branchId, ?int $warehouseId, ?int $finishedProductId): array
    {
        $period = $periodId
            ? ReportingPeriod::query()->whereKey($periodId)->first()
            : ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->first();

        $query = DB::table('product_assembly_batches as batch')
            ->join('product_boms as bom', 'bom.id', '=', 'batch.product_bom_id')
            ->join('finished_products as product', 'product.id', '=', 'bom.finished_product_id')
            ->join('branches as branch', 'branch.id', '=', 'batch.branch_id')
            ->join('warehouses as input_warehouse', 'input_warehouse.id', '=', 'batch.input_warehouse_id')
            ->join('warehouses as output_warehouse', 'output_warehouse.id', '=', 'batch.output_warehouse_id')
            ->leftJoinSub(
                DB::table('product_assembly_consumptions')
                    ->groupBy('product_assembly_batch_id')
                    ->selectRaw('product_assembly_batch_id, SUM(total_cost) as component_cost'),
                'component_cost',
                'component_cost.product_assembly_batch_id',
                '=',
                'batch.id',
            )
            ->leftJoinSub(
                DB::table('product_assembly_expenses')
                    ->groupBy('product_assembly_batch_id')
                    ->selectRaw('product_assembly_batch_id, SUM(amount) as direct_cost'),
                'direct_cost',
                'direct_cost.product_assembly_batch_id',
                '=',
                'batch.id',
            )
            ->where('batch.status', 'COMPLETED');

        if ($period) {
            $query->whereBetween('batch.assembly_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()]);
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
        if ($finishedProductId) {
            $query->where('product.id', $finishedProductId);
        }

        $rows = $query->select([
            'batch.id',
            'batch.batch_no',
            'batch.assembly_date',
            'batch.planned_quantity',
            'batch.good_quantity',
            'batch.reject_quantity',
            'batch.qc_status',
            'batch.status',
            'bom.version as bom_version',
            'product.id as finished_product_id',
            'product.name as finished_product_name',
            'product.product_code',
            'branch.name as branch_name',
            'branch.code as branch_code',
            'input_warehouse.name as input_warehouse_name',
            'input_warehouse.code as input_warehouse_code',
            'output_warehouse.name as output_warehouse_name',
            'output_warehouse.code as output_warehouse_code',
            'component_cost.component_cost',
            'direct_cost.direct_cost',
        ])->orderByDesc('batch.assembly_date')->orderByDesc('batch.id')->get();

        $planned = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->planned_quantity, 6), '0.000000');
        $good = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->good_quantity, 6), '0.000000');
        $reject = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->reject_quantity, 6), '0.000000');
        $component = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) ($row->component_cost ?? 0), 4), '0.0000');
        $direct = $rows->reduce(fn (string $total, object $row): string => bcadd($total, (string) ($row->direct_cost ?? 0), 4), '0.0000');

        return [
            'period' => $period,
            'rows' => $rows,
            'planned' => $planned,
            'good' => $good,
            'reject' => $reject,
            'component_cost' => $component,
            'direct_cost' => $direct,
            'total_cost' => bcadd($component, $direct, 4),
        ];
    }
}
