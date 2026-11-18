<?php

namespace App\Services\Sales;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Sales\SaleStatus;
use App\Exceptions\Sales\InvalidCustomerReceiptException;
use App\Models\backend\Account;
use App\Models\backend\JournalEntry;
use App\Models\Sales\CustomerReceipt;
use App\Models\Sales\Sale;
use App\Models\Sales\SalePayment;
use App\Services\Finance\FinanceAccountMappingService;
use App\Services\Finance\JournalDraftService;
use App\Services\Finance\JournalWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CustomerReceiptService
{
    public function __construct(
        private readonly FinanceAccountMappingService $mappings,
        private readonly JournalDraftService $journals,
        private readonly JournalWorkflowService $journalWorkflow,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    public function collect(
        Sale $sale,
        string $receiptAt,
        array $payments,
        ?string $note,
        int $userId,
    ): CustomerReceipt {
        return DB::transaction(function () use ($sale, $receiptAt, $payments, $note, $userId): CustomerReceipt {
            $sale = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCollectibleSale($sale);
            $this->assertUser($userId);
            $receiptAt = $this->receiptDate($receiptAt, $sale);

            [$normalizedPayments, $appliedTotal] = $this->normalizePayments(
                $payments,
                (int) $sale->branch_id,
                (string) $sale->due_amount,
            );

            $receipt = CustomerReceipt::query()->create([
                'receipt_no' => $this->nextReceiptNumber(),
                'branch_id' => $sale->branch_id,
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'receipt_at' => $receiptAt,
                'amount' => $appliedTotal,
                'due_before' => $sale->due_amount,
                'due_after' => bcsub((string) $sale->due_amount, $appliedTotal, 4),
                'status' => 'POSTED',
                'note' => $this->nullableText($note),
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
            $receipt->setRelation('sale', $sale);

            foreach ($normalizedPayments as $payment) {
                SalePayment::query()->create([
                    'payment_no' => $this->nextPaymentNumber(),
                    'sale_id' => $sale->id,
                    'customer_receipt_id' => $receipt->id,
                    'payment_type_id' => $payment['payment_type_id'],
                    'account_id' => $payment['account_id'],
                    'payment_at' => $receiptAt,
                    'amount' => $payment['amount'],
                    'tendered_amount' => $payment['tendered_amount'],
                    'change_amount' => $payment['change_amount'],
                    'reference_no' => $payment['reference_no'],
                    'status' => 'POSTED',
                    'created_by' => $userId,
                ]);
            }

            $sale->update([
                'paid_amount' => bcadd((string) $sale->paid_amount, $appliedTotal, 4),
                'due_amount' => bcsub((string) $sale->due_amount, $appliedTotal, 4),
            ]);

            $journal = $this->createJournal(
                $receipt,
                collect($normalizedPayments),
                $userId,
            );
            $journal = $this->journalWorkflow->approve($journal, $userId);
            $this->journalWorkflow->post($journal, $userId);

            return $receipt->fresh([
                'sale',
                'customer',
                'payments.paymentType',
                'payments.account',
            ]);
        }, 3);
    }

    private function assertCollectibleSale(Sale $sale): void
    {
        if ($sale->status !== SaleStatus::Posted) {
            throw new InvalidCustomerReceiptException('Due can be collected only from a Posted Sale.');
        }
        if ($sale->customer_id === null) {
            throw new InvalidCustomerReceiptException('A registered Customer is required for due collection.');
        }
        if (bccomp((string) $sale->due_amount, '0', 4) !== 1) {
            throw new InvalidCustomerReceiptException('This Sale has no remaining due amount.');
        }
    }

    private function assertUser(int $userId): void
    {
        if (! DB::table('users')->where('id', $userId)->exists()) {
            throw new InvalidCustomerReceiptException('An active operator is required for due collection.');
        }
    }

    private function receiptDate(string $value, Sale $sale): string
    {
        try {
            $date = CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new InvalidCustomerReceiptException('The collection date is invalid.');
        }

        if ($date->lt($sale->sale_at)) {
            throw new InvalidCustomerReceiptException('Collection date cannot be earlier than the Sale date.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{0: array<int, array<string, mixed>>, 1: string}
     */
    private function normalizePayments(array $payments, int $branchId, string $dueAmount): array
    {
        if ($payments === []) {
            throw new InvalidCustomerReceiptException('Add at least one payment row.');
        }

        $normalized = [];
        $appliedTotal = '0.0000';

        foreach ($payments as $index => $payment) {
            $tenderedAmount = $this->amount(
                $payment['amount'] ?? null,
                'payment row '.($index + 1),
            );
            $paymentType = $this->paymentType((int) ($payment['payment_type_id'] ?? 0));
            $account = $this->cashAccount(
                (int) ($payment['account_id'] ?? 0),
                $branchId,
            );
            $remainingDue = bcsub($dueAmount, $appliedTotal, 4);

            if (bccomp($remainingDue, '0', 4) !== 1) {
                throw new InvalidCustomerReceiptException(
                    'Remove unused payment rows after the due amount has been fully collected.',
                );
            }

            $exceedsDue = bccomp($tenderedAmount, $remainingDue, 4) === 1;
            if ($exceedsDue && ! $paymentType->allows_change) {
                throw new InvalidCustomerReceiptException(
                    "{$paymentType->name} cannot exceed the remaining due. Only a Cash-type payment may return change.",
                );
            }

            $appliedAmount = $exceedsDue ? $remainingDue : $tenderedAmount;
            $changeAmount = $exceedsDue
                ? bcsub($tenderedAmount, $appliedAmount, 4)
                : '0.0000';

            $normalized[] = [
                'payment_type_id' => (int) $paymentType->id,
                'account_id' => (int) $account->id,
                'amount' => $appliedAmount,
                'tendered_amount' => $tenderedAmount,
                'change_amount' => $changeAmount,
                'reference_no' => $this->nullableText($payment['reference_no'] ?? null),
            ];
            $appliedTotal = bcadd($appliedTotal, $appliedAmount, 4);
        }

        return [$normalized, $appliedTotal];
    }

    private function paymentType(int $paymentTypeId): object
    {
        $paymentType = DB::table('payment_types')
            ->where('id', $paymentTypeId)
            ->where('is_active', true)
            ->first(['id', 'name', 'allows_change']);

        if (! $paymentType) {
            throw new InvalidCustomerReceiptException(
                'Select an active Payment Method for every payment row.',
            );
        }

        return $paymentType;
    }

    private function cashAccount(int $accountId, int $branchId): Account
    {
        $account = Account::query()
            ->with('type')
            ->whereKey($accountId)
            ->where('is_active', true)
            ->first();
        $assigned = DB::table('branch_accounts')
            ->where('branch_id', $branchId)
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->exists();

        if (! $account
            || ! $account->is_cash_bank_account
            || $account->type?->code !== 'ASSET'
            || ! $assigned) {
            throw new InvalidCustomerReceiptException(
                'Choose an active Cash, Bank or Mobile Financial account assigned to the Sale branch.',
            );
        }

        return $account;
    }

    /** @param Collection<int, array<string, mixed>> $payments */
    private function createJournal(
        CustomerReceipt $receipt,
        Collection $payments,
        int $userId,
    ): JournalEntry {
        $lines = [];

        foreach ($payments->groupBy('account_id') as $accountId => $accountPayments) {
            $lines[] = new JournalLineData(
                accountId: (int) $accountId,
                debit: $this->sum($accountPayments->pluck('amount')->all()),
                memo: 'Customer payment received for '.$receipt->sale->sale_no,
            );
        }

        $receivableAccount = $this->mappings->resolveRequired(
            FinanceMappingKey::CustomerReceivable,
            (int) $receipt->branch_id,
        );
        $lines[] = new JournalLineData(
            accountId: (int) $receivableAccount->id,
            credit: $receipt->amount,
            memo: 'Customer due collected for '.$receipt->sale->sale_no,
        );

        return $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'RECEIPT',
            branchId: (int) $receipt->branch_id,
            entryDate: $receipt->receipt_at->toDateString(),
            sourceType: 'customer_receipt',
            sourceId: (int) $receipt->id,
            postingKey: 'journal:customer-receipt:'.$receipt->id,
            lines: $lines,
            narration: 'Customer due collection '.$receipt->receipt_no
                .' for '.$receipt->sale->sale_no,
            createdBy: $userId,
        ))->journal;
    }

    /** @param array<int, mixed> $amounts */
    private function sum(array $amounts): string
    {
        $total = '0.0000';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        return $total;
    }

    private function amount(mixed $value, string $field): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $raw)) {
            throw new InvalidCustomerReceiptException("The {$field} amount is invalid.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $normalized = (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
        if (bccomp($normalized, '0', 4) !== 1) {
            throw new InvalidCustomerReceiptException(
                "The {$field} amount must be greater than zero.",
            );
        }

        return $normalized;
    }

    private function nextReceiptNumber(): string
    {
        do {
            $number = 'CR-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (CustomerReceipt::query()->where('receipt_no', $number)->exists());

        return $number;
    }

    private function nextPaymentNumber(): string
    {
        do {
            $number = 'SPAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (SalePayment::query()->where('payment_no', $number)->exists());

        return $number;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
