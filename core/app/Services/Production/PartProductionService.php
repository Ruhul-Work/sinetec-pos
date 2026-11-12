<?php

namespace App\Services\Production;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Production\PartProductionStatus;
use App\Enums\Production\ProductionQcStatus;
use App\Exceptions\Production\InvalidPartProductionException;
use App\Models\backend\Warehouse;
use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Inventory\RawMaterialStockMovement;
use App\Models\Production\PartBom;
use App\Models\Production\PartBomItem;
use App\Models\Production\PartProductionBatch;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Finance\PartProductionJournalService;
use App\Services\Inventory\PartStockPostingService;
use App\Services\Inventory\RawMaterialStockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PartProductionService
{
    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly PartProductionJournalService $productionJournals,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $consumptions
     * @param  array<int, array<string, mixed>>  $expenses
     */
    public function createDraft(
        array $header,
        array $consumptions,
        array $expenses,
        ?int $userId,
    ): PartProductionBatch {
        return DB::transaction(function () use ($header, $consumptions, $expenses, $userId): PartProductionBatch {
            $context = $this->draftContext($header);
            $batch = PartProductionBatch::query()->create([
                'batch_no' => $this->nextNumber(),
                'part_bom_id' => $context['bom']->id,
                'branch_id' => $context['input_warehouse']->branch_id,
                'input_warehouse_id' => $context['input_warehouse']->id,
                'output_warehouse_id' => $context['output_warehouse']->id,
                'production_date' => $context['production_date'],
                'planned_quantity' => $context['planned_quantity'],
                'good_quantity' => $context['good_quantity'],
                'reject_quantity' => $context['reject_quantity'],
                'qc_status' => $context['qc_status'],
                'status' => PartProductionStatus::Draft,
                'qc_note' => $this->nullableText($header['qc_note'] ?? null),
                'created_by' => $userId,
            ]);

            $this->replaceLines($batch, $context['bom'], $consumptions, $expenses);

            return $this->load($batch);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $consumptions
     * @param  array<int, array<string, mixed>>  $expenses
     */
    public function updateDraft(
        PartProductionBatch $batch,
        array $header,
        array $consumptions,
        array $expenses,
    ): PartProductionBatch {
        return DB::transaction(function () use ($batch, $header, $consumptions, $expenses): PartProductionBatch {
            $batch = $this->lock($batch);
            $this->assertDraft($batch);
            $context = $this->draftContext($header);

            $batch->update([
                'part_bom_id' => $context['bom']->id,
                'branch_id' => $context['input_warehouse']->branch_id,
                'input_warehouse_id' => $context['input_warehouse']->id,
                'output_warehouse_id' => $context['output_warehouse']->id,
                'production_date' => $context['production_date'],
                'planned_quantity' => $context['planned_quantity'],
                'good_quantity' => $context['good_quantity'],
                'reject_quantity' => $context['reject_quantity'],
                'qc_status' => $context['qc_status'],
                'qc_note' => $this->nullableText($header['qc_note'] ?? null),
            ]);

            $this->replaceLines($batch, $context['bom'], $consumptions, $expenses);

            return $this->load($batch);
        }, 3);
    }

    public function complete(PartProductionBatch $batch, ?int $userId): PartProductionBatch
    {
        return DB::transaction(function () use ($batch, $userId): PartProductionBatch {
            $batch = $this->lock($batch);

            if ($batch->status === PartProductionStatus::Completed) {
                return $this->load($batch);
            }

            $this->assertDraft($batch);
            $batch->loadMissing(['bom.part', 'consumptions', 'expenses.category']);
            $this->assertCompletionResult($batch);

            if ($batch->consumptions->isEmpty()) {
                throw new InvalidPartProductionException('At least one raw material consumption line is required.');
            }

            $materialCost = '0.0000';

            foreach ($batch->consumptions->sortBy('id') as $consumption) {
                $unitCost = $this->latestRawMaterialCost(
                    (int) $consumption->raw_material_id,
                    (int) $batch->input_warehouse_id,
                    $batch->production_date->toDateString(),
                );
                $totalCost = $this->roundProduct((string) $consumption->base_quantity, $unitCost, 4);

                $consumption->update([
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ]);
                $materialCost = bcadd($materialCost, $totalCost, 4);

                $this->rawMaterialStock->post(new StockPostingData(
                    itemId: (int) $consumption->raw_material_id,
                    branchId: (int) $batch->branch_id,
                    warehouseId: (int) $batch->input_warehouse_id,
                    movementType: StockMovementType::PartProductionConsumption,
                    direction: StockDirection::Out,
                    quantity: $consumption->base_quantity,
                    sourceType: 'part_production_batch',
                    sourceId: (int) $batch->id,
                    postingKey: "part_production:{$batch->id}:consumption:{$consumption->id}",
                    movementAt: $batch->production_date,
                    unitCost: $unitCost,
                    sourceItemId: (int) $consumption->id,
                    note: 'Consumed for '.$batch->batch_no,
                    postedBy: $userId,
                ));
            }

            $expenseCost = $this->sumExpenses($batch);
            $totalCost = bcadd($materialCost, $expenseCost, 4);

            if (bccomp((string) $batch->good_quantity, '0', 6) > 0) {
                $outputUnitCost = $this->roundDivision($totalCost, (string) $batch->good_quantity, 4);

                $this->partStock->post(new StockPostingData(
                    itemId: (int) $batch->bom->part_id,
                    branchId: (int) $batch->branch_id,
                    warehouseId: (int) $batch->output_warehouse_id,
                    movementType: StockMovementType::PartProductionOutput,
                    direction: StockDirection::In,
                    quantity: $batch->good_quantity,
                    sourceType: 'part_production_batch',
                    sourceId: (int) $batch->id,
                    postingKey: "part_production:{$batch->id}:output",
                    movementAt: $batch->production_date,
                    unitCost: $outputUnitCost,
                    sourceItemId: (int) $batch->id,
                    note: 'Good output from '.$batch->batch_no,
                    postedBy: $userId,
                ));
            }

            $batch->update([
                'status' => PartProductionStatus::Completed,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            $this->productionJournals->createDraft($batch->fresh(), $userId);

            return $this->load($batch);
        }, 3);
    }

    /**
     * @return array{
     *     is_estimate: bool,
     *     material_cost: string,
     *     expense_cost: string,
     *     total_cost: string,
     *     good_output_unit_cost: string|null,
     *     lines: array<int, array{unit_cost: string, total_cost: string}>
     * }
     */
    public function materialCostPreview(PartProductionBatch $batch): array
    {
        $batch->loadMissing('consumptions');
        $isEstimate = $batch->status === PartProductionStatus::Draft;
        $materialCost = '0.0000';
        $lines = [];

        foreach ($batch->consumptions as $consumption) {
            $unitCost = $isEstimate
                ? $this->latestRawMaterialCost(
                    (int) $consumption->raw_material_id,
                    (int) $batch->input_warehouse_id,
                    $batch->production_date->toDateString(),
                )
                : (string) $consumption->unit_cost;
            $totalCost = $isEstimate
                ? $this->roundProduct((string) $consumption->base_quantity, $unitCost, 4)
                : (string) $consumption->total_cost;

            $lines[(int) $consumption->id] = [
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
            ];
            $materialCost = bcadd($materialCost, $totalCost, 4);
        }

        $batch->loadMissing('expenses');
        $expenseCost = $this->sumExpenses($batch);
        $totalCost = bcadd($materialCost, $expenseCost, 4);
        $goodOutputUnitCost = bccomp((string) $batch->good_quantity, '0', 6) > 0
            ? $this->roundDivision($totalCost, (string) $batch->good_quantity, 4)
            : null;

        return [
            'is_estimate' => $isEstimate,
            'material_cost' => $materialCost,
            'expense_cost' => $expenseCost,
            'total_cost' => $totalCost,
            'good_output_unit_cost' => $goodOutputUnitCost,
            'lines' => $lines,
        ];
    }

    public function deleteDraft(PartProductionBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $batch = $this->lock($batch);
            $this->assertDraft($batch);
            $batch->delete();
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function plannedConsumptions(PartBom $bom, mixed $plannedQuantity, ?int $warehouseId = null): array
    {
        $planned = $this->decimal($plannedQuantity, 6, false, 'Planned quantity');
        $bom->loadMissing(['items.rawMaterial.baseUnit', 'items.unit']);
        $scale = bcdiv($planned, (string) $bom->output_quantity, 12);

        return $bom->items->map(function (PartBomItem $item) use ($scale, $warehouseId): array {
            $quantity = bcmul((string) $item->required_quantity, $scale, 12);
            $wastageFactor = bcadd('1', bcdiv((string) $item->wastage_percent, '100', 12), 12);
            $quantity = $this->roundDecimal(bcmul($quantity, $wastageFactor, 12), 6);
            $baseQuantity = $this->convertToBase($item, $quantity);

            $available = null;
            if ($warehouseId) {
                $available = RawMaterialStockBalance::query()
                    ->where('raw_material_id', $item->raw_material_id)
                    ->where('warehouse_id', $warehouseId)
                    ->value('quantity') ?? '0.000000';
            }

            return [
                'raw_material_id' => (int) $item->raw_material_id,
                'raw_material_name' => $item->rawMaterial?->name,
                'raw_material_code' => $item->rawMaterial?->material_code,
                'unit_id' => (int) $item->unit_id,
                'unit_name' => $item->unit?->name,
                'unit_code' => $item->unit?->code,
                'base_unit_code' => $item->rawMaterial?->baseUnit?->code,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'available_quantity' => (string) $available,
                'wastage_percent' => (string) $item->wastage_percent,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function draftContext(array $header): array
    {
        $productionDate = trim((string) ($header['production_date'] ?? ''));
        $bom = PartBom::query()
            ->with(['part', 'items'])
            ->whereKey((int) ($header['part_bom_id'] ?? 0))
            ->where('status', 'ACTIVE')
            ->first();

        if (! $bom) {
            throw new InvalidPartProductionException('Select an active Part BOM.');
        }

        if (($bom->effective_from && $productionDate < $bom->effective_from->toDateString())
            || ($bom->effective_to && $productionDate > $bom->effective_to->toDateString())) {
            throw new InvalidPartProductionException('The production date is outside the selected BOM effective period.');
        }

        $inputWarehouse = $this->warehouse((int) ($header['input_warehouse_id'] ?? 0));
        $outputWarehouse = $this->warehouse((int) ($header['output_warehouse_id'] ?? 0));

        if ((int) $inputWarehouse->branch_id !== (int) $outputWarehouse->branch_id) {
            throw new InvalidPartProductionException('Input and output warehouses must belong to the same branch.');
        }

        return [
            'bom' => $bom,
            'input_warehouse' => $inputWarehouse,
            'output_warehouse' => $outputWarehouse,
            'production_date' => $productionDate,
            'planned_quantity' => $this->decimal($header['planned_quantity'] ?? null, 6, false, 'Planned quantity'),
            'good_quantity' => $this->decimal($header['good_quantity'] ?? 0, 6, true, 'Good quantity'),
            'reject_quantity' => $this->decimal($header['reject_quantity'] ?? 0, 6, true, 'Reject quantity'),
            'qc_status' => $this->qcStatus($header['qc_status'] ?? ProductionQcStatus::Pending->value),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $consumptions
     * @param  array<int, array<string, mixed>>  $expenses
     */
    private function replaceLines(
        PartProductionBatch $batch,
        PartBom $bom,
        array $consumptions,
        array $expenses,
    ): void {
        $normalizedConsumptions = $this->normalizeConsumptions($bom, $consumptions);
        $normalizedExpenses = $this->normalizeExpenses($expenses);

        $batch->consumptions()->delete();
        $batch->expenses()->delete();
        $batch->consumptions()->createMany($normalizedConsumptions);
        $batch->expenses()->createMany($normalizedExpenses);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function normalizeConsumptions(PartBom $bom, array $lines): array
    {
        $bom->loadMissing('items');
        $bomItems = $bom->items->keyBy('raw_material_id');

        if (count($lines) !== $bomItems->count()) {
            throw new InvalidPartProductionException('Actual consumption must contain every BOM raw material exactly once.');
        }

        $normalized = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $materialId = (int) ($line['raw_material_id'] ?? 0);
            $bomItem = $bomItems->get($materialId);

            if (! $bomItem || isset($seen[$materialId])) {
                throw new InvalidPartProductionException('Consumption line '.($index + 1).' is not a unique component of the selected BOM.');
            }

            if ((int) ($line['unit_id'] ?? 0) !== (int) $bomItem->unit_id) {
                throw new InvalidPartProductionException('Consumption line '.($index + 1).' must use the unit configured in the BOM.');
            }

            $quantity = $this->decimal($line['quantity'] ?? null, 6, false, 'Consumption quantity');
            $normalized[] = [
                'raw_material_id' => $materialId,
                'unit_id' => (int) $bomItem->unit_id,
                'quantity' => $quantity,
                'base_quantity' => $this->convertToBase($bomItem, $quantity),
                'unit_cost' => '0.0000',
                'total_cost' => '0.0000',
            ];
            $seen[$materialId] = true;
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function normalizeExpenses(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $index => $line) {
            if (blank($line['production_expense_category_id'] ?? null)
                && blank($line['amount'] ?? null)
                && blank($line['note'] ?? null)) {
                continue;
            }

            $category = ProductionExpenseCategory::query()
                ->whereKey((int) ($line['production_expense_category_id'] ?? 0))
                ->where('is_active', true)
                ->first();

            if (! $category) {
                throw new InvalidPartProductionException('Expense line '.($index + 1).' has an unavailable category.');
            }

            $normalized[] = [
                'production_expense_category_id' => $category->id,
                'account_id' => null,
                'amount' => $this->decimal($line['amount'] ?? null, 4, false, 'Expense amount'),
                'allocation_basis' => 'BATCH',
                'note' => $this->nullableText($line['note'] ?? null),
            ];
        }

        return $normalized;
    }

    private function assertCompletionResult(PartProductionBatch $batch): void
    {
        $good = (string) $batch->good_quantity;
        $reject = (string) $batch->reject_quantity;

        match ($batch->qc_status) {
            ProductionQcStatus::Passed => $this->assertQc(
                bccomp($good, '0', 6) > 0 && bccomp($reject, '0', 6) === 0,
                'Passed QC requires good quantity and zero reject quantity.',
            ),
            ProductionQcStatus::Partial => $this->assertQc(
                bccomp($good, '0', 6) > 0 && bccomp($reject, '0', 6) > 0,
                'Partial QC requires both good and reject quantities.',
            ),
            ProductionQcStatus::Failed => $this->assertQc(
                bccomp($good, '0', 6) === 0 && bccomp($reject, '0', 6) > 0,
                'Failed QC requires zero good quantity and a reject quantity.',
            ),
            ProductionQcStatus::Pending => throw new InvalidPartProductionException(
                'QC must be Passed, Partial or Failed before completing production.',
            ),
        };
    }

    private function assertQc(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidPartProductionException($message);
        }
    }

    private function sumExpenses(PartProductionBatch $batch): string
    {
        $total = '0.0000';

        foreach ($batch->expenses as $expense) {
            $total = bcadd($total, (string) $expense->amount, 4);
        }

        return $total;
    }

    private function latestRawMaterialCost(int $materialId, int $warehouseId, string $productionDate): string
    {
        $cost = RawMaterialStockMovement::query()
            ->where('raw_material_id', $materialId)
            ->where('warehouse_id', $warehouseId)
            ->where('direction', StockDirection::In)
            ->whereDate('movement_at', '<=', $productionDate)
            ->whereNotNull('unit_cost')
            ->orderByDesc('movement_at')
            ->orderByDesc('id')
            ->value('unit_cost');

        return $cost === null ? '0.0000' : $this->decimal($cost, 4, true, 'Raw material unit cost');
    }

    private function convertToBase(PartBomItem $item, string $quantity): string
    {
        $factor = bcdiv((string) $item->base_quantity, (string) $item->required_quantity, 12);

        return $this->roundDecimal(bcmul($quantity, $factor, 12), 6);
    }

    private function warehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $warehouse) {
            throw new InvalidPartProductionException('The selected warehouse is inactive or unavailable.');
        }

        return $warehouse;
    }

    private function qcStatus(mixed $value): ProductionQcStatus
    {
        return ProductionQcStatus::tryFrom(strtoupper(trim((string) $value)))
            ?? throw new InvalidPartProductionException('Select a valid QC status.');
    }

    private function assertDraft(PartProductionBatch $batch): void
    {
        if ($batch->status !== PartProductionStatus::Draft) {
            throw new InvalidPartProductionException('Only a Draft production batch can be changed or completed.');
        }
    }

    private function lock(PartProductionBatch $batch): PartProductionBatch
    {
        return PartProductionBatch::query()
            ->lockForUpdate()
            ->findOrFail($batch->id);
    }

    private function load(PartProductionBatch $batch): PartProductionBatch
    {
        return $batch->fresh([
            'bom.part.baseUnit',
            'branch',
            'inputWarehouse',
            'outputWarehouse',
            'consumptions.rawMaterial.baseUnit',
            'consumptions.unit',
            'expenses.category',
            'creator',
            'completedBy',
            'journal',
        ]);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'PPB-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (PartProductionBatch::query()->where('batch_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, bool $allowZero, string $field): string
    {
        $raw = trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidPartProductionException("{$field} must be a non-negative number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > 14 || strlen($fraction) > $scale) {
            throw new InvalidPartProductionException("{$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');

        if (! $allowZero && bccomp($normalized, '0', $scale) === 0) {
            throw new InvalidPartProductionException("{$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function roundProduct(string $left, string $right, int $scale): string
    {
        return $this->roundDecimal(bcmul($left, $right, $scale + 2), $scale);
    }

    private function roundDivision(string $left, string $right, int $scale): string
    {
        return $this->roundDecimal(bcdiv($left, $right, $scale + 2), $scale);
    }

    private function roundDecimal(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, $increment, $scale);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
