<?php
namespace App\Services\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class LowStockReportService
{
    /**
     * Build the operational low-stock query across all three inventory domains.
     *
     * Only configured reorder levels are included. A row becomes actionable
     * when its current warehouse balance is equal to or below the configured
     * minimum quantity.
     */
    public function query(
        ?int $branchId = null,
        ?int $warehouseId = null,
        string $domain = '',
        string $alertStatus = '',
    ): Builder {
        $union = $this->domainQuery(
            domain: 'RAW_MATERIAL',
            domainLabel: 'Raw Material',
            itemTable: 'raw_materials',
            itemColumn: 'raw_material_id',
            itemCodeColumn: 'material_code',
            reorderTable: 'raw_material_reorder_levels',
            balanceTable: 'raw_material_stock_balances',
            actionExpression: "'PURCHASE'",
        )
            ->unionAll($this->domainQuery(
                domain: 'PART',
                domainLabel: 'Part',
                itemTable: 'parts',
                itemColumn: 'part_id',
                itemCodeColumn: 'part_code',
                reorderTable: 'part_reorder_levels',
                balanceTable: 'part_stock_balances',
                actionExpression: "CASE
                    WHEN UPPER(COALESCE(item.procurement_mode, '')) = 'MAKE' THEN 'PRODUCTION'
                    WHEN UPPER(COALESCE(item.procurement_mode, '')) = 'BUY' THEN 'PURCHASE'
                    ELSE 'MAKE_OR_BUY'
                END",
            ))
            ->unionAll($this->domainQuery(
                domain: 'FINISHED_PRODUCT',
                domainLabel: 'Finished Product',
                itemTable: 'finished_products',
                itemColumn: 'finished_product_id',
                itemCodeColumn: 'product_code',
                reorderTable: 'finished_product_reorder_levels',
                balanceTable: 'finished_product_stock_balances',
                actionExpression: "'ASSEMBLY'",
            ));

        return DB::query()
            ->fromSub($union, 'low_stock')
            ->when($branchId, fn(Builder $query, int $id) => $query->where('branch_id', $id))
            ->when($warehouseId, fn(Builder $query, int $id) => $query->where('warehouse_id', $id))
            ->when(
                in_array($domain, $this->domains(), true),
                fn(Builder $query) => $query->where('domain', $domain),
            )
            ->when(
                in_array($alertStatus, $this->alertStatuses(), true),
                fn(Builder $query) => $query->where('alert_status', $alertStatus),
            );
    }

    /**
     * @return array{total_alerts: int, out_of_stock: int, low_stock: int, reorder_point: int}
     */
    public function summary(
        ?int $branchId = null,
        ?int $warehouseId = null,
        string $domain = '',
        string $alertStatus = '',
    ): array {
        $query = $this->query($branchId, $warehouseId, $domain, $alertStatus);

        return [
            'total_alerts'  => (clone $query)->count(),
            'out_of_stock'  => (clone $query)->where('alert_status', 'OUT_OF_STOCK')->count(),
            'low_stock'     => (clone $query)->where('alert_status', 'LOW_STOCK')->count(),
            'reorder_point' => (clone $query)->where('alert_status', 'REORDER_POINT')->count(),
        ];
    }

    /**
     * Determine the operational replenishment suggestion without creating any
     * Purchase or Production document.
     */
    public function suggestedQuantity(object $row): ?float
    {
        $current = (float) $row->current_quantity;
        $minimum = (float) $row->minimum_quantity;
        $reorder = (float) $row->reorder_quantity;
        $maximum = $row->maximum_quantity !== null
            ? (float) $row->maximum_quantity
            : null;
        $minimumRecovery = max(0, $minimum - $current);

        if ($reorder > 0) {
            $suggested = max($reorder, $minimumRecovery);

            return $maximum !== null
                ? max(0, min($suggested, $maximum - $current))
                : $suggested;
        }

        if ($maximum !== null) {
            return max(0, $maximum - $current);
        }

        return null;
    }

    /** @return array<int, string> */
    public function domains(): array
    {
        return ['RAW_MATERIAL', 'PART', 'FINISHED_PRODUCT'];
    }

    /** @return array<int, string> */
    public function alertStatuses(): array
    {
        return ['OUT_OF_STOCK', 'LOW_STOCK', 'REORDER_POINT'];
    }

    private function domainQuery(
        string $domain,
        string $domainLabel,
        string $itemTable,
        string $itemColumn,
        string $itemCodeColumn,
        string $reorderTable,
        string $balanceTable,
        string $actionExpression,
    ): Builder {
        $balanceExpression = 'COALESCE(balance.quantity, 0)';
        $alertExpression   = "CASE
            WHEN {$balanceExpression} <= 0 THEN 'OUT_OF_STOCK'
            WHEN {$balanceExpression} < reorder_level.minimum_quantity THEN 'LOW_STOCK'
            ELSE 'REORDER_POINT'
        END";

        return DB::table($reorderTable . ' as reorder_level')
            ->join($itemTable . ' as item', 'item.id', '=', 'reorder_level.' . $itemColumn)
            ->join('units as unit', 'unit.id', '=', 'item.base_unit_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'reorder_level.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
            ->leftJoin($balanceTable . ' as balance', function ($join) use ($itemColumn): void {
                $join->on('balance.' . $itemColumn, '=', 'item.id')
                    ->on('balance.warehouse_id', '=', 'warehouse.id');
            })
            ->whereNull('item.deleted_at')
            ->whereNull('warehouse.deleted_at')
            ->whereNull('branch.deleted_at')
            ->where('item.is_active', true)
            ->where('warehouse.is_active', true)
            ->where('branch.is_active', true)
            ->whereRaw($balanceExpression . ' <= reorder_level.minimum_quantity')
            ->select([
                DB::raw("'{$domain}' as domain"),
                DB::raw("'{$domainLabel}' as domain_label"),
                'item.id as item_id',
                'item.name as item_name',
                'item.' . $itemCodeColumn . ' as item_code',
                'unit.code as unit_code',
                'unit.precision as unit_precision',
                'branch.id as branch_id',
                'branch.name as branch_name',
                'branch.code as branch_code',
                'warehouse.id as warehouse_id',
                'warehouse.name as warehouse_name',
                'warehouse.code as warehouse_code',
                'reorder_level.minimum_quantity',
                'reorder_level.reorder_quantity',
                'reorder_level.maximum_quantity',
                DB::raw($balanceExpression . ' as current_quantity'),
                DB::raw(
                    'CASE WHEN reorder_level.minimum_quantity - ' . $balanceExpression . ' > 0 '
                    . 'THEN reorder_level.minimum_quantity - ' . $balanceExpression . ' ELSE 0 END as shortfall_quantity',
                ),
                DB::raw($alertExpression . ' as alert_status'),
                DB::raw($actionExpression . ' as action_type'),
            ]);
    }
}
