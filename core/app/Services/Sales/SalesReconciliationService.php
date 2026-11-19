<?php

namespace App\Services\Sales;

use App\Enums\Sales\SaleReturnStatus;
use App\Enums\Sales\SaleStatus;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleReturn;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SalesReconciliationService
{
    /**
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @return array{
     *     sales_checked: int,
     *     sales_with_issues: int,
     *     returns_checked: int,
     *     returns_with_issues: int,
     *     issues: array<int, array{type: string, number: string, date: string, related_sale: string, url: string, issues: array<int, string>}>
     * }
     */
    public function reconcile(array $filters): array
    {
        $report = [
            'sales_checked' => 0,
            'sales_with_issues' => 0,
            'returns_checked' => 0,
            'returns_with_issues' => 0,
            'issues' => [],
        ];

        $this->reconcileSales($filters, $report);
        $this->reconcileReturns($filters, $report);

        usort($report['issues'], fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        return $report;
    }

    /**
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @param array<string, mixed> $report
     */
    private function reconcileSales(array $filters, array &$report): void
    {
        Sale::query()
            ->where('status', SaleStatus::Posted->value)
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->where(function ($query) use ($filters): void {
                $query->where(function ($saleQuery) use ($filters): void {
                    $saleQuery
                        ->when($filters['date_from'] ?? null, fn ($builder, string $date) => $builder->whereDate('sale_at', '>=', $date))
                        ->when($filters['date_to'] ?? null, fn ($builder, string $date) => $builder->whereDate('sale_at', '<=', $date));
                })
                    ->orWhereHas('returns', function ($returnQuery) use ($filters): void {
                        $returnQuery->where('status', SaleReturnStatus::Posted->value)
                            ->when($filters['date_from'] ?? null, fn ($builder, string $date) => $builder->whereDate('return_at', '>=', $date))
                            ->when($filters['date_to'] ?? null, fn ($builder, string $date) => $builder->whereDate('return_at', '<=', $date));
                    })
                    ->orWhereHas('customerReceipts', function ($receiptQuery) use ($filters): void {
                        $receiptQuery->where('status', 'POSTED')
                            ->when($filters['date_from'] ?? null, fn ($builder, string $date) => $builder->whereDate('receipt_at', '>=', $date))
                            ->when($filters['date_to'] ?? null, fn ($builder, string $date) => $builder->whereDate('receipt_at', '<=', $date));
                    });
            })
            ->with([
                'items',
                'payments' => fn ($query) => $query->where('status', 'POSTED'),
                'returns' => fn ($query) => $query->where('status', SaleReturnStatus::Posted->value),
                'customerReceipts' => fn ($query) => $query
                    ->where('status', 'POSTED')
                    ->with(['payments' => fn ($payments) => $payments->where('status', 'POSTED')]),
            ])
            ->orderBy('id')
            ->chunkById(100, function (EloquentCollection $sales) use (&$report): void {
                $saleIds = $sales->modelKeys();
                $receiptIds = $sales->flatMap(fn (Sale $sale) => $sale->customerReceipts->modelKeys())
                    ->unique()
                    ->values()
                    ->all();
                $movements = DB::table('finished_product_stock_movements')
                    ->where('source_type', 'sale')
                    ->whereIn('source_id', $saleIds)
                    ->get()
                    ->groupBy('source_id');
                $journals = $this->journalTotals([
                    'sale' => $saleIds,
                    'customer_receipt' => $receiptIds,
                ]);

                foreach ($sales as $sale) {
                    $report['sales_checked']++;
                    $issues = [];
                    if ($sale->items->isEmpty()) {
                        $issues[] = 'Posted Sale has no Finished Product lines.';
                    }
                    $itemSubtotal = $this->sum($sale->items->pluck('line_total'));
                    if (bccomp($itemSubtotal, (string) $sale->subtotal, 4) !== 0) {
                        $issues[] = 'Sale line totals do not match the document subtotal.';
                    }
                    $calculatedTotal = bcadd(
                        bcsub((string) $sale->subtotal, (string) $sale->discount, 4),
                        (string) $sale->tax,
                        4,
                    );
                    if (bccomp($calculatedTotal, (string) $sale->total, 4) !== 0) {
                        $issues[] = 'Sale subtotal, discount and tax do not match the document total.';
                    }
                    $this->checkStockMovements(
                        $sale->items,
                        $movements->get($sale->id, collect()),
                        (int) $sale->branch_id,
                        (int) $sale->warehouse_id,
                        'SALE',
                        'OUT',
                        'Sale',
                        $issues,
                    );

                    $paymentTotal = $this->sum($sale->payments->pluck('amount'));
                    $refundTotal = $this->sum($sale->returns->pluck('refund_amount'));
                    $dueAdjustmentTotal = $this->sum($sale->returns->pluck('due_adjustment'));
                    $expectedPaid = bcsub($paymentTotal, $refundTotal, 4);
                    $expectedDue = bcsub(
                        bcadd(bcsub((string) $sale->total, $paymentTotal, 4), $refundTotal, 4),
                        $dueAdjustmentTotal,
                        4,
                    );

                    if (bccomp($expectedPaid, (string) $sale->paid_amount, 4) !== 0) {
                        $issues[] = 'Posted payments less refunds do not match the Sale paid amount.';
                    }
                    if (bccomp($expectedDue, (string) $sale->due_amount, 4) !== 0) {
                        $issues[] = 'Sale total, payments and return due adjustments do not match the current due amount.';
                    }

                    $this->checkJournal(
                        $journals,
                        'sale',
                        (int) $sale->id,
                        (string) $sale->total,
                        'Sale',
                        $issues,
                    );

                    foreach ($sale->customerReceipts as $receipt) {
                        $receiptPayments = $this->sum($receipt->payments->pluck('amount'));
                        if (bccomp($receiptPayments, (string) $receipt->amount, 4) !== 0) {
                            $issues[] = "Customer receipt {$receipt->receipt_no} payment rows do not match its amount.";
                        }
                        $this->checkJournal(
                            $journals,
                            'customer_receipt',
                            (int) $receipt->id,
                            (string) $receipt->amount,
                            "Customer receipt {$receipt->receipt_no}",
                            $issues,
                        );
                    }

                    $saleRoute = $sale->channel->value === 'POS'
                        ? 'sales.pos.show'
                        : 'sales.wholesale.show';
                    $this->recordIssues(
                        $report,
                        'Sale',
                        $sale->sale_no,
                        $sale->sale_at->toDateString(),
                        '',
                        $issues,
                        route($saleRoute, $sale),
                    );
                }
            });
    }

    /**
     * @param array{branch_id?: int|null, date_from?: string|null, date_to?: string|null} $filters
     * @param array<string, mixed> $report
     */
    private function reconcileReturns(array $filters, array &$report): void
    {
        SaleReturn::query()
            ->where('status', SaleReturnStatus::Posted->value)
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('return_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('return_at', '<=', $date))
            ->with(['items', 'payments', 'sale'])
            ->orderBy('id')
            ->chunkById(100, function (EloquentCollection $returns) use (&$report): void {
                $returnIds = $returns->modelKeys();
                $movements = DB::table('finished_product_stock_movements')
                    ->where('source_type', 'sale_return')
                    ->whereIn('source_id', $returnIds)
                    ->get()
                    ->groupBy('source_id');
                $journals = $this->journalTotals(['sale_return' => $returnIds]);

                foreach ($returns as $return) {
                    $report['returns_checked']++;
                    $issues = [];
                    if ($return->items->isEmpty()) {
                        $issues[] = 'Posted Sales Return has no Finished Product lines.';
                    }
                    $itemSubtotal = $this->sum($return->items->pluck('line_total'));
                    if (bccomp($itemSubtotal, (string) $return->subtotal, 4) !== 0) {
                        $issues[] = 'Return line totals do not match the document subtotal.';
                    }
                    $calculatedTotal = bcadd(
                        bcsub((string) $return->subtotal, (string) $return->discount, 4),
                        (string) $return->tax,
                        4,
                    );
                    if (bccomp($calculatedTotal, (string) $return->total, 4) !== 0) {
                        $issues[] = 'Return subtotal, discount and tax do not match the document total.';
                    }
                    $this->checkStockMovements(
                        $return->items,
                        $movements->get($return->id, collect()),
                        (int) $return->branch_id,
                        (int) $return->warehouse_id,
                        'SALE_RETURN',
                        'IN',
                        'Sales Return',
                        $issues,
                    );

                    $refundPayments = $this->sum($return->payments->pluck('amount'));
                    if (bccomp(
                        bcadd((string) $return->refund_amount, (string) $return->due_adjustment, 4),
                        (string) $return->total,
                        4,
                    ) !== 0) {
                        $issues[] = 'Refund and due adjustment do not add up to the return total.';
                    }
                    if (bccomp($refundPayments, (string) $return->refund_amount, 4) !== 0) {
                        $issues[] = 'Refund payment rows do not match the recorded refund amount.';
                    }
                    if (! $return->sale || $return->sale->status !== SaleStatus::Posted) {
                        $issues[] = 'Source Sale is not Posted.';
                    } elseif (
                        (int) $return->sale->branch_id !== (int) $return->branch_id
                        || (int) $return->sale->warehouse_id !== (int) $return->warehouse_id
                    ) {
                        $issues[] = 'Return branch or warehouse does not match the source Sale.';
                    }

                    $this->checkJournal(
                        $journals,
                        'sale_return',
                        (int) $return->id,
                        (string) $return->total,
                        'Sales Return',
                        $issues,
                    );

                    $this->recordIssues(
                        $report,
                        'Sales Return',
                        $return->return_no,
                        $return->return_at->toDateString(),
                        $return->sale?->sale_no ?? '—',
                        $issues,
                        route('sales.returns.show', $return),
                    );
                }
            });
    }

    /**
     * @param Collection<int, object> $items
     * @param Collection<int, object> $movements
     * @param array<int, string> $issues
     */
    private function checkStockMovements(
        Collection $items,
        Collection $movements,
        int $branchId,
        int $warehouseId,
        string $movementType,
        string $direction,
        string $documentLabel,
        array &$issues,
    ): void {
        $expectedItemIds = [];
        $bySourceItem = $movements->groupBy('source_item_id');

        foreach ($items as $item) {
            $itemId = (int) $item->id;
            $expectedItemIds[] = $itemId;
            $itemMovements = $bySourceItem->get((string) $itemId, collect());

            if ($itemMovements->isEmpty()) {
                $issues[] = "{$documentLabel} line {$itemId} is missing its stock movement.";
                continue;
            }
            if ($itemMovements->count() !== 1) {
                $issues[] = "{$documentLabel} line {$itemId} has {$itemMovements->count()} stock movements; expected one.";
            }

            foreach ($itemMovements as $movement) {
                if (
                    (int) $movement->finished_product_id !== (int) $item->finished_product_id
                    || (int) $movement->branch_id !== $branchId
                    || (int) $movement->warehouse_id !== $warehouseId
                    || $movement->movement_type !== $movementType
                    || $movement->direction !== $direction
                ) {
                    $issues[] = "{$documentLabel} line {$itemId} has a stock movement with incorrect item, branch, warehouse, type or direction.";
                }
                if (bccomp((string) $movement->quantity, (string) $item->base_quantity, 6) !== 0) {
                    $issues[] = "{$documentLabel} line {$itemId} stock quantity does not match its base quantity.";
                }
            }
        }

        foreach ($bySourceItem as $sourceItemId => $itemMovements) {
            if (! in_array((int) $sourceItemId, $expectedItemIds, true)) {
                $issues[] = "{$documentLabel} has stock movements that do not match a document line.";
            }
        }
    }

    /**
     * @param array<string, array<int, int>> $sourceIds
     * @return array<string, Collection<int, object>>
     */
    private function journalTotals(array $sourceIds): array
    {
        $sourceIds = array_filter($sourceIds, fn (array $ids): bool => $ids !== []);
        if ($sourceIds === []) {
            return [];
        }

        $query = DB::table('journal_entries as journal')
            ->leftJoin('journal_entry_lines as line', 'line.journal_entry_id', '=', 'journal.id')
            ->where(function ($query) use ($sourceIds): void {
                foreach ($sourceIds as $sourceType => $ids) {
                    $query->orWhere(function ($sourceQuery) use ($sourceType, $ids): void {
                        $sourceQuery->where('journal.source_type', $sourceType)
                            ->whereIn('journal.source_id', $ids);
                    });
                }
            })
            ->groupBy('journal.source_type', 'journal.source_id', 'journal.id', 'journal.status')
            ->select([
                'journal.source_type',
                'journal.source_id',
                'journal.id',
                'journal.status',
            ])
            ->selectRaw('COALESCE(SUM(line.debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(line.credit), 0) as credit_total');

        $result = [];
        foreach ($query->get() as $journal) {
            $key = $this->sourceKey($journal->source_type, (int) $journal->source_id);
            $result[$key] ??= collect();
            $result[$key]->push($journal);
        }

        return $result;
    }

    /**
     * @param array<string, Collection<int, object>> $journals
     * @param array<int, string> $issues
     */
    private function checkJournal(
        array $journals,
        string $sourceType,
        int $sourceId,
        string $expectedAmount,
        string $label,
        array &$issues,
    ): void {
        $entries = $journals[$this->sourceKey($sourceType, $sourceId)] ?? collect();
        if ($entries->count() !== 1) {
            $issues[] = $entries->isEmpty()
                ? "{$label} is missing its Finance journal."
                : "{$label} has {$entries->count()} source journals; expected one.";

            return;
        }

        $journal = $entries->first();
        if ($journal->status !== 'POSTED') {
            $issues[] = "{$label} Finance journal is {$journal->status}, not POSTED.";
        }
        if (bccomp((string) $journal->debit_total, (string) $journal->credit_total, 4) !== 0) {
            $issues[] = "{$label} Finance journal is not balanced.";
        }
        if (
            bccomp((string) $journal->debit_total, $expectedAmount, 4) !== 0
            || bccomp((string) $journal->credit_total, $expectedAmount, 4) !== 0
        ) {
            $issues[] = "{$label} Finance journal totals do not match the document amount.";
        }
    }

    /**
     * @param array<string, mixed> $report
     * @param array<int, string> $issues
     */
    private function recordIssues(
        array &$report,
        string $type,
        string $number,
        string $date,
        string $relatedSale,
        array $issues,
        string $url,
    ): void {
        if ($issues === []) {
            return;
        }

        if ($type === 'Sale') {
            $report['sales_with_issues']++;
        } else {
            $report['returns_with_issues']++;
        }
        $report['issues'][] = [
            'type' => $type,
            'number' => $number,
            'date' => $date,
            'related_sale' => $relatedSale,
            'url' => $url,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function sum(iterable $amounts): string
    {
        $total = '0.0000';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        return $total;
    }

    private function sourceKey(string $sourceType, int $sourceId): string
    {
        return $sourceType.':'.$sourceId;
    }
}
