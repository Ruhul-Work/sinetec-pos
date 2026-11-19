<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\FinanceMappingKey;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\Sales\SaleReturn;

final class SaleReturnJournalService
{
    public function __construct(
        private readonly FinanceAccountMappingService $mappings,
        private readonly JournalDraftService $journals,
        private readonly JournalWorkflowService $workflow,
    ) {}

    public function createAndPost(SaleReturn $saleReturn, int $userId): void
    {
        $branchId = (int) $saleReturn->branch_id;
        $lines = [];
        $reversedRevenue = bcsub(
            (string) $saleReturn->subtotal,
            (string) $saleReturn->discount,
            4,
        );

        if ($this->positive($reversedRevenue)) {
            $lines[] = new JournalLineData(
                accountId: $this->mappedAccount(FinanceMappingKey::SalesRevenue, $branchId),
                debit: $reversedRevenue,
                memo: 'Sales revenue reversed for '.$saleReturn->return_no,
            );
        }

        if ($this->positive($saleReturn->tax)) {
            $lines[] = new JournalLineData(
                accountId: $this->mappedAccount(FinanceMappingKey::OutputTax, $branchId),
                debit: $saleReturn->tax,
                memo: 'Output tax reversed for '.$saleReturn->return_no,
            );
        }

        if ($this->positive($saleReturn->due_adjustment)) {
            $lines[] = new JournalLineData(
                accountId: $this->mappedAccount(FinanceMappingKey::CustomerReceivable, $branchId),
                credit: $saleReturn->due_adjustment,
                memo: 'Customer due adjusted by '.$saleReturn->return_no,
            );
        }

        foreach ($saleReturn->payments->groupBy('account_id') as $accountId => $payments) {
            $lines[] = new JournalLineData(
                accountId: (int) $accountId,
                credit: $this->sum($payments->pluck('amount')->all()),
                memo: 'Customer refund paid for '.$saleReturn->return_no,
            );
        }

        if (count($lines) < 2) {
            throw new InvalidJournalDraftException(
                'A posted Sales Return requires a balanced Finance journal.',
            );
        }

        $result = $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'SALE_RETURN',
            branchId: $branchId,
            entryDate: $saleReturn->return_at,
            sourceType: 'sale_return',
            sourceId: (int) $saleReturn->id,
            postingKey: 'journal:sale-return:'.$saleReturn->id,
            lines: $lines,
            narration: 'Sales Return '.$saleReturn->return_no
                .' against '.$saleReturn->sale->sale_no,
            createdBy: $userId,
        ));

        $journal = $this->workflow->approve($result->journal, $userId);
        $this->workflow->post($journal, $userId);
    }

    private function mappedAccount(FinanceMappingKey $key, int $branchId): int
    {
        return (int) $this->mappings->resolveRequired($key, $branchId)->id;
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

    private function positive(mixed $amount): bool
    {
        return bccomp((string) $amount, '0', 4) === 1;
    }
}
