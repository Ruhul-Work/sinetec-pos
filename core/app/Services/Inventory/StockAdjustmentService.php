<?php

namespace App\Services\Inventory;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockAdjustmentStatus;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockDomain;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\Inventory\InvalidStockAdjustmentException;
use App\Models\backend\Warehouse;
use App\Models\Inventory\StockAdjustment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockAdjustmentService
{
    public const TYPES = ['GAIN', 'LOSS', 'DAMAGE', 'WASTAGE', 'CORRECTION'];

    private const DOMAINS = [
        'raw_material' => [
            'item_table' => 'raw_materials', 'item_column' => 'raw_material_id',
            'unit_table' => 'raw_material_units', 'adjustment_item_table' => 'raw_material_adjustment_items',
        ],
        'part' => [
            'item_table' => 'parts', 'item_column' => 'part_id',
            'unit_table' => 'part_units', 'adjustment_item_table' => 'part_adjustment_items',
        ],
        'finished_product' => [
            'item_table' => 'finished_products', 'item_column' => 'finished_product_id',
            'unit_table' => 'finished_product_units', 'adjustment_item_table' => 'finished_product_adjustment_items',
        ],
    ];

    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly FinishedProductStockPostingService $finishedProductStock,
    ) {}

    /**
     * @param  array{warehouse_id:int, adjustment_at:string, adjustment_type:string, reason:string, note?:?string}  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(array $header, array $lines, ?int $userId = null): StockAdjustment
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $warehouse = $this->warehouse((int) $header['warehouse_id']);
            $type = $this->type((string) $header['adjustment_type']);
            $normalizedLines = $this->normalizeLines($lines, $type);

            $adjustment = StockAdjustment::query()->create([
                'adjustment_no' => $this->nextNumber(),
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'adjustment_at' => $header['adjustment_at'],
                'adjustment_type' => $type,
                'status' => StockAdjustmentStatus::Draft,
                'reason' => trim((string) $header['reason']),
                'note' => $header['note'] ?? null,
                'created_by' => $userId,
            ]);

            $this->insertLines($adjustment, $normalizedLines);

            return $this->load($adjustment);
        }, 3);
    }

    /**
     * @param  array{warehouse_id:int, adjustment_at:string, adjustment_type:string, reason:string, note?:?string}  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(StockAdjustment $adjustment, array $header, array $lines): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $header, $lines) {
            $adjustment = $this->lock($adjustment);
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'Only a draft stock adjustment can be edited.');

            $warehouse = $this->warehouse((int) $header['warehouse_id']);
            $type = $this->type((string) $header['adjustment_type']);
            $normalizedLines = $this->normalizeLines($lines, $type);

            $adjustment->update([
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'adjustment_at' => $header['adjustment_at'],
                'adjustment_type' => $type,
                'reason' => trim((string) $header['reason']),
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

            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'Only a draft stock adjustment can be approved.');
            $this->assertHasLines($adjustment);

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

            $this->assertStatus($adjustment, StockAdjustmentStatus::Approved, 'Stock adjustment must be approved before posting.');
            $lines = $this->domainLines($adjustment);
            if ($lines === []) {
                throw new InvalidStockAdjustmentException('At least one stock adjustment line is required.');
            }

            $warehouse = $this->warehouse((int) $adjustment->warehouse_id, true);
            $movementType = $this->movementType((string) $adjustment->adjustment_type);

            foreach ($lines as [$domain, $line]) {
                $config = self::DOMAINS[$domain->value];
                $direction = $line->direction instanceof StockDirection
                    ? $line->direction
                    : StockDirection::from((string) $line->direction);

                $this->postingService($domain)->post(new StockPostingData(
                    itemId: (int) $line->{$config['item_column']},
                    branchId: (int) $warehouse->branch_id,
                    warehouseId: (int) $warehouse->id,
                    movementType: $movementType,
                    direction: $direction,
                    quantity: $line->base_quantity,
                    sourceType: 'stock_adjustment',
                    sourceId: (int) $adjustment->id,
                    postingKey: "stock_adjustment:{$adjustment->id}:{$domain->value}:{$line->id}:".strtolower($direction->value),
                    movementAt: $adjustment->adjustment_at,
                    unitCost: $line->unit_cost,
                    sourceItemId: (int) $line->id,
                    note: $line->note ?: $adjustment->reason,
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
            $this->assertStatus($adjustment, StockAdjustmentStatus::Draft, 'Only a draft stock adjustment can be deleted.');
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

    /** @param array<int, array<string, mixed>> $lines */
    private function normalizeLines(array $lines, string $type): array
    {
        if ($lines === []) {
            throw new InvalidStockAdjustmentException('At least one stock adjustment line is required.');
        }

        $seen = [];
        $normalized = [];

        foreach ($lines as $index => $line) {
            $domain = StockDomain::tryFrom((string) ($line['domain'] ?? ''));
            if (! $domain) {
                throw new InvalidStockAdjustmentException('Line '.($index + 1).' has an invalid inventory type.');
            }

            $config = self::DOMAINS[$domain->value];
            $itemId = (int) ($line['item_id'] ?? 0);
            $unitId = (int) ($line['unit_id'] ?? 0);
            $key = $domain->value.':'.$itemId;

            if (isset($seen[$key])) {
                throw new InvalidStockAdjustmentException('The same inventory item cannot be entered more than once in one adjustment.');
            }
            $seen[$key] = true;

            $item = DB::table($config['item_table'])
                ->where('id', $itemId)->whereNull('deleted_at')->where('is_active', true)
                ->first(['id', 'base_unit_id']);
            if (! $item) {
                throw new InvalidStockAdjustmentException('Line '.($index + 1).' contains an unavailable inventory item.');
            }

            $unitExists = DB::table('units')->where('id', $unitId)
                ->whereNull('deleted_at')->where('is_active', true)->exists();
            if (! $unitExists) {
                throw new InvalidStockAdjustmentException('Line '.($index + 1).' contains an unavailable unit.');
            }

            $factor = '1.000000';
            if ((int) $item->base_unit_id !== $unitId) {
                $factor = DB::table($config['unit_table'])
                    ->where($config['item_column'], $itemId)->where('unit_id', $unitId)
                    ->value('conversion_to_base');
                if ($factor === null) {
                    throw new InvalidStockAdjustmentException('Line '.($index + 1).' uses a unit that is not mapped to the selected item.');
                }
            }

            $direction = $this->direction($type, $line['direction'] ?? null, $index);
            $quantity = $this->decimal($line['quantity'] ?? '', 6, 14, false, 'quantity');
            $baseQuantity = $this->decimal(
                $this->multiplyDecimal($quantity, (string) $factor, 6), 6, 14, false, 'base quantity'
            );
            $unitCost = ($line['unit_cost'] ?? null) === null || ($line['unit_cost'] ?? '') === ''
                ? null
                : $this->decimal($line['unit_cost'], 4, 16, true, 'base-unit cost');

            $normalized[] = [
                'domain' => $domain->value,
                'item_id' => $itemId,
                'unit_id' => $unitId,
                'direction' => $direction->value,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_cost' => $unitCost,
                'note' => filled($line['note'] ?? null) ? trim((string) $line['note']) : null,
            ];
        }

        return $normalized;
    }

    private function direction(string $type, mixed $requested, int $index): StockDirection
    {
        return match ($type) {
            'GAIN' => StockDirection::In,
            'LOSS', 'DAMAGE', 'WASTAGE' => StockDirection::Out,
            'CORRECTION' => StockDirection::tryFrom((string) $requested)
                ?? throw new InvalidStockAdjustmentException('Line '.($index + 1).' requires a valid IN or OUT direction.'),
        };
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function insertLines(StockAdjustment $adjustment, array $lines): void
    {
        $timestamp = now();
        foreach ($lines as $line) {
            $config = self::DOMAINS[$line['domain']];
            DB::table($config['adjustment_item_table'])->insert([
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
            DB::table($config['adjustment_item_table'])->where('stock_adjustment_id', $adjustment->id)->delete();
        }
    }

    private function type(string $type): string
    {
        $type = strtoupper(trim($type));
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidStockAdjustmentException('The selected stock adjustment type is invalid.');
        }

        return $type;
    }

    private function movementType(string $type): StockMovementType
    {
        return match ($type) {
            'DAMAGE' => StockMovementType::Damage,
            'WASTAGE' => StockMovementType::Wastage,
            default => StockMovementType::Adjustment,
        };
    }

    private function warehouse(int $warehouseId, bool $lock = false): Warehouse
    {
        $query = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)
            ->whereHas('branch', fn ($branch) => $branch->where('is_active', true));
        if ($lock) {
            $query->lockForUpdate();
        }
        $warehouse = $query->first();
        if (! $warehouse) {
            throw new InvalidStockAdjustmentException('The selected warehouse is unavailable.');
        }

        return $warehouse;
    }

    private function assertHasLines(StockAdjustment $adjustment): void
    {
        if ($this->domainLines($adjustment) === []) {
            throw new InvalidStockAdjustmentException('At least one stock adjustment line is required.');
        }
    }

    private function assertStatus(StockAdjustment $adjustment, StockAdjustmentStatus $status, string $message): void
    {
        if ($adjustment->status !== $status) {
            throw new InvalidStockAdjustmentException($message);
        }
    }

    private function lock(StockAdjustment $adjustment): StockAdjustment
    {
        $locked = StockAdjustment::query()->whereKey($adjustment->getKey())
            ->whereIn('adjustment_type', self::TYPES)->lockForUpdate()->first();
        if (! $locked) {
            throw new InvalidStockAdjustmentException('The stock adjustment does not exist.');
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
            $number = 'ADJ-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (StockAdjustment::query()->where('adjustment_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, int $maxIntegerDigits, bool $allowZero, string $field): string
    {
        $raw = is_float($value) ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.') : trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidStockAdjustmentException("The {$field} must be a non-negative decimal number.");
        }
        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidStockAdjustmentException("The {$field} exceeds the supported precision.");
        }
        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && $normalized === '0.'.str_repeat('0', $scale)) {
            throw new InvalidStockAdjustmentException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function multiplyDecimal(string $left, string $right, int $scale): string
    {
        [$li, $lf] = array_pad(explode('.', $left, 2), 2, '');
        [$ri, $rf] = array_pad(explode('.', $right, 2), 2, '');
        $product = $this->multiplyIntegers(ltrim($li.$lf, '0') ?: '0', ltrim($ri.$rf, '0') ?: '0');
        $sourceScale = strlen($lf) + strlen($rf);
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
