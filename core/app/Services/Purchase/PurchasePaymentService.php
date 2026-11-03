<?php

namespace App\Services\Purchase;

use App\Enums\Purchase\PurchasePaymentStatus;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Exceptions\Purchase\InvalidPurchasePaymentException;
use App\Models\backend\Account;
use App\Models\Purchase\PurchasePayment;
use App\Models\Purchase\PurchaseReceipt;
use App\Services\Finance\PurchaseJournalService;
use Illuminate\Support\Facades\DB;

final class PurchasePaymentService
{
    public function __construct(private readonly SupplierDueService $due, private readonly PurchaseJournalService $journals) {}

    /** @param array{purchase_receipt_id:int, payment_type_id:int, account_id:int, payment_date:string, amount:string|float|int, reference_no?:string|null, note?:string|null} $data */
    public function create(array $data, int $userId): PurchasePayment
    {
        return DB::transaction(function () use ($data, $userId): PurchasePayment {
            $receipt = $this->receipt((int) $data['purchase_receipt_id']);
            $account = $this->cashAccount((int) $data['account_id'], (int) $receipt->branch_id);
            $amount = $this->amount($data['amount']);
            if ($amount > $this->due->receiptDue($receipt)['due']) {
                throw new InvalidPurchasePaymentException('Payment cannot exceed this receipt’s current due amount.');
            }

            return PurchasePayment::query()->create([
                'payment_no' => 'PAY-'.now()->format('Ymd-His-u'), 'branch_id' => $receipt->branch_id, 'supplier_id' => $receipt->supplier_id,
                'purchase_receipt_id' => $receipt->id, 'payment_type_id' => $data['payment_type_id'], 'account_id' => $account->id,
                'payment_date' => $data['payment_date'], 'amount' => $amount, 'reference_no' => trim((string) ($data['reference_no'] ?? '')) ?: null,
                'status' => PurchasePaymentStatus::Draft, 'note' => trim((string) ($data['note'] ?? '')) ?: null, 'created_by' => $userId,
            ]);
        }, 3);
    }

    public function post(PurchasePayment $payment, int $userId): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $userId): PurchasePayment {
            $payment = PurchasePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status === PurchasePaymentStatus::Posted) {
                return $payment;
            }
            if ($payment->status === PurchasePaymentStatus::Void) {
                throw new InvalidPurchasePaymentException('A void payment cannot be posted.');
            }
            $receipt = $this->receipt((int) $payment->purchase_receipt_id);
            if ((float) $payment->amount > $this->due->receiptDue($receipt, $payment->id)['due']) {
                throw new InvalidPurchasePaymentException('The receipt due changed; this payment is now greater than the remaining due.');
            }
            $this->cashAccount((int) $payment->account_id, (int) $payment->branch_id);
            $this->journals->createPaymentDraft($payment, $userId);
            $payment->update(['status' => PurchasePaymentStatus::Posted, 'posted_by' => $userId, 'posted_at' => now()]);

            return $payment->fresh();
        }, 3);
    }

    private function receipt(int $id): PurchaseReceipt
    {
        return PurchaseReceipt::query()->whereKey($id)->where('status', PurchaseReceiptStatus::Posted)->lockForUpdate()->first()
            ?? throw new InvalidPurchasePaymentException('Select a posted purchase receipt.');
    }

    private function cashAccount(int $id, int $branchId): Account
    {
        $account = Account::query()->with('type')->whereKey($id)->where('is_active', true)->first();
        if (! $account || $account->type?->code !== 'ASSET' || ! DB::table('branch_accounts')->where('branch_id', $branchId)->where('account_id', $id)->where('is_active', true)->exists()) {
            throw new InvalidPurchasePaymentException('Choose an active Asset account assigned to the receipt branch.');
        }

        return $account;
    }

    private function amount(mixed $amount): float
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidPurchasePaymentException('Payment amount must be greater than zero.');
        }

        return round((float) $amount, 4);
    }
}
