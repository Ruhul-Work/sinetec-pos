<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalDraftResult;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\FinanceMappingKey;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Models\Production\PartProductionBatch;

final class PartProductionJournalService
{
    public function __construct(
        private readonly FinanceAccountMappingService $mappings,
        private readonly JournalDraftService $journals,
    ) {}

    public function createDraft(PartProductionBatch $batch, ?int $userId = null): ?JournalDraftResult
    {
        $batch->loadMissing(['bom.part', 'consumptions', 'expenses.category']);

        $materialCost = $this->sum($batch->consumptions->pluck('total_cost')->all());
        $expenseCost = $this->sum($batch->expenses->pluck('amount')->all());
        $partCost = bcadd($materialCost, $expenseCost, 4);

        if (bccomp($partCost, '0.0000', 4) === 0) {
            return null;
        }

        $branchId = (int) $batch->branch_id;
        $outputAccount = bccomp((string) $batch->good_quantity, '0', 6) > 0
            ? FinanceMappingKey::PartInventory
            : FinanceMappingKey::ProductionLoss;
        $outputMemo = $outputAccount === FinanceMappingKey::PartInventory
            ? 'Part production output cost'
            : 'Fully rejected production batch cost';

        $lines = [
            new JournalLineData(
                accountId: (int) $this->mappings
                    ->resolveRequired($outputAccount, $branchId)
                    ->id,
                debit: $partCost,
                memo: $outputMemo,
            ),
        ];

        if (bccomp($materialCost, '0.0000', 4) > 0) {
            $lines[] = new JournalLineData(
                accountId: (int) $this->mappings
                    ->resolveRequired(FinanceMappingKey::RawMaterialInventory, $branchId)
                    ->id,
                credit: $materialCost,
                memo: 'Raw materials consumed in part production',
            );
        }

        foreach ($batch->expenses->groupBy('account_id') as $accountId => $expenses) {
            if ((int) $accountId < 1) {
                throw new FinanceConfigurationException(
                    'Every allocated production expense category requires an expense account before completion.',
                );
            }

            $lines[] = new JournalLineData(
                accountId: (int) $accountId,
                credit: $this->sum($expenses->pluck('amount')->all()),
                memo: 'Direct production expense allocated to part inventory',
            );
        }

        return $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'PRODUCTION',
            branchId: $branchId,
            entryDate: $batch->production_date,
            sourceType: 'part_production_batch',
            sourceId: (int) $batch->id,
            postingKey: 'journal:part_production_batch:'.$batch->id,
            lines: $lines,
            narration: 'Part production '.$batch->batch_no.' for '.$batch->bom?->part?->name,
            createdBy: $userId ?? $batch->completed_by ?? $batch->created_by,
        ));
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
}
