<?php

namespace App\Services\Production;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Production\ProductAssemblyStatus;
use App\Enums\Production\ProductionQcStatus;
use App\Exceptions\Production\InvalidProductAssemblyException;
use App\Models\backend\Warehouse;
use App\Models\Inventory\PartStockBalance;
use App\Models\Inventory\PartStockMovement;
use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Inventory\RawMaterialStockMovement;
use App\Models\Production\ProductAssemblyBatch;
use App\Models\Production\ProductBom;
use App\Models\Production\ProductBomItem;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Inventory\FinishedProductStockPostingService;
use App\Services\Inventory\PartStockPostingService;
use App\Services\Inventory\RawMaterialStockPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProductAssemblyService
{
    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly FinishedProductStockPostingService $finishedProductStock,
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
    ): ProductAssemblyBatch {
        return DB::transaction(function () use ($header, $consumptions, $expenses, $userId): ProductAssemblyBatch {
            $context = $this->draftContext($header);
            $batch = ProductAssemblyBatch::query()->create([
                'batch_no' => $this->nextNumber(),
                'product_bom_id' => $context['bom']->id,
                'branch_id' => $context['input_warehouse']->branch_id,
                'input_warehouse_id' => $context['input_warehouse']->id,
                'output_warehouse_id' => $context['output_warehouse']->id,
                'assembly_date' => $context['assembly_date'],
                'planned_quantity' => $context['planned_quantity'],
                'good_quantity' => $context['good_quantity'],
                'reject_quantity' => $context['reject_quantity'],
                'qc_status' => $context['qc_status'],
                'status' => ProductAssemblyStatus::Draft,
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
        ProductAssemblyBatch $batch,
        array $header,
        array $consumptions,
        array $expenses,
    ): ProductAssemblyBatch {
        return DB::transaction(function () use ($batch, $header, $consumptions, $expenses): ProductAssemblyBatch {
            $batch = $this->lock($batch);
            $this->assertDraft($batch);
            $context = $this->draftContext($header);

            $batch->update([
                'product_bom_id' => $context['bom']->id,
                'branch_id' => $context['input_warehouse']->branch_id,
                'input_warehouse_id' => $context['input_warehouse']->id,
                'output_warehouse_id' => $context['output_warehouse']->id,
                'assembly_date' => $context['assembly_date'],
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

    public function complete(ProductAssemblyBatch $batch, ?int $userId): ProductAssemblyBatch
    {
        return DB::transaction(function () use ($batch, $userId): ProductAssemblyBatch {
            $batch = $this->lock($batch);

            if ($batch->status === ProductAssemblyStatus::Completed) {
                return $this->load($batch);
            }

            $this->assertDraft($batch);
            $batch->loadMissing(['bom.finishedProduct', 'consumptions', 'expenses']);
            $this->assertCompletionResult($batch);

            if ($batch->consumptions->isEmpty()) {
                throw new InvalidProductAssemblyException('At least one Recipe component consumption is required.');
            }

            $componentCost = '0.0000';

            foreach ($batch->consumptions->sortBy('id') as $line) {
                $unitCost = $this->latestComponentCost($line, $batch);
                $totalCost = $this->roundProduct((string) $line->base_quantity, $unitCost, 4);

                $line->update(['unit_cost' => $unitCost, 'total_cost' => $totalCost]);
                $componentCost = bcadd($componentCost, $totalCost, 4);

                $posting = new StockPostingData(
                    itemId: (int) ($line->raw_material_id ?? $line->part_id),
                    branchId: (int) $batch->branch_id,
                    warehouseId: (int) $batch->input_warehouse_id,
                    movementType: StockMovementType::AssemblyConsumption,
                    direction: StockDirection::Out,
                    quantity: $line->base_quantity,
                    sourceType: 'product_assembly_batch',
                    sourceId: (int) $batch->id,
                    postingKey: "product_assembly:{$batch->id}:consumption:{$line->id}",
                    movementAt: $batch->assembly_date,
                    unitCost: $unitCost,
                    sourceItemId: (int) $line->id,
                    note: 'Consumed for '.$batch->batch_no,
                    postedBy: $userId,
                );

                if ($line->raw_material_id !== null) {
                    $this->rawMaterialStock->post($posting);
                } else {
                    $this->partStock->post($posting);
                }
            }

            $totalCost = bcadd($componentCost, $this->sumExpenses($batch), 4);

            if (bccomp((string) $batch->good_quantity, '0', 6) > 0) {
                $outputUnitCost = $this->roundDivision($totalCost, (string) $batch->good_quantity, 4);

                $this->finishedProductStock->post(new StockPostingData(
                    itemId: (int) $batch->bom->finished_product_id,
                    branchId: (int) $batch->branch_id,
                    warehouseId: (int) $batch->output_warehouse_id,
                    movementType: StockMovementType::AssemblyOutput,
                    direction: StockDirection::In,
                    quantity: $batch->good_quantity,
                    sourceType: 'product_assembly_batch',
                    sourceId: (int) $batch->id,
                    postingKey: "product_assembly:{$batch->id}:output",
                    movementAt: $batch->assembly_date,
                    unitCost: $outputUnitCost,
                    sourceItemId: (int) $batch->id,
                    note: 'Good output from '.$batch->batch_no,
                    postedBy: $userId,
                ));
            }

            $batch->update([
                'status' => ProductAssemblyStatus::Completed,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            return $this->load($batch);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function costPreview(ProductAssemblyBatch $batch): array
    {
        $batch->loadMissing(['consumptions', 'expenses']);
        $estimate = $batch->status === ProductAssemblyStatus::Draft;
        $componentCost = '0.0000';
        $lines = [];

        foreach ($batch->consumptions as $line) {
            $unitCost = $estimate ? $this->latestComponentCost($line, $batch) : (string) $line->unit_cost;
            $totalCost = $estimate
                ? $this->roundProduct((string) $line->base_quantity, $unitCost, 4)
                : (string) $line->total_cost;

            $lines[(int) $line->id] = ['unit_cost' => $unitCost, 'total_cost' => $totalCost];
            $componentCost = bcadd($componentCost, $totalCost, 4);
        }

        $expenseCost = $this->sumExpenses($batch);
        $totalCost = bcadd($componentCost, $expenseCost, 4);
        $unitCost = bccomp((string) $batch->good_quantity, '0', 6) > 0
            ? $this->roundDivision($totalCost, (string) $batch->good_quantity, 4)
            : null;

        return [
            'is_estimate' => $estimate,
            'component_cost' => $componentCost,
            'expense_cost' => $expenseCost,
            'total_cost' => $totalCost,
            'good_output_unit_cost' => $unitCost,
            'lines' => $lines,
        ];
    }

    public function deleteDraft(ProductAssemblyBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $batch = $this->lock($batch);
            $this->assertDraft($batch);
            $batch->delete();
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function plannedConsumptions(ProductBom $bom, mixed $plannedQuantity, ?int $warehouseId = null): array
    {
        $planned = $this->decimal($plannedQuantity, 6, false, 'Planned quantity');
        $bom->loadMissing([
            'items.rawMaterial.baseUnit',
            'items.part.baseUnit',
            'items.unit',
        ]);
        $scale = bcdiv($planned, (string) $bom->output_quantity, 12);

        return $bom->items->map(function (ProductBomItem $item) use ($scale, $warehouseId): array {
            $quantity = bcmul((string) $item->required_quantity, $scale, 12);
            $wastageFactor = bcadd('1', bcdiv((string) $item->wastage_percent, '100', 12), 12);
            $quantity = $this->roundDecimal(bcmul($quantity, $wastageFactor, 12), 6);
            $baseQuantity = $this->convertToBase($item, $quantity);
            $type = $item->componentType();
            $component = $item->component();

            return [
                'component_type' => $type,
                'raw_material_id' => $item->raw_material_id,
                'part_id' => $item->part_id,
                'component_name' => $component?->name,
                'component_code' => $type === 'RAW_MATERIAL'
                    ? $item->rawMaterial?->material_code
                    : $item->part?->part_code,
                'unit_id' => (int) $item->unit_id,
                'unit_name' => $item->unit?->name,
                'unit_code' => $item->unit?->code,
                'base_unit_code' => $component?->baseUnit?->code,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'available_quantity' => $warehouseId
                    ? $this->availableQuantity($item, $warehouseId)
                    : null,
                'wastage_percent' => (string) $item->wastage_percent,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function draftContext(array $header): array
    {
        $assemblyDate = trim((string) ($header['assembly_date'] ?? ''));
        $bom = ProductBom::query()
            ->with(['finishedProduct', 'items'])
            ->whereKey((int) ($header['product_bom_id'] ?? 0))
            ->where('status', 'ACTIVE')
            ->first();

        if (! $bom) {
            throw new InvalidProductAssemblyException('Select an active Finished Product Recipe.');
        }

        if (($bom->effective_from && $assemblyDate < $bom->effective_from->toDateString())
            || ($bom->effective_to && $assemblyDate > $bom->effective_to->toDateString())) {
            throw new InvalidProductAssemblyException('The assembly date is outside the selected Recipe effective period.');
        }

        $inputWarehouse = $this->warehouse((int) ($header['input_warehouse_id'] ?? 0));
        $outputWarehouse = $this->warehouse((int) ($header['output_warehouse_id'] ?? 0));

        if ((int) $inputWarehouse->branch_id !== (int) $outputWarehouse->branch_id) {
            throw new InvalidProductAssemblyException('Input and output warehouses must belong to the same branch.');
        }

        return [
            'bom' => $bom,
            'input_warehouse' => $inputWarehouse,
            'output_warehouse' => $outputWarehouse,
            'assembly_date' => $assemblyDate,
            'planned_quantity' => $this->decimal($header['planned_quantity'] ?? null, 6, false, 'Planned quantity'),
            'good_quantity' => $this->decimal($header['good_quantity'] ?? 0, 6, true, 'Good quantity'),
            'reject_quantity' => $this->decimal($header['reject_quantity'] ?? 0, 6, true, 'Reject quantity'),
            'qc_status' => $this->qcStatus($header['qc_status'] ?? ProductionQcStatus::Pending->value),
        ];
    }

    private function replaceLines(
        ProductAssemblyBatch $batch,
        ProductBom $bom,
        array $consumptions,
        array $expenses,
    ): void {
        $batch->consumptions()->delete();
        $batch->expenses()->delete();
        $batch->consumptions()->createMany($this->normalizeConsumptions($bom, $consumptions));
        $batch->expenses()->createMany($this->normalizeExpenses($expenses));
    }

    private function normalizeConsumptions(ProductBom $bom, array $lines): array
    {
        $bom->loadMissing('items');
        $bomItems = $bom->items->keyBy(fn (ProductBomItem $item): string => $this->componentKey(
            $item->raw_material_id ? 'RAW_MATERIAL' : 'PART',
            (int) ($item->raw_material_id ?? $item->part_id),
        ));

        if (count($lines) !== $bomItems->count()) {
            throw new InvalidProductAssemblyException('Actual consumption must contain every Recipe component exactly once.');
        }

        $normalized = [];
        $seen = [];

        foreach ($lines as $index => $line) {
            $type = strtoupper(trim((string) ($line['component_type'] ?? '')));
            $componentId = $type === 'RAW_MATERIAL'
                ? (int) ($line['raw_material_id'] ?? 0)
                : (int) ($line['part_id'] ?? 0);
            $key = $this->componentKey($type, $componentId);
            $bomItem = $bomItems->get($key);

            if (! $bomItem || isset($seen[$key])) {
                throw new InvalidProductAssemblyException('Consumption line '.($index + 1).' is not a unique component of the selected Recipe.');
            }

            if ((int) ($line['unit_id'] ?? 0) !== (int) $bomItem->unit_id) {
                throw new InvalidProductAssemblyException('Consumption line '.($index + 1).' must use the Recipe unit.');
            }

            $quantity = $this->decimal($line['quantity'] ?? null, 6, false, 'Consumption quantity');
            $normalized[] = [
                'raw_material_id' => $type === 'RAW_MATERIAL' ? $componentId : null,
                'part_id' => $type === 'PART' ? $componentId : null,
                'unit_id' => (int) $bomItem->unit_id,
                'quantity' => $quantity,
                'base_quantity' => $this->convertToBase($bomItem, $quantity),
                'unit_cost' => '0.0000',
                'total_cost' => '0.0000',
            ];
            $seen[$key] = true;
        }

        return $normalized;
    }

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
                throw new InvalidProductAssemblyException('Expense line '.($index + 1).' has an unavailable category.');
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

    private function assertCompletionResult(ProductAssemblyBatch $batch): void
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
            ProductionQcStatus::Pending => throw new InvalidProductAssemblyException(
                'QC must be Passed, Partial or Failed before completing assembly.',
            ),
        };
    }

    private function latestComponentCost(object $line, ProductAssemblyBatch $batch): string
    {
        $query = $line->raw_material_id !== null
            ? RawMaterialStockMovement::query()->where('raw_material_id', $line->raw_material_id)
            : PartStockMovement::query()->where('part_id', $line->part_id);

        $cost = $query
            ->where('warehouse_id', $batch->input_warehouse_id)
            ->where('direction', StockDirection::In)
            ->whereDate('movement_at', '<=', $batch->assembly_date->toDateString())
            ->whereNotNull('unit_cost')
            ->orderByDesc('movement_at')
            ->orderByDesc('id')
            ->value('unit_cost');

        return $cost === null ? '0.0000' : $this->decimal($cost, 4, true, 'Component unit cost');
    }

    private function availableQuantity(ProductBomItem $item, int $warehouseId): string
    {
        if ($item->raw_material_id !== null) {
            return (string) (RawMaterialStockBalance::query()
                ->where('raw_material_id', $item->raw_material_id)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity') ?? '0.000000');
        }

        return (string) (PartStockBalance::query()
            ->where('part_id', $item->part_id)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity') ?? '0.000000');
    }

    private function convertToBase(ProductBomItem $item, string $quantity): string
    {
        $factor = bcdiv((string) $item->base_quantity, (string) $item->required_quantity, 12);

        return $this->roundDecimal(bcmul($quantity, $factor, 12), 6);
    }

    private function sumExpenses(ProductAssemblyBatch $batch): string
    {
        $total = '0.0000';
        foreach ($batch->expenses as $expense) {
            $total = bcadd($total, (string) $expense->amount, 4);
        }

        return $total;
    }

    private function componentKey(string $type, int $id): string
    {
        if (! in_array($type, ['RAW_MATERIAL', 'PART'], true) || $id < 1) {
            throw new InvalidProductAssemblyException('Select a valid Raw Material or Part component.');
        }

        return $type.':'.$id;
    }

    private function warehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $warehouse) {
            throw new InvalidProductAssemblyException('The selected warehouse is inactive or unavailable.');
        }

        return $warehouse;
    }

    private function qcStatus(mixed $value): ProductionQcStatus
    {
        return ProductionQcStatus::tryFrom(strtoupper(trim((string) $value)))
            ?? throw new InvalidProductAssemblyException('Select a valid QC status.');
    }

    private function assertQc(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidProductAssemblyException($message);
        }
    }

    private function assertDraft(ProductAssemblyBatch $batch): void
    {
        if ($batch->status !== ProductAssemblyStatus::Draft) {
            throw new InvalidProductAssemblyException('Only a Draft assembly batch can be changed or completed.');
        }
    }

    private function lock(ProductAssemblyBatch $batch): ProductAssemblyBatch
    {
        return ProductAssemblyBatch::query()->lockForUpdate()->findOrFail($batch->id);
    }

    private function load(ProductAssemblyBatch $batch): ProductAssemblyBatch
    {
        return $batch->fresh([
            'bom.finishedProduct.baseUnit',
            'branch',
            'inputWarehouse',
            'outputWarehouse',
            'consumptions.rawMaterial.baseUnit',
            'consumptions.part.baseUnit',
            'consumptions.unit',
            'expenses.category',
            'creator',
            'completedBy',
        ]);
    }

    private function nextNumber(): string
    {
        do {
            $number = 'PAB-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (ProductAssemblyBatch::query()->where('batch_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, bool $allowZero, string $field): string
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidProductAssemblyException("{$field} must be a non-negative number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > 14 || strlen($fraction) > $scale) {
            throw new InvalidProductAssemblyException("{$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && bccomp($normalized, '0', $scale) === 0) {
            throw new InvalidProductAssemblyException("{$field} must be greater than zero.");
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
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
