<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalDraftResult;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Purchase\PurchaseReceiptStatus;
use App\Enums\Purchase\PurchaseReturnStatus;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\Purchase\PurchasePayment;
use App\Models\Purchase\PurchaseReceipt;
use App\Models\Purchase\PurchaseReturn;

final class PurchaseJournalService
{
    public function __construct(
        private readonly FinanceAccountMappingService $mappings,
        private readonly JournalDraftService $journals,
    ) {}

    public function createReceiptDraft(PurchaseReceipt $receipt, ?int $userId = null): JournalDraftResult
    {
        $receipt->loadMissing('items');
        if ($receipt->items->isEmpty()) {
            throw new InvalidJournalDraftException('A purchase receipt journal requires at least one receipt item.');
        }

        $rawTotal = $this->sumAmounts($receipt->items->whereNotNull('raw_material_id')->pluck('line_total')->all());
        $partTotal = $this->sumAmounts($receipt->items->whereNotNull('part_id')->pluck('line_total')->all());
        $tax = $this->amount($receipt->tax);
        $discount = $this->amount($receipt->discount);
        $payable = $this->amount($receipt->total);
        $lines = [];

        if ($this->isPositive($rawTotal)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::RawMaterialInventory, (int) $receipt->branch_id),
                debit: $rawTotal,
                memo: 'Raw material received',
            );
        }
        if ($this->isPositive($partTotal)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::PartInventory, (int) $receipt->branch_id),
                debit: $partTotal,
                memo: 'Parts received',
            );
        }
        if ($this->isPositive($tax)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::InputTax, (int) $receipt->branch_id),
                debit: $tax,
                memo: 'Recoverable purchase input tax',
            );
        }
        if ($this->isPositive($discount)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::PurchaseDiscount, (int) $receipt->branch_id),
                credit: $discount,
                memo: 'Purchase discount received',
            );
        }
        if ($this->isPositive($payable)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::SupplierPayable, (int) $receipt->branch_id),
                credit: $payable,
                memo: 'Amount payable to supplier',
            );
        }

        return $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'PURCHASE',
            branchId: (int) $receipt->branch_id,
            entryDate: $receipt->receipt_date,
            sourceType: 'purchase_receipt',
            sourceId: (int) $receipt->id,
            postingKey: 'journal:purchase_receipt:'.$receipt->id,
            lines: $lines,
            narration: 'Purchase receipt '.$receipt->receipt_no,
            createdBy: $userId ?? $receipt->posted_by ?? $receipt->created_by,
        ));
    }

    public function createReturnDraft(PurchaseReturn $return, ?int $userId = null): JournalDraftResult
    {
        $return->loadMissing(['items', 'receipt']);
        if ($return->items->isEmpty()) {
            throw new InvalidJournalDraftException('A purchase return journal requires at least one return item.');
        }

        $rawTotal = $this->sumAmounts($return->items->whereNotNull('raw_material_id')->pluck('line_total')->all());
        $partTotal = $this->sumAmounts($return->items->whereNotNull('part_id')->pluck('line_total')->all());
        $payableReversal = $this->sumAmounts([$rawTotal, $partTotal]);
        $branchId = (int) $return->branch_id;
        $lines = [
            new JournalLineData(
                accountId: $this->account(FinanceMappingKey::SupplierPayable, $branchId),
                debit: $payableReversal,
                memo: 'Supplier payable reversed for returned items',
            ),
        ];

        if ($this->isPositive($rawTotal)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::RawMaterialInventory, $branchId),
                credit: $rawTotal,
                memo: 'Raw material returned to supplier',
            );
        }
        if ($this->isPositive($partTotal)) {
            $lines[] = new JournalLineData(
                accountId: $this->account(FinanceMappingKey::PartInventory, $branchId),
                credit: $partTotal,
                memo: 'Parts returned to supplier',
            );
        }

        return $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'PURCHASE_RETURN',
            branchId: $branchId,
            entryDate: $return->return_date,
            sourceType: 'purchase_return',
            sourceId: (int) $return->id,
            postingKey: 'journal:purchase_return:'.$return->id,
            lines: $lines,
            narration: 'Purchase return '.$return->return_no.' against receipt '.($return->receipt?->receipt_no ?? '#'.$return->purchase_receipt_id),
            createdBy: $userId ?? $return->posted_by ?? $return->created_by,
        ));
    }

    public function createPaymentDraft(PurchasePayment $payment, ?int $userId = null): JournalDraftResult
    {
        $payment->loadMissing('receipt');
        $amount = $this->amount($payment->amount);

        return $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'PAYMENT', branchId: (int) $payment->branch_id, entryDate: $payment->payment_date,
            sourceType: 'purchase_payment', sourceId: (int) $payment->id, postingKey: 'journal:purchase_payment:'.$payment->id,
            lines: [
                new JournalLineData(accountId: $this->account(FinanceMappingKey::SupplierPayable, (int) $payment->branch_id), debit: $amount, memo: 'Supplier payment against '.($payment->receipt?->receipt_no ?? '#'.$payment->purchase_receipt_id)),
                new JournalLineData(accountId: (int) $payment->account_id, credit: $amount, memo: 'Cash or bank payment'),
            ],
            narration: 'Supplier payment '.$payment->payment_no, createdBy: $userId ?? $payment->posted_by ?? $payment->created_by,
        ));
    }

    /** @return array{receipt_created: int, receipt_existing: int, return_created: int, return_existing: int} */
    public function reconcilePostedDocuments(?int $branchId = null): array
    {
        $summary = [
            'receipt_created' => 0,
            'receipt_existing' => 0,
            'return_created' => 0,
            'return_existing' => 0,
        ];

        PurchaseReceipt::query()
            ->where('status', PurchaseReceiptStatus::Posted)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('id')
            ->each(function (PurchaseReceipt $receipt) use (&$summary): void {
                $result = $this->createReceiptDraft($receipt);
                $summary[$result->wasAlreadyCreated ? 'receipt_existing' : 'receipt_created']++;
            });

        PurchaseReturn::query()
            ->where('status', PurchaseReturnStatus::Posted)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('id')
            ->each(function (PurchaseReturn $return) use (&$summary): void {
                $result = $this->createReturnDraft($return);
                $summary[$result->wasAlreadyCreated ? 'return_existing' : 'return_created']++;
            });

        return $summary;
    }

    private function account(FinanceMappingKey $key, int $branchId): int
    {
        return (int) $this->mappings->resolveRequired($key, $branchId)->id;
    }

    /** @param array<int, mixed> $amounts */
    private function sumAmounts(array $amounts): string
    {
        $sum = '0';
        foreach ($amounts as $amount) {
            $sum = $this->addIntegers($sum, $this->scaled($this->amount($amount)));
        }

        return $this->unscaled($sum);
    }

    private function amount(mixed $amount): string
    {
        $value = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new InvalidJournalDraftException('Purchase journal amount has an invalid monetary value.');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
    }

    private function isPositive(string $amount): bool
    {
        return $this->scaled($amount) !== '0';
    }

    private function scaled(string $amount): string
    {
        return ltrim(str_replace('.', '', $amount), '0') ?: '0';
    }

    private function unscaled(string $amount): string
    {
        $amount = str_pad(ltrim($amount, '0') ?: '0', 5, '0', STR_PAD_LEFT);

        return substr($amount, 0, -4).'.'.substr($amount, -4);
    }

    private function addIntegers(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $sum = '';
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $digit = $carry;
            $digit += $leftIndex >= 0 ? (int) $left[$leftIndex--] : 0;
            $digit += $rightIndex >= 0 ? (int) $right[$rightIndex--] : 0;
            $sum = ($digit % 10).$sum;
            $carry = intdiv($digit, 10);
        }

        return ltrim($sum, '0') ?: '0';
    }
}
