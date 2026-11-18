<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\FinanceMappingKey;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\Sales\Sale;
use App\Models\Sales\SalePayment;
use Illuminate\Support\Collection;

final class SalesJournalService
{
    public function __construct(
        private readonly FinanceAccountMappingService $mappings,
        private readonly JournalDraftService $journals,
        private readonly JournalWorkflowService $workflow,
    ) {}

    /** @param Collection<int, SalePayment> $payments */
    public function createAndPost(Sale $sale, Collection $payments, int $userId): void
    {
        $branchId = (int) $sale->branch_id;
        $lines = [];

        foreach ($payments->groupBy('account_id') as $accountId => $accountPayments) {
            $lines[] = new JournalLineData(
                accountId: (int) $accountId,
                debit: $this->sum($accountPayments->pluck('amount')->all()),
                memo: $sale->channel->label().' payment received for '.$sale->sale_no,
            );
        }

        if ($this->positive($sale->due_amount)) {
            $lines[] = new JournalLineData(
                accountId: $this->mappedAccount(FinanceMappingKey::CustomerReceivable, $branchId),
                debit: $sale->due_amount,
                memo: 'Customer due for '.$sale->sale_no,
            );
        }

        $netRevenue = bcsub((string) $sale->total, (string) $sale->tax, 4);
        if ($this->positive($netRevenue)) {
            $lines[] = new JournalLineData(
                accountId: $this->mappedAccount(FinanceMappingKey::SalesRevenue, $branchId),
                credit: $netRevenue,
                memo: 'Net sales revenue for '.$sale->sale_no,
            );
        }

        if ($this->positive($sale->tax)) {
            $lines[] = new JournalLineData(
                accountId: $this->mappedAccount(FinanceMappingKey::OutputTax, $branchId),
                credit: $sale->tax,
                memo: 'Output tax collected for '.$sale->sale_no,
            );
        }

        if (count($lines) < 2) {
            throw new InvalidJournalDraftException('A completed Sale requires a balanced Finance journal.');
        }

        $result = $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'SALE',
            branchId: $branchId,
            entryDate: $sale->sale_at,
            sourceType: 'sale',
            sourceId: (int) $sale->id,
            postingKey: 'journal:sale:'.$sale->id,
            lines: $lines,
            narration: $sale->channel->label().' sale '.$sale->sale_no,
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
