<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Facades\DB;

final class MonthlySalesReportService
{
    public function report(?int $periodId, ?int $branchId, ?int $warehouseId, ?int $customerId, string $channel = ''): array
    {
        $period = $periodId
            ? ReportingPeriod::query()->whereKey($periodId)->first()
            : ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->first();

        $saleQuery = DB::table('sales as sale')
            ->leftJoin('customers as customer', 'customer.id', '=', 'sale.customer_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'sale.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'sale.branch_id')
            ->where('sale.status', 'POSTED');
        $returnQuery = DB::table('sale_returns as return')
            ->join('sales as sale', 'sale.id', '=', 'return.sale_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'sale.customer_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'return.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'return.branch_id')
            ->where('return.status', 'POSTED');
        $receiptQuery = DB::table('customer_receipts as receipt')
            ->join('sales as sale', 'sale.id', '=', 'receipt.sale_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'receipt.customer_id')
            ->join('branches as branch', 'branch.id', '=', 'receipt.branch_id')
            ->where('receipt.status', 'POSTED');
        $initialPaymentQuery = DB::table('sale_payments as payment')
            ->join('sales as sale', 'sale.id', '=', 'payment.sale_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'sale.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'sale.branch_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'sale.customer_id')
            ->where('payment.status', 'POSTED')
            ->whereNull('payment.customer_receipt_id');

        if ($period) {
            $saleQuery->whereBetween('sale.sale_at', [$period->starts_on->toDateString().' 00:00:00', $period->ends_on->toDateString().' 23:59:59']);
            $returnQuery->whereBetween('return.return_at', [$period->starts_on->toDateString().' 00:00:00', $period->ends_on->toDateString().' 23:59:59']);
            $receiptQuery->whereBetween('receipt.receipt_at', [$period->starts_on->toDateString().' 00:00:00', $period->ends_on->toDateString().' 23:59:59']);
            $initialPaymentQuery->whereBetween('payment.payment_at', [$period->starts_on->toDateString().' 00:00:00', $period->ends_on->toDateString().' 23:59:59']);
        }
        $this->applySaleScopes($saleQuery, $branchId, $warehouseId, $customerId, $channel);
        $this->applyReturnScopes($returnQuery, $branchId, $warehouseId, $customerId, $channel);
        $this->applyReceiptScopes($receiptQuery, $branchId, $customerId, $channel);
        $this->applyInitialPaymentScopes($initialPaymentQuery, $branchId, $warehouseId, $customerId, $channel);

        $sales = (clone $saleQuery)->select([
            'sale.id',
            'sale.sale_no',
            'sale.sale_at',
            'sale.channel',
            'sale.subtotal',
            'sale.discount',
            'sale.tax',
            'sale.total',
            'sale.paid_amount',
            'sale.due_amount',
            'customer.name as customer_name',
            'warehouse.name as warehouse_name',
            'warehouse.code as warehouse_code',
            'branch.name as branch_name',
            'branch.code as branch_code',
        ])->orderByDesc('sale.sale_at')->orderByDesc('sale.id')->get();

        $returns = (clone $returnQuery)->select([
            'return.id',
            'return.return_no',
            'return.return_at',
            'return.total',
            'return.due_adjustment',
            'return.refund_amount',
            'sale.sale_no',
            'sale.channel',
            'customer.name as customer_name',
            'warehouse.name as warehouse_name',
            'warehouse.code as warehouse_code',
            'branch.name as branch_name',
            'branch.code as branch_code',
        ])->orderByDesc('return.return_at')->orderByDesc('return.id')->get();

        $receipts = (clone $receiptQuery)->select([
            'receipt.id',
            'receipt.receipt_no',
            'receipt.receipt_at',
            'receipt.amount',
            'receipt.due_before',
            'receipt.due_after',
            'sale.sale_no',
            'sale.channel',
            'customer.name as customer_name',
            'branch.name as branch_name',
            'branch.code as branch_code',
        ])->orderByDesc('receipt.receipt_at')->orderByDesc('receipt.id')->get();

        $saleTotal = $sales->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->total, 4), '0.0000');
        $returnTotal = $returns->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->total, 4), '0.0000');
        $refundTotal = $returns->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->refund_amount, 4), '0.0000');
        $dueAdjustment = $returns->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->due_adjustment, 4), '0.0000');
        $netSales = bcsub($saleTotal, $returnTotal, 4);
        $receiptTotal = $receipts->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->amount, 4), '0.0000');
        $initialPaymentTotal = (clone $initialPaymentQuery)
            ->sum(DB::raw('payment.amount'));
        $initialPaymentTotal = number_format((float) ($initialPaymentTotal ?? 0), 4, '.', '');
        $paidNow = $sales->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->paid_amount, 4), '0.0000');
        $dueTotal = $sales->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->due_amount, 4), '0.0000');
        $netCollected = bcsub(bcadd($initialPaymentTotal, $receiptTotal, 4), $refundTotal, 4);

        $saleIds = $sales->pluck('id')->all();
        $lineRows = collect();
        if ($saleIds) {
            $lineRows = DB::table('sale_items as item')
                ->join('sales as sale', 'sale.id', '=', 'item.sale_id')
                ->join('finished_products as product', 'product.id', '=', 'item.finished_product_id')
                ->join('units as unit', 'unit.id', '=', 'item.unit_id')
                ->whereIn('item.sale_id', $saleIds)
                ->select([
                    'sale.sale_no',
                    'sale.sale_at',
                    'sale.channel',
                    'product.name as product_name',
                    'product.product_code',
                    'unit.code as unit_code',
                    'item.quantity',
                    'item.base_quantity',
                    'item.unit_price',
                    'item.line_total',
                ])
                ->orderByDesc('sale.sale_at')
                ->orderByDesc('item.id')
                ->get();
        }

        return [
            'period' => $period,
            'sales' => $sales,
            'returns' => $returns,
            'receipts' => $receipts,
            'lines' => $lineRows,
            'sale_total' => $saleTotal,
            'return_total' => $returnTotal,
            'net_sales' => $netSales,
            'refund_total' => $refundTotal,
            'due_adjustment' => $dueAdjustment,
            'receipt_total' => $receiptTotal,
            'initial_payment_total' => $initialPaymentTotal,
            'net_collected' => $netCollected,
            'paid_now' => $paidNow,
            'due_total' => $dueTotal,
        ];
    }

    private function applySaleScopes($query, ?int $branchId, ?int $warehouseId, ?int $customerId, string $channel): void
    {
        if ($branchId) $query->where('sale.branch_id', $branchId);
        elseif (current_branch_id()) $query->where('sale.branch_id', current_branch_id());
        if ($warehouseId) $query->where('sale.warehouse_id', $warehouseId);
        if ($customerId) $query->where('sale.customer_id', $customerId);
        if (in_array($channel, ['POS', 'WHOLESALE'], true)) $query->where('sale.channel', $channel);
    }

    private function applyReturnScopes($query, ?int $branchId, ?int $warehouseId, ?int $customerId, string $channel): void
    {
        if ($branchId) $query->where('return.branch_id', $branchId);
        elseif (current_branch_id()) $query->where('return.branch_id', current_branch_id());
        if ($warehouseId) $query->where('return.warehouse_id', $warehouseId);
        if ($customerId) $query->where('sale.customer_id', $customerId);
        if (in_array($channel, ['POS', 'WHOLESALE'], true)) $query->where('sale.channel', $channel);
    }

    private function applyReceiptScopes($query, ?int $branchId, ?int $customerId, string $channel): void
    {
        if ($branchId) $query->where('receipt.branch_id', $branchId);
        elseif (current_branch_id()) $query->where('receipt.branch_id', current_branch_id());
        if ($customerId) $query->where('receipt.customer_id', $customerId);
        if (in_array($channel, ['POS', 'WHOLESALE'], true)) $query->where('sale.channel', $channel);
    }

    private function applyInitialPaymentScopes($query, ?int $branchId, ?int $warehouseId, ?int $customerId, string $channel): void
    {
        if ($branchId) $query->where('sale.branch_id', $branchId);
        elseif (current_branch_id()) $query->where('sale.branch_id', current_branch_id());
        if ($warehouseId) $query->where('sale.warehouse_id', $warehouseId);
        if ($customerId) $query->where('sale.customer_id', $customerId);
        if (in_array($channel, ['POS', 'WHOLESALE'], true)) $query->where('sale.channel', $channel);
    }
}
