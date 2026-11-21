<?php

namespace App\Services\Reporting;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\ReportingPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class StockValuationService
{
    private const DOMAINS = [
        'raw_material' => [
            'label' => 'Raw Material',
            'balance_table' => 'raw_material_stock_balances',
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'code_column' => 'material_code',
            'rate_table' => 'monthly_raw_material_rates',
            'rate_item_column' => 'raw_material_id',
        ],
        'part' => [
            'label' => 'Part',
            'balance_table' => 'part_stock_balances',
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'code_column' => 'part_code',
            'rate_table' => 'monthly_part_rates',
            'rate_item_column' => 'part_id',
        ],
        'finished_product' => [
            'label' => 'Finished Product',
            'balance_table' => 'finished_product_stock_balances',
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'code_column' => 'product_code',
            'rate_table' => 'monthly_finished_product_rates',
            'rate_item_column' => 'finished_product_id',
        ],
    ];

    /** @return array<string, mixed> */
    public function report(?int $periodId, ?int $branchId, ?int $warehouseId, string $domain = ''): array
    {
        $period = $periodId
            ? ReportingPeriod::query()->whereKey($periodId)->first()
            : ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->first();

        $sheet = $period
            ? MonthlyRateSheet::query()
                ->where('reporting_period_id', $period->id)
                ->whereIn('status', [
                    MonthlyRateSheetStatus::Approved->value,
                    MonthlyRateSheetStatus::Locked->value,
                ])
                ->first()
            : null;

        $rows = collect();
        foreach (self::DOMAINS as $domainKey => $config) {
            if ($domain !== '' && $domain !== $domainKey) {
                continue;
            }

            $query = DB::table($config['balance_table'].' as balance')
                ->join('warehouses as warehouse', 'warehouse.id', '=', 'balance.warehouse_id')
                ->join('branches as branch', 'branch.id', '=', 'warehouse.branch_id')
                ->join($config['item_table'].' as item', 'item.id', '=', 'balance.'.$config['item_column'])
                ->join('units as unit', 'unit.id', '=', 'item.base_unit_id')
                ->whereNull('item.deleted_at')
                ->where('item.is_active', true)
                ->where('balance.quantity', '!=', '0');

            if ($branchId) {
                $query->where('warehouse.branch_id', $branchId);
            } elseif (current_branch_id()) {
                $query->where('warehouse.branch_id', current_branch_id());
            }
            if ($warehouseId) {
                $query->where('balance.warehouse_id', $warehouseId);
            }

            $query->select([
                'balance.quantity',
                'balance.warehouse_id',
                'warehouse.name as warehouse_name',
                'warehouse.code as warehouse_code',
                'branch.name as branch_name',
                'branch.code as branch_code',
                'item.id as item_id',
                'item.name as item_name',
                'item.'.$config['code_column'].' as item_code',
                'unit.code as unit_code',
            ]);

            foreach ($query->orderBy('branch.name')->orderBy('warehouse.name')->orderBy('item.name')->get() as $row) {
                $rate = '0.0000';
                $basis = 'NO_APPROVED_RATE';
                $rateStatus = null;

                if ($sheet) {
                    $rateRow = DB::table($config['rate_table'])
                        ->where('monthly_rate_sheet_id', $sheet->id)
                        ->where($config['rate_item_column'], $row->item_id)
                        ->first();
                    if ($rateRow) {
                        $rate = number_format((float) $rateRow->rate, 4, '.', '');
                        $basis = $rateRow->rate_basis;
                        $rateStatus = $sheet->status->value;
                    }
                }

                $value = bcmul((string) $row->quantity, $rate, 4);
                $rows->push([
                    'domain' => $domainKey,
                    'domain_label' => $config['label'],
                    'branch_name' => $row->branch_name,
                    'branch_code' => $row->branch_code,
                    'warehouse_name' => $row->warehouse_name,
                    'warehouse_code' => $row->warehouse_code,
                    'item_name' => $row->item_name,
                    'item_code' => $row->item_code,
                    'unit_code' => $row->unit_code,
                    'quantity' => number_format((float) $row->quantity, 6, '.', ''),
                    'rate' => $rate,
                    'basis' => $basis,
                    'rate_status' => $rateStatus,
                    'value' => $value,
                ]);
            }
        }

        $grandTotal = $rows->reduce(
            fn (string $total, array $row): string => bcadd($total, $row['value'], 4),
            '0.0000',
        );
        $domainTotals = [];
        foreach (self::DOMAINS as $domainKey => $config) {
            $domainTotals[$domainKey] = [
                'label' => $config['label'],
                'value' => $rows->where('domain', $domainKey)->reduce(
                    fn (string $total, array $row): string => bcadd($total, $row['value'], 4),
                    '0.0000',
                ),
            ];
        }

        return [
            'period' => $period,
            'sheet' => $sheet,
            'rows' => $rows,
            'domain_totals' => $domainTotals,
            'grand_total' => $grandTotal,
        ];
    }
}
