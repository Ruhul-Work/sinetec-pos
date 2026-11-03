<?php

namespace App\Services\Purchase;

use App\Enums\Purchase\PurchasePaymentStatus;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Enums\Purchase\PurchaseReturnStatus;
use App\Models\Purchase\PurchaseReceipt;
use Illuminate\Support\Facades\DB;

final class SupplierDueService
{
    /** @return array{receipt_total: float, returned_total: float, paid_total: float, due: float} */
    public function receiptDue(PurchaseReceipt $receipt, ?int $excludePaymentId = null): array
    {
        $returns = (float) DB::table('purchase_return')->where('purchase_receipt_id', $receipt->id)->where('status', PurchaseReturnStatus::Posted->value)->sum('total');
        $payments = DB::table('purchase_payment')->where('purchase_receipt_id', $receipt->id)->where('status', PurchasePaymentStatus::Posted->value);
        if ($excludePaymentId) {
            $payments->where('id', '!=', $excludePaymentId);
        }
        $paid = (float) $payments->sum('amount');
        $total = (float) $receipt->total;

        return ['receipt_total' => $total, 'returned_total' => $returns, 'paid_total' => $paid, 'due' => max(0, round($total - $returns - $paid, 4))];
    }

    /** @return array{receipt_total: float, returned_total: float, paid_total: float, due: float} */
    public function postedReceiptDue(int $receiptId): array
    {
        $receipt = PurchaseReceipt::query()->whereKey($receiptId)->where('status', PurchaseReceiptStatus::Posted)->firstOrFail();

        return $this->receiptDue($receipt);
    }
}
