<?php

namespace App\Services\Reporting;

use App\Enums\Reporting\MonthlyRateSheetStatus;
use App\Enums\Reporting\MonthlyReportSnapshotScope;
use App\Enums\Reporting\MonthlyReportSnapshotStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidMonthlyReportSnapshotException;
use App\Models\backend\Branch;
use App\Models\Reporting\MonthlyRateSheet;
use App\Models\Reporting\MonthlyReportSnapshot;
use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Facades\DB;

final class MonthlyReportSnapshotService
{
    public function __construct(
        private readonly StockValuationService $stockValuation,
        private readonly MonthlyPurchaseReportService $purchases,
        private readonly MonthlyStockReportService $stock,
        private readonly MonthlyPartProductionReportService $partProduction,
        private readonly MonthlyAssemblyReportService $assembly,
        private readonly MonthlySalesReportService $sales,
    ) {}

    public function generate(
        ReportingPeriod $period,
        int $userId,
        ?string $note = null,
        ?int $branchId = null,
    ): MonthlyReportSnapshot {
        if ($branchId === null && current_branch_id() !== null) {
            throw new InvalidMonthlyReportSnapshotException(
                'Switch the topbar branch scope to All Branches before generating a company-wide snapshot.',
            );
        }
        if ($branchId !== null && current_branch_id() !== null && current_branch_id() !== $branchId) {
            throw new InvalidMonthlyReportSnapshotException(
                'The snapshot branch must match the active topbar branch.',
            );
        }

        return DB::transaction(function () use ($period, $userId, $note, $branchId): MonthlyReportSnapshot {
            $period = ReportingPeriod::query()
                ->whereKey($period->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPeriodIsClosed($period);
            $rateSheet = $this->lockedRateSheet($period);
            $branch = $this->snapshotBranch($branchId);
            $scopeKey = MonthlyReportSnapshot::scopeKey($branch?->id);
            $current = $this->currentSnapshot($period, $scopeKey);
            $version = 1;

            if ($current) {
                $this->assertRevisionIsAllowed($period, $current, $note);
                $version = $current->version + 1;
            }

            $payload = $this->buildPayload($period, $rateSheet, $branch);
            $checksum = $this->checksum($payload);

            if ($current) {
                $current->update([
                    'status' => MonthlyReportSnapshotStatus::Superseded,
                    'superseded_by' => $userId,
                    'superseded_at' => now(),
                ]);
            }

            return MonthlyReportSnapshot::query()->create([
                'reporting_period_id' => $period->id,
                'monthly_rate_sheet_id' => $rateSheet->id,
                'report_type' => MonthlyReportSnapshot::REPORT_TYPE,
                'scope_type' => $branch
                    ? MonthlyReportSnapshotScope::Branch
                    : MonthlyReportSnapshotScope::Company,
                'branch_id' => $branch?->id,
                'scope_key' => $scopeKey,
                'version' => $version,
                'status' => MonthlyReportSnapshotStatus::Current,
                'snapshot_key' => $this->snapshotKey($period, $version, $branch?->id),
                'filters' => [
                    'scope' => $branch ? 'BRANCH' : 'COMPANY',
                    'branch_id' => $branch?->id,
                ],
                'payload' => $payload,
                'checksum' => $checksum,
                'note' => $this->text($note),
                'supersedes_snapshot_id' => $current?->id,
                'generated_by' => $userId,
                'generated_at' => now(),
            ])->load(['period', 'rateSheet', 'branch', 'generator', 'supersedes']);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function presentation(
        MonthlyReportSnapshot $snapshot,
        ?int $branchId,
        ?int $warehouseId,
    ): array {
        $payload = $snapshot->payload;
        $reports = $payload['reports'] ?? [];

        $reports['stock_valuation'] = $this->filterStockValuation(
            $reports['stock_valuation'] ?? [],
            $branchId,
            $warehouseId,
        );
        $reports['purchase'] = $this->filterPurchase(
            $reports['purchase'] ?? [],
            $branchId,
            $warehouseId,
        );
        $reports['stock'] = $this->filterStock(
            $reports['stock'] ?? [],
            $branchId,
            $warehouseId,
        );
        $reports['part_production'] = $this->filterProduction(
            $reports['part_production'] ?? [],
            $branchId,
            $warehouseId,
        );
        $reports['assembly'] = $this->filterProduction(
            $reports['assembly'] ?? [],
            $branchId,
            $warehouseId,
        );
        $reports['sales'] = $this->filterSales(
            $reports['sales'] ?? [],
            $branchId,
            $warehouseId,
        );

        $payload['reports'] = $reports;

        return $payload;
    }

    public function hasValidChecksum(MonthlyReportSnapshot $snapshot): bool
    {
        if (! is_string($snapshot->checksum) || $snapshot->checksum === '') {
            return false;
        }

        return hash_equals(
            $snapshot->checksum,
            $this->checksum($snapshot->payload),
        );
    }

    private function assertPeriodIsClosed(ReportingPeriod $period): void
    {
        if ($period->status !== ReportingPeriodStatus::Closed) {
            throw new InvalidMonthlyReportSnapshotException(
                'A Final Monthly Snapshot can be generated only after the reporting period is Closed.',
            );
        }
    }

    private function lockedRateSheet(ReportingPeriod $period): MonthlyRateSheet
    {
        $rateSheet = MonthlyRateSheet::query()
            ->where('reporting_period_id', $period->id)
            ->lockForUpdate()
            ->first();

        if (! $rateSheet || $rateSheet->status !== MonthlyRateSheetStatus::Locked) {
            throw new InvalidMonthlyReportSnapshotException(
                'Lock the period Monthly Rate Sheet before generating the Final Monthly Snapshot.',
            );
        }

        return $rateSheet;
    }

    private function currentSnapshot(
        ReportingPeriod $period,
        string $scopeKey,
    ): ?MonthlyReportSnapshot {
        return MonthlyReportSnapshot::query()
            ->where('reporting_period_id', $period->id)
            ->where('report_type', MonthlyReportSnapshot::REPORT_TYPE)
            ->where('scope_key', $scopeKey)
            ->where('status', MonthlyReportSnapshotStatus::Current->value)
            ->lockForUpdate()
            ->first();
    }

    private function assertRevisionIsAllowed(
        ReportingPeriod $period,
        MonthlyReportSnapshot $current,
        ?string $note,
    ): void {
        $reopenedAt = $period->events()
            ->where('action', 'REOPENED')
            ->where('created_at', '>', $current->generated_at)
            ->max('created_at');

        $closedAgain = $reopenedAt
            && $period->events()
                ->where('action', 'CLOSED')
                ->where('created_at', '>', $reopenedAt)
                ->exists();

        if (! $closedAgain) {
            throw new InvalidMonthlyReportSnapshotException(
                'This period already has a Final Snapshot. Reopen and close the period after an authorised correction before generating a new version.',
            );
        }

        if ($this->text($note) === null) {
            throw new InvalidMonthlyReportSnapshotException(
                'Explain the correction before generating a replacement snapshot version.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function buildPayload(
        ReportingPeriod $period,
        MonthlyRateSheet $rateSheet,
        ?Branch $branch,
    ): array {
        $branchId = $branch?->id;
        $stockValuation = $this->stockValuation->report($period->id, $branchId, null);
        $purchases = $this->purchases->report($period->id, $branchId, null, null);
        $stock = $this->stock->report($period->id, $branchId, null);
        $partProduction = $this->partProduction->report($period->id, $branchId, null, null);
        $assembly = $this->assembly->report($period->id, $branchId, null, null);
        $sales = $this->sales->report($period->id, $branchId, null, null);

        return [
            'schema_version' => 1,
            'scope' => $branch ? 'BRANCH' : 'COMPANY',
            'scope_branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ] : null,
            'period' => [
                'id' => $period->id,
                'year' => $period->year,
                'month' => $period->month,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ],
            'rate_sheet' => [
                'id' => $rateSheet->id,
                'sheet_no' => $rateSheet->sheet_no,
                'status' => $rateSheet->status->value,
                'locked_at' => $rateSheet->locked_at?->toIso8601String(),
            ],
            'dimensions' => [
                'branches' => DB::table('branches')
                    ->when($branchId, fn ($query) => $query->where('id', $branchId))
                    ->orderBy('name')
                    ->get(['id', 'name', 'code'])
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
                'warehouses' => DB::table('warehouses')
                    ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                    ->orderBy('name')
                    ->get(['id', 'branch_id', 'name', 'code'])
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
            ],
            'reports' => [
                'stock_valuation' => $this->stockValuationPayload($stockValuation),
                'purchase' => $this->purchasePayload($purchases),
                'stock' => $this->stockPayload($stock),
                'part_production' => $this->productionPayload($partProduction, 'material_cost'),
                'assembly' => $this->productionPayload($assembly, 'component_cost'),
                'sales' => $this->salesPayload($sales),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function stockValuationPayload(array $report): array
    {
        return [
            'grand_total' => $report['grand_total'],
            'domain_totals' => $report['domain_totals'],
            'rows' => $this->rows($report['rows']),
        ];
    }

    /** @return array<string, mixed> */
    private function purchasePayload(array $report): array
    {
        return [
            'receipt_total' => $report['receipt_total'],
            'return_total' => $report['return_total'],
            'net_purchase' => $report['net_purchase'],
            'payment_total' => $report['payment_total'],
            'receipts' => $this->rows($report['receipts']),
            'returns' => $this->rows($report['returns']),
            'payments' => $this->rows($report['payments']),
            'lines' => $this->rows($report['lines']),
        ];
    }

    /** @return array<string, mixed> */
    private function stockPayload(array $report): array
    {
        return [
            'opening_total' => $report['opening_total'],
            'in_total' => $report['in_total'],
            'out_total' => $report['out_total'],
            'closing_total' => $report['closing_total'],
            'rows' => $this->rows($report['rows']),
        ];
    }

    /** @return array<string, mixed> */
    private function productionPayload(array $report, string $componentKey): array
    {
        return [
            'planned' => $report['planned'],
            'good' => $report['good'],
            'reject' => $report['reject'],
            $componentKey => $report[$componentKey],
            'direct_cost' => $report['direct_cost'],
            'total_cost' => $report['total_cost'],
            'rows' => $this->rows($report['rows']),
        ];
    }

    /** @return array<string, mixed> */
    private function salesPayload(array $report): array
    {
        return [
            'sale_total' => $report['sale_total'],
            'return_total' => $report['return_total'],
            'net_sales' => $report['net_sales'],
            'refund_total' => $report['refund_total'],
            'due_adjustment' => $report['due_adjustment'],
            'receipt_total' => $report['receipt_total'],
            'initial_payment_total' => $report['initial_payment_total'],
            'net_collected' => $report['net_collected'],
            'paid_now' => $report['paid_now'],
            'due_total' => $report['due_total'],
            'sales' => $this->rows($report['sales']),
            'returns' => $this->rows($report['returns']),
            'receipts' => $this->rows($report['receipts']),
            'initial_payments' => $this->rows($report['initial_payments']),
            'lines' => $this->rows($report['lines']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(mixed $rows): array
    {
        return collect($rows)
            ->map(fn (mixed $row): array => (array) $row)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function filterStockValuation(array $report, ?int $branchId, ?int $warehouseId): array
    {
        $rows = $this->filterRows($report['rows'] ?? [], $branchId, $warehouseId);
        $report['rows'] = $rows;
        $report['grand_total'] = $this->sum($rows, 'value', 4);

        foreach ($report['domain_totals'] ?? [] as $domain => $summary) {
            $domainRows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ($row['domain'] ?? '') === $domain,
            ));
            $report['domain_totals'][$domain]['value'] = $this->sum($domainRows, 'value', 4);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function filterPurchase(array $report, ?int $branchId, ?int $warehouseId): array
    {
        $receipts = $this->filterRows($report['receipts'] ?? [], $branchId, $warehouseId);
        $returns = $this->filterRows($report['returns'] ?? [], $branchId, $warehouseId);
        $payments = $this->filterRows($report['payments'] ?? [], $branchId, $warehouseId);
        $lines = $this->filterRows($report['lines'] ?? [], $branchId, $warehouseId);

        $report['receipts'] = $receipts;
        $report['returns'] = $returns;
        $report['payments'] = $payments;
        $report['lines'] = $lines;
        $report['receipt_total'] = $this->sum($receipts, 'total', 4);
        $report['return_total'] = $this->sum($returns, 'total', 4);
        $report['payment_total'] = $this->sum($payments, 'amount', 4);
        $report['net_purchase'] = bcsub($report['receipt_total'], $report['return_total'], 4);

        return $report;
    }

    /** @return array<string, mixed> */
    private function filterStock(array $report, ?int $branchId, ?int $warehouseId): array
    {
        $rows = $this->filterRows($report['rows'] ?? [], $branchId, $warehouseId);
        $report['rows'] = $rows;
        $report['opening_total'] = $this->sum($rows, 'opening', 6);
        $report['in_total'] = $this->sum($rows, 'in', 6);
        $report['out_total'] = $this->sum($rows, 'out', 6);
        $report['closing_total'] = $this->sum($rows, 'closing', 6);

        return $report;
    }

    /** @return array<string, mixed> */
    private function filterProduction(array $report, ?int $branchId, ?int $warehouseId): array
    {
        $rows = array_values(array_filter(
            $report['rows'] ?? [],
            function (array $row) use ($branchId, $warehouseId): bool {
                if ($branchId && (int) ($row['branch_id'] ?? 0) !== $branchId) {
                    return false;
                }

                if (! $warehouseId) {
                    return true;
                }

                return (int) ($row['input_warehouse_id'] ?? 0) === $warehouseId
                    || (int) ($row['output_warehouse_id'] ?? 0) === $warehouseId;
            },
        ));

        $report['rows'] = $rows;
        $report['planned'] = $this->sum($rows, 'planned_quantity', 6);
        $report['good'] = $this->sum($rows, 'good_quantity', 6);
        $report['reject'] = $this->sum($rows, 'reject_quantity', 6);

        if (array_key_exists('material_cost', $report)) {
            $report['material_cost'] = $this->sum($rows, 'material_cost', 4);
        }
        if (array_key_exists('component_cost', $report)) {
            $report['component_cost'] = $this->sum($rows, 'component_cost', 4);
        }
        $report['direct_cost'] = $this->sum($rows, 'direct_cost', 4);
        $componentTotal = $report['material_cost'] ?? $report['component_cost'] ?? '0.0000';
        $report['total_cost'] = bcadd($componentTotal, $report['direct_cost'], 4);

        return $report;
    }

    /** @return array<string, mixed> */
    private function filterSales(array $report, ?int $branchId, ?int $warehouseId): array
    {
        $sales = $this->filterRows($report['sales'] ?? [], $branchId, $warehouseId);
        $returns = $this->filterRows($report['returns'] ?? [], $branchId, $warehouseId);
        $receipts = $this->filterRows($report['receipts'] ?? [], $branchId, $warehouseId);
        $initialPayments = $this->filterRows(
            $report['initial_payments'] ?? [],
            $branchId,
            $warehouseId,
        );
        $lines = $this->filterRows($report['lines'] ?? [], $branchId, $warehouseId);

        $report['sales'] = $sales;
        $report['returns'] = $returns;
        $report['receipts'] = $receipts;
        $report['initial_payments'] = $initialPayments;
        $report['lines'] = $lines;
        $report['sale_total'] = $this->sum($sales, 'total', 4);
        $report['return_total'] = $this->sum($returns, 'total', 4);
        $report['net_sales'] = bcsub($report['sale_total'], $report['return_total'], 4);
        $report['refund_total'] = $this->sum($returns, 'refund_amount', 4);
        $report['due_adjustment'] = $this->sum($returns, 'due_adjustment', 4);
        $report['receipt_total'] = $this->sum($receipts, 'amount', 4);
        $report['initial_payment_total'] = $this->sum($initialPayments, 'amount', 4);
        $report['net_collected'] = bcsub(
            bcadd($report['initial_payment_total'], $report['receipt_total'], 4),
            $report['refund_total'],
            4,
        );
        $report['paid_now'] = $this->sum($sales, 'paid_amount', 4);
        $report['due_total'] = $this->sum($sales, 'due_amount', 4);

        return $report;
    }

    /** @return array<int, array<string, mixed>> */
    private function filterRows(array $rows, ?int $branchId, ?int $warehouseId): array
    {
        return array_values(array_filter(
            $rows,
            function (array $row) use ($branchId, $warehouseId): bool {
                if ($branchId && (int) ($row['branch_id'] ?? 0) !== $branchId) {
                    return false;
                }
                if ($warehouseId && (int) ($row['warehouse_id'] ?? 0) !== $warehouseId) {
                    return false;
                }

                return true;
            },
        ));
    }

    private function sum(array $rows, string $field, int $scale): string
    {
        return array_reduce(
            $rows,
            fn (string $total, array $row): string => bcadd(
                $total,
                (string) ($row[$field] ?? 0),
                $scale,
            ),
            number_format(0, $scale, '.', ''),
        );
    }

    private function snapshotBranch(?int $branchId): ?Branch
    {
        if ($branchId === null) {
            return null;
        }

        $branch = Branch::query()
            ->whereKey($branchId)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            throw new InvalidMonthlyReportSnapshotException(
                'The selected snapshot branch is unavailable or inactive.',
            );
        }

        return $branch;
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $json);
    }

    private function snapshotKey(
        ReportingPeriod $period,
        int $version,
        ?int $branchId,
    ): string {
        return sprintf(
            'MONTHLY-PACKAGE-%04d-%02d-%s-V%d',
            $period->year,
            $period->month,
            $branchId ? 'BRANCH-'.$branchId : 'COMPANY',
            $version,
        );
    }

    private function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 2000);
    }
}
