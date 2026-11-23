<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Facades\DB;

final class MonthlyStockReportService
{
    private const DOMAINS = [
        'raw_material' => [
            'label' => 'Raw Material',
            'movement_table' => 'raw_material_stock_movements',
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'code_column' => 'material_code',
        ],
        'part' => [
            'label' => 'Part',
            'movement_table' => 'part_stock_movements',
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'code_column' => 'part_code',
        ],
        'finished_product' => [
            'label' => 'Finished Product',
            'movement_table' => 'finished_product_stock_movements',
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'code_column' => 'product_code',
        ],
    ];

    /** @return array<string, mixed> */
    public function report(?int $periodId, ?int $branchId, ?int $warehouseId, string $domain = ''): array
    {
        $period = $periodId
            ? ReportingPeriod::query()->whereKey($periodId)->first()
            : ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->first();

        $rows = collect();
        $openingTotal = '0.000000';
        $inTotal = '0.000000';
        $outTotal = '0.000000';
        $closingTotal = '0.000000';

        foreach (self::DOMAINS as $domainKey => $config) {
            if ($domain !== '' && $domain !== $domainKey) {
                continue;
            }
            if (! $period) {
                continue;
            }

            $opening = $this->domainMovements(
                $config,
                $domainKey,
                $branchId,
                $warehouseId,
                null,
                $period,
                true,
            );
            $movementRows = $this->domainMovements(
                $config,
                $domainKey,
                $branchId,
                $warehouseId,
                null,
                $period,
                false,
            );

            $keys = collect($opening)->keys()->merge(collect($movementRows)->keys())->unique();
            foreach ($keys as $key) {
                $meta = ($opening[$key]['meta'] ?? null) ?: ($movementRows[$key]['meta'] ?? null);
                if (! $meta) {
                    continue;
                }

                $openingQty = $opening[$key]['quantity'] ?? '0.000000';
                $inQty = $movementRows[$key]['in'] ?? '0.000000';
                $outQty = $movementRows[$key]['out'] ?? '0.000000';
                $closingQty = bcadd($openingQty, bcsub($inQty, $outQty, 6), 6);

                $row = [
                    'domain' => $domainKey,
                    'domain_label' => $config['label'],
                    'branch_id' => $meta['branch_id'],
                    'warehouse_id' => $meta['warehouse_id'],
                    'item_name' => $meta['item_name'],
                    'item_code' => $meta['item_code'],
                    'unit_code' => $meta['unit_code'],
                    'branch_name' => $meta['branch_name'],
                    'branch_code' => $meta['branch_code'],
                    'warehouse_name' => $meta['warehouse_name'],
                    'warehouse_code' => $meta['warehouse_code'],
                    'opening' => $openingQty,
                    'in' => $inQty,
                    'out' => $outQty,
                    'closing' => $closingQty,
                ];
                $rows->push($row);

                $openingTotal = bcadd($openingTotal, $openingQty, 6);
                $inTotal = bcadd($inTotal, $inQty, 6);
                $outTotal = bcadd($outTotal, $outQty, 6);
                $closingTotal = bcadd($closingTotal, $closingQty, 6);
            }
        }

        return [
            'period' => $period,
            'rows' => $rows,
            'opening_total' => $openingTotal,
            'in_total' => $inTotal,
            'out_total' => $outTotal,
            'closing_total' => $closingTotal,
        ];
    }

    private function domainMovements(
        array $config,
        string $domain,
        ?int $branchId,
        ?int $warehouseId,
        ?int $itemId,
        ReportingPeriod $period,
        bool $openingOnly,
    ): array {
        $query = DB::table($config['movement_table'].' as movement')
            ->join($config['item_table'].' as item', 'item.id', '=', 'movement.'.$config['item_column'])
            ->join('units as unit', 'unit.id', '=', 'item.base_unit_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'movement.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'movement.branch_id');

        if ($openingOnly) {
            $query->where('movement.movement_at', '<', $period->starts_on->toDateString().' 00:00:00');
        } else {
            $query->whereBetween('movement.movement_at', [
                $period->starts_on->toDateString().' 00:00:00',
                $period->ends_on->toDateString().' 23:59:59',
            ]);
        }
        if ($branchId) {
            $query->where('movement.branch_id', $branchId);
        } elseif (current_branch_id()) {
            $query->where('movement.branch_id', current_branch_id());
        }
        if ($warehouseId) {
            $query->where('movement.warehouse_id', $warehouseId);
        }
        if ($itemId) {
            $query->where('movement.'.$config['item_column'], $itemId);
        }

        $rows = $query
            ->select([
                'movement.'.$config['item_column'].' as item_id',
                'movement.branch_id',
                'movement.warehouse_id',
                'item.name as item_name',
                'item.'.$config['code_column'].' as item_code',
                'unit.code as unit_code',
                'branch.name as branch_name',
                'branch.code as branch_code',
                'warehouse.name as warehouse_name',
                'warehouse.code as warehouse_code',
                'movement.direction',
                'movement.quantity',
            ])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $domain.'|'.$row->item_id.'|'.$row->warehouse_id;
            $meta = [
                'branch_id' => (int) $row->branch_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'item_name' => $row->item_name,
                'item_code' => $row->item_code,
                'unit_code' => $row->unit_code,
                'branch_name' => $row->branch_name,
                'branch_code' => $row->branch_code,
                'warehouse_name' => $row->warehouse_name,
                'warehouse_code' => $row->warehouse_code,
            ];
            $quantity = number_format((float) $row->quantity, 6, '.', '');

            if (! isset($result[$key])) {
                $result[$key] = $openingOnly
                    ? ['quantity' => '0.000000', 'meta' => $meta]
                    : ['in' => '0.000000', 'out' => '0.000000', 'meta' => $meta];
            }
            if ($openingOnly) {
                $result[$key]['quantity'] = $row->direction === 'IN'
                    ? bcadd($result[$key]['quantity'], $quantity, 6)
                    : bcsub($result[$key]['quantity'], $quantity, 6);
            } elseif ($row->direction === 'IN') {
                $result[$key]['in'] = bcadd($result[$key]['in'], $quantity, 6);
            } else {
                $result[$key]['out'] = bcadd($result[$key]['out'], $quantity, 6);
            }
        }

        return $result;
    }
}
