<?php

namespace App\Services\Inventory;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockAdjustmentStatus;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockDomain;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\Inventory\InvalidOpeningStockException;
use App\Models\backend\Warehouse;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OpeningStockService
{
    private const DOMAINS = [
        'raw_material' => [
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'unit_table' => 'raw_material_units',
            'item_table_adjustment' => 'raw_material_adjustment_items',
            'movement_table' => 'raw_material_stock_movements',
        ],
        'part' => [
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'unit_table' => 'part_units',
            'item_table_adjustment' => 'part_adjustment_items',
            'movement_table' => 'part_stock_movements',
        ],
        'finished_product' => [
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'unit_table' => 'finished_product_units',
            'item_table_adjustment' => 'finished_product_adjustment_items',
            'movement_table' => 'finished_product_stock_movements',
        ],
    ];

    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly FinishedProductStockPostingService $finishedProductStock,
    ) {}

    /**
     * @param  array{warehouse_id:int, adjustment_at:string, reason?:?string, note?:?string}  $header
     * @param  array<int, array{domain:string, item_id:int, unit_id:int, quantity:int|float|string, unit_cost?:int|float|string|null, note?:?string}>  $lines
     */
    public function createDraft(array $header, array $lines, ?int $userId = null): StockAdjustment
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $warehouse = $this->warehouse((int) $header['warehouse_id']);
            $normalizedLines = $this->normalizeLines($lines);

            $adjustment = StockAdjustment::query()->create([
                'adjustment_no' => $this->nextNumber(),
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'adjustment_at' => $header['adjustment_at'],
                'adjustment_type' => 'OPENING',
                'status' => StockAdjustmentStatus::Draft,
                'reason' => $header['reason'] ?? null,
                'note' => $header['note'] ?? null,
                'created_by' => $userId,
            ]);

            $this->insertLines($adjustment, $normalizedLines);

            return $this->load($adjustment);
        }, 3);
    }

    /**
     * @param  array{warehouse_id:int, adjustment_at:string, reason?:?string, note?:?string}  $header
     * @param  array<int, array{domain:string, item_id:int, unit_id:int, quantity:int|float|string, unit_cost?:int|float|string|null, note?:?string}>  $lines
     */
    public function updateDraft(StockAdjustment $adjustment, array $header, array $lines): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $header, $lines) {
            $adjustment = $this->lock($adjustment);
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'Only a draft opening stock document can be edited.');

            $warehouse = $this->warehouse((int) $header['warehouse_id']);
            $normalizedLines = $this->normalizeLines($lines);

            $adjustment->update([
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'adjustment_at' => $header['adjustment_at'],
                'reason' => $header['reason'] ?? null,
                'note' => $header['note'] ?? null,
            ]);

            $this->deleteLines($adjustment);
            $this->insertLines($adjustment, $normalizedLines);

            return $this->load($adjustment);
        }, 3);
    }

    public function approve(StockAdjustment $adjustment, ?int $userId = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $userId) {
            $adjustment = $this->lock($adjustment);

            if ($adjustment->status === StockAdjustmentStatus::Approved) {
                return $this->load($adjustment);
            }

            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'Only a draft opening stock document can be approved.');
            $this->assertHasLines($adjustment);
            $this->assertOpeningIsFirstMovement($adjustment);

            $adjustment->update([
                'status' => StockAdjustmentStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $this->load($adjustment);
        }, 3);
    }

    public function post(StockAdjustment $adjustment, ?int $userId = null): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $userId) {
            $adjustment = $this->lock($adjustment);

            if ($adjustment->status === StockAdjustmentStatus::Posted) {
                return $this->load($adjustment);
            }

            $this->assertStatus($adjustment, StockAdjustmentStatus::Approved, 'Opening stock must be approved before posting.');
            $this->assertHasLines($adjustment);
            $this->assertOpeningIsFirstMovement($adjustment);

            foreach ($this->domainLines($adjustment) as [$domain, $line]) {
                $config = self::DOMAINS[$domain->value];
                $this->postingService($domain)->post(new StockPostingData(
                    itemId: (int) $line->{$config['item_column']},
                    branchId: (int) $adjustment->branch_id,
                    warehouseId: (int) $adjustment->warehouse_id,
                    movementType: StockMovementType::Opening,
                    direction: StockDirection::In,
                    quantity: $line->base_quantity,
                    sourceType: 'stock_adjustment',
                    sourceId: (int) $adjustment->id,
                    postingKey: "stock_adjustment:{$adjustment->id}:{$domain->value}:{$line->id}:opening:in",
                    movementAt: $adjustment->adjustment_at,
                    unitCost: $line->unit_cost,
                    sourceItemId: (int) $line->id,
                    note: $line->note ?: $adjustment->note,
                    postedBy: $userId,
                ));
            }

            $adjustment->update([
                'status' => StockAdjustmentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->load($adjustment);
        }, 3);
    }

    public function deleteDraft(StockAdjustment $adjustment): void
    {
        DB::transaction(function () use ($adjustment) {
            $adjustment = $this->lock($adjustment);
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'Only a draft opening stock document can be deleted.');
            $adjustment->delete();
        }, 3);
    }

    /** @return array<int, array{0:StockDomain, 1:Model}> */
    private function domainLines(StockAdjustment $adjustment): array
    {
        $adjustment->loadMissing(['rawMaterialItems', 'partItems', 'finishedProductItems']);
        $lines = [];

        foreach ($adjustment->rawMaterialItems->sortBy('id') as $line) {
            $lines[] = [StockDomain::RawMaterial, $line];
        }
        foreach ($adjustment->partItems->sortBy('id') as $line) {
            $lines[] = [StockDomain::Part, $line];
        }
        foreach ($adjustment->finishedProductItems->sortBy('id') as $line) {
            $lines[] = [StockDomain::FinishedProduct, $line];
        }

        return $lines;
    }

    private function assertOpeningIsFirstMovement(StockAdjustment $adjustment): void
    {
        foreach ($this->domainLines($adjustment) as [$domain, $line]) {
            $config = self::DOMAINS[$domain->value];

            // Serialize competing opening documents for the same item. The
            // movement lookup is also a locking/current read, so a transaction
            // waiting here sees a movement committed by the winner.
            $domain->itemModel()::query()
                ->whereKey($line->{$config['item_column']})
                ->lockForUpdate()
                ->firstOrFail();

            $exists = DB::table($config['movement_table'])
                ->where($config['item_column'], $line->{$config['item_column']})
                ->where('warehouse_id', $adjustment->warehouse_id)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new InvalidOpeningStockException(
                    'Opening stock is allowed only before the first stock movement for each item and warehouse.'
                );
            }
        }
    }

    private function assertHasLines(StockAdjustment $adjustment): void
    {
        if ($this->domainLines($adjustment) === []) {
            throw new InvalidOpeningStockException('At least one opening stock line is required.');
        }
    }

    private function assertStatus(StockAdjustment $adjustment, StockAdjustmentStatus $status, string $message): void
    {
        if ($adjustment->status !== $status) {
            throw new InvalidOpeningStockException($message);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidOpeningStockException('At least one opening stock line is required.');
        }

        $seen = [];
        $normalized = [];

        foreach ($lines as $index => $line) {
            $domain = StockDomain::tryFrom((string) ($line['domain'] ?? ''));
            if (! $domain) {
                throw new InvalidOpeningStockException('Line '.($index + 1).' has an invalid inventory type.');
            }

            $config = self::DOMAINS[$domain->value];
            $itemId = (int) ($line['item_id'] ?? 0);
            $unitId = (int) ($line['unit_id'] ?? 0);
            $key = $domain->value.':'.$itemId;

            if (isset($seen[$key])) {
                throw new InvalidOpeningStockException('The same inventory item cannot be entered more than once in one opening document.');
            }
            $seen[$key] = true;

            $item = DB::table($config['item_table'])
                ->where('id', $itemId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first(['id', 'base_unit_id']);

            if (! $item) {
                throw new InvalidOpeningStockException('Line '.($index + 1).' contains an unavailable inventory item.');
            }

            $unitExists = DB::table('units')
                ->where('id', $unitId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->exists();

            if (! $unitExists) {
                throw new InvalidOpeningStockException('Line '.($index + 1).' contains an unavailable unit.');
            }

            $factor = '1.000000';
            if ((int) $item->base_unit_id !== $unitId) {
                $factor = DB::table($config['unit_table'])
                    ->where($config['item_column'], $itemId)
                    ->where('unit_id', $unitId)
                    ->value('conversion_to_base');

                if ($factor === null) {
                    throw new InvalidOpeningStockException('Line '.($index + 1).' uses a unit that is not mapped to the selected item.');
                }
            }

            $quantity = $this->decimal($line['quantity'] ?? '', 6, 14, false, 'quantity');
            $baseQuantity = $this->multiplyDecimal($quantity, (string) $factor, 6);
            $baseQuantity = $this->decimal($baseQuantity, 6, 14, false, 'base quantity');
            $unitCost = ($line['unit_cost'] ?? null) === null || ($line['unit_cost'] ?? '') === ''
                ? null
                : $this->decimal($line['unit_cost'], 4, 16, true, 'base-unit cost');

            $normalized[] = [
                'domain' => $domain->value,
                'item_id' => $itemId,
                'unit_id' => $unitId,
                'direction' => StockDirection::In->value,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_cost' => $unitCost,
                'note' => filled($line['note'] ?? null) ? trim((string) $line['note']) : null,
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function insertLines(StockAdjustment $adjustment, array $lines): void
    {
        $timestamp = now();

        foreach ($lines as $line) {
            $config = self::DOMAINS[$line['domain']];
            DB::table($config['item_table_adjustment'])->insert([
                'stock_adjustment_id' => $adjustment->id,
                $config['item_column'] => $line['item_id'],
                'unit_id' => $line['unit_id'],
                'direction' => $line['direction'],
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'unit_cost' => $line['unit_cost'],
                'note' => $line['note'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function deleteLines(StockAdjustment $adjustment): void
    {
        foreach (self::DOMAINS as $config) {
            DB::table($config['item_table_adjustment'])
                ->where('stock_adjustment_id', $adjustment->id)
                ->delete();
        }
    }

    private function warehouse(int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $warehouse) {
            throw new InvalidOpeningStockException('The selected warehouse is unavailable.');
        }

        return $warehouse;
    }

    private function lock(StockAdjustment $adjustment): StockAdjustment
    {
        $locked = StockAdjustment::query()
            ->whereKey($adjustment->getKey())
            ->where('adjustment_type', 'OPENING')
            ->lockForUpdate()
            ->first();

        if (! $locked) {
            throw new InvalidOpeningStockException('The opening stock document does not exist.');
        }

        return $locked;
    }

    private function load(StockAdjustment $adjustment): StockAdjustment
    {
        return $adjustment->fresh([
            'branch', 'warehouse', 'createdBy', 'approvedBy', 'postedBy',
            'rawMaterialItems.rawMaterial.baseUnit', 'rawMaterialItems.unit',
            'partItems.part.baseUnit', 'partItems.unit',
            'finishedProductItems.finishedProduct.baseUnit', 'finishedProductItems.unit',
        ]);
    }

    private function postingService(StockDomain $domain): StockPostingService
    {
        return match ($domain) {
            StockDomain::RawMaterial => $this->rawMaterialStock,
            StockDomain::Part => $this->partStock,
            StockDomain::FinishedProduct => $this->finishedProductStock,
        };
    }

    private function nextNumber(): string
    {
        do {
            $number = 'OPEN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (StockAdjustment::query()->where('adjustment_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, int $maxIntegerDigits, bool $allowZero, string $field): string
    {
        $raw = is_float($value)
            ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.')
            : trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidOpeningStockException("The {$field} must be a non-negative decimal number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidOpeningStockException("The {$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && $normalized === '0.'.str_repeat('0', $scale)) {
            throw new InvalidOpeningStockException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function multiplyDecimal(string $left, string $right, int $scale): string
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftDigits = ltrim($leftInteger.$leftFraction, '0') ?: '0';
        $rightDigits = ltrim($rightInteger.$rightFraction, '0') ?: '0';
        $product = $this->multiplyIntegers($leftDigits, $rightDigits);
        $sourceScale = strlen($leftFraction) + strlen($rightFraction);

        if ($sourceScale > $scale) {
            $cut = $sourceScale - $scale;
            $roundDigit = strlen($product) > $cut ? (int) $product[strlen($product) - $cut] : 0;
            $product = strlen($product) > $cut ? substr($product, 0, -$cut) : '0';
            if ($roundDigit >= 5) {
                $product = $this->addOne($product);
            }
            $sourceScale = $scale;
        }

        $product = str_pad($product, $sourceScale + 1, '0', STR_PAD_LEFT);
        $integer = $sourceScale === 0 ? $product : substr($product, 0, -$sourceScale);
        $fraction = $sourceScale === 0 ? '' : substr($product, -$sourceScale);

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, $scale, '0');
    }

    private function multiplyIntegers(string $left, string $right): string
    {
        $result = array_fill(0, strlen($left) + strlen($right), 0);

        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $sum = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
                $result[$position] = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private function addOne(string $number): string
    {
        $digits = str_split($number);
        $carry = 1;

        for ($i = count($digits) - 1; $i >= 0 && $carry; $i--) {
            $sum = (int) $digits[$i] + $carry;
            $digits[$i] = (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        return ($carry ? '1' : '').implode('', $digits);
    }
}
