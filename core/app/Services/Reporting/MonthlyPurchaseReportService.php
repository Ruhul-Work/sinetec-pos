<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportingPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MonthlyPurchaseReportService
{
    /** @return array<string, mixed> */
    public function report(?int $periodId, ?int $branchId, ?int $warehouseId, ?int $supplierId, string $domain = ''): array
    {
        $period = $periodId
            ? ReportingPeriod::query()->whereKey($periodId)->first()
            : ReportingPeriod::query()->orderByDesc('year')->orderByDesc('month')->first();

        $receiptQuery = DB::table('purchase_receipt as receipt')
            ->join('suppliers as supplier', 'supplier.id', '=', 'receipt.supplier_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'receipt.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'receipt.branch_id')
            ->where('receipt.status', 'POSTED');
        $returnQuery = DB::table('purchase_return as return')
            ->join('suppliers as supplier', 'supplier.id', '=', 'return.supplier_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'return.warehouse_id')
            ->join('branches as branch', 'branch.id', '=', 'return.branch_id')
            ->where('return.status', 'POSTED');
        $paymentQuery = DB::table('purchase_payment as payment')
            ->join('suppliers as supplier', 'supplier.id', '=', 'payment.supplier_id')
            ->join('branches as branch', 'branch.id', '=', 'payment.branch_id')
            ->where('payment.status', 'POSTED');

        if ($period) {
            $receiptQuery->whereBetween('receipt.receipt_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()]);
            $returnQuery->whereBetween('return.return_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()]);
            $paymentQuery->whereBetween('payment.payment_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()]);
        }

        $this->applyReceiptScopes($receiptQuery, $branchId, $warehouseId, $supplierId);
        $this->applyReturnScopes($returnQuery, $branchId, $warehouseId, $supplierId);
        $this->applyPaymentScopes($paymentQuery, $branchId, $supplierId);

        $receipts = (clone $receiptQuery)
            ->select([
                'receipt.id',
                'receipt.receipt_no',
                'receipt.receipt_date',
                'receipt.subtotal',
                'receipt.discount',
                'receipt.tax',
                'receipt.total',
                'supplier.name as supplier_name',
                'warehouse.name as warehouse_name',
                'branch.name as branch_name',
            ])
            ->orderBy('receipt.receipt_date')
            ->orderBy('receipt.receipt_no')
            ->get();

        $returns = (clone $returnQuery)
            ->select([
                'return.id',
                'return.return_no',
                'return.return_date',
                'return.total',
                'supplier.name as supplier_name',
                'warehouse.name as warehouse_name',
                'branch.name as branch_name',
            ])
            ->orderBy('return.return_date')
            ->orderBy('return.return_no')
            ->get();

        $payments = (clone $paymentQuery)
            ->select([
                'payment.id',
                'payment.payment_no',
                'payment.payment_date',
                'payment.amount',
                'supplier.name as supplier_name',
                'branch.name as branch_name',
            ])
            ->orderBy('payment.payment_date')
            ->orderBy('payment.payment_no')
            ->get();

        $lineRows = collect();
        $receiptIds = $receipts->pluck('id')->all();
        if ($receipts->isNotEmpty()) {
            $rawLines = DB::table('purchase_receipt_item as item')
                ->join('purchase_receipt as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')
                ->join('raw_materials as item_master', 'item_master.id', '=', 'item.raw_material_id')
                ->join('units as unit', 'unit.id', '=', 'item.unit_id')
                ->whereIn('item.purchase_receipt_id', $receiptIds)
                ->selectRaw("'RAW_MATERIAL' as domain,item.id as line_id,receipt.receipt_no,receipt.receipt_date,item_master.material_code as item_code,item_master.name as item_name,unit.code as unit_code,item.base_quantity,item.unit_rate,item.line_total");
            $partLines = DB::table('purchase_receipt_item as item')
                ->join('purchase_receipt as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')
                ->join('parts as item_master', 'item_master.id', '=', 'item.part_id')
                ->join('units as unit', 'unit.id', '=', 'item.unit_id')
                ->whereIn('item.purchase_receipt_id', $receiptIds)
                ->selectRaw("'PART' as domain,item.id as line_id,receipt.receipt_no,receipt.receipt_date,item_master.part_code as item_code,item_master.name as item_name,unit.code as unit_code,item.base_quantity,item.unit_rate,item.line_total");

            $lineRows = DB::query()->fromSub($rawLines->unionAll($partLines), 'purchase_lines')
                ->when($domain !== '', fn ($query) => $query->where('domain', $domain))
                ->orderBy('receipt_date')
                ->orderBy('line_id')
                ->get();
        }

        $receiptTotal = $receipts->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->total, 4), '0.0000');
        $returnTotal = $returns->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->total, 4), '0.0000');
        $paymentTotal = $payments->reduce(fn (string $total, object $row): string => bcadd($total, (string) $row->amount, 4), '0.0000');
        $netPurchase = bcsub($receiptTotal, $returnTotal, 4);

        return [
            'period' => $period,
            'receipts' => $receipts,
            'returns' => $returns,
            'payments' => $payments,
            'lines' => $lineRows,
            'receipt_total' => $receiptTotal,
            'return_total' => $returnTotal,
            'net_purchase' => $netPurchase,
            'payment_total' => $paymentTotal,
        ];
    }

    private function applyReceiptScopes($query, ?int $branchId, ?int $warehouseId, ?int $supplierId): void
    {
        if ($branchId) {
            $query->where('receipt.branch_id', $branchId);
        } elseif (current_branch_id()) {
            $query->where('receipt.branch_id', current_branch_id());
        }
        if ($warehouseId) {
            $query->where('receipt.warehouse_id', $warehouseId);
        }
        if ($supplierId) {
            $query->where('receipt.supplier_id', $supplierId);
        }
    }

    private function applyReturnScopes($query, ?int $branchId, ?int $warehouseId, ?int $supplierId): void
    {
        if ($branchId) {
            $query->where('return.branch_id', $branchId);
        } elseif (current_branch_id()) {
            $query->where('return.branch_id', current_branch_id());
        }
        if ($warehouseId) {
            $query->where('return.warehouse_id', $warehouseId);
        }
        if ($supplierId) {
            $query->where('return.supplier_id', $supplierId);
        }
    }

    private function applyPaymentScopes($query, ?int $branchId, ?int $supplierId): void
    {
        if ($branchId) {
            $query->where('payment.branch_id', $branchId);
        } elseif (current_branch_id()) {
            $query->where('payment.branch_id', current_branch_id());
        }
        if ($supplierId) {
            $query->where('payment.supplier_id', $supplierId);
        }
    }
}
