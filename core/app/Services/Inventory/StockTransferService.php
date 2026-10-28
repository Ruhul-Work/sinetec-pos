<?php

namespace App\Services\Inventory;

use App\Data\Inventory\StockPostingData;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockDomain;
use App\Enums\Inventory\StockMovementType;
use App\Enums\Inventory\StockTransferStatus;
use App\Exceptions\Inventory\InvalidStockTransferException;
use App\Models\backend\Warehouse;
use App\Models\Inventory\StockTransfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockTransferService
{
    private const DOMAINS = [
        'raw_material' => [
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'unit_table' => 'raw_material_units',
            'transfer_item_table' => 'raw_material_transfer_items',
        ],
        'part' => [
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'unit_table' => 'part_units',
            'transfer_item_table' => 'part_transfer_items',
        ],
        'finished_product' => [
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'unit_table' => 'finished_product_units',
            'transfer_item_table' => 'finished_product_transfer_items',
        ],
    ];

    public function __construct(
        private readonly RawMaterialStockPostingService $rawMaterialStock,
        private readonly PartStockPostingService $partStock,
        private readonly FinishedProductStockPostingService $finishedProductStock,
    ) {}

    /**
     * @param  array{source_warehouse_id:int, destination_warehouse_id:int, transfer_at:string, note?:?string}  $header
     * @param  array<int, array{domain:string, item_id:int, unit_id:int, quantity:int|float|string}>  $lines
     */
    public function createDraft(array $header, array $lines, ?int $userId = null): StockTransfer
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            [$source, $destination] = $this->warehouses($header);
            $normalizedLines = $this->normalizeLines($lines);

            $transfer = StockTransfer::query()->create([
                'transfer_no' => $this->nextNumber(),
                'branch_id' => $source->branch_id,
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'transfer_at' => $header['transfer_at'],
                'status' => StockTransferStatus::Draft,
                'note' => $header['note'] ?? null,
                'created_by' => $userId,
            ]);

            $this->insertLines($transfer, $normalizedLines);

            return $this->load($transfer);
        }, 3);
    }

    /**
     * @param  array{source_warehouse_id:int, destination_warehouse_id:int, transfer_at:string, note?:?string}  $header
     * @param  array<int, array{domain:string, item_id:int, unit_id:int, quantity:int|float|string}>  $lines
     */
    public function updateDraft(StockTransfer $transfer, array $header, array $lines): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $header, $lines) {
            $transfer = $this->lock($transfer);
            $this->assertDraft($transfer, 'Only a draft stock transfer can be edited.');
            [$source, $destination] = $this->warehouses($header);
            $normalizedLines = $this->normalizeLines($lines);

            $transfer->update([
                'branch_id' => $source->branch_id,
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'transfer_at' => $header['transfer_at'],
                'note' => $header['note'] ?? null,
            ]);

            $this->deleteLines($transfer);
            $this->insertLines($transfer, $normalizedLines);

            return $this->load($transfer);
        }, 3);
    }

    public function post(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $transfer = $this->lock($transfer);

            if ($transfer->status === StockTransferStatus::Posted) {
                return $this->load($transfer);
            }

            $this->assertDraft($transfer, 'Only a draft stock transfer can be posted.');
            [$source, $destination] = $this->warehouses([
                'source_warehouse_id' => $transfer->source_warehouse_id,
                'destination_warehouse_id' => $transfer->destination_warehouse_id,
            ], true);
            $lines = $this->domainLines($transfer);

            if ($lines === []) {
                throw new InvalidStockTransferException('At least one stock transfer line is required.');
            }

            foreach ($lines as [$domain, $line]) {
                $config = self::DOMAINS[$domain->value];
                $itemId = (int) $line->{$config['item_column']};
                $service = $this->postingService($domain);

                $service->post(new StockPostingData(
                    itemId: $itemId,
                    branchId: (int) $source->branch_id,
                    warehouseId: (int) $source->id,
                    movementType: StockMovementType::TransferOut,
                    direction: StockDirection::Out,
                    quantity: $line->base_quantity,
                    sourceType: 'stock_transfer',
                    sourceId: (int) $transfer->id,
                    postingKey: "stock_transfer:{$transfer->id}:{$domain->value}:{$line->id}:out",
                    movementAt: $transfer->transfer_at,
                    unitCost: $line->unit_cost,
                    sourceItemId: (int) $line->id,
                    note: $transfer->note,
                    postedBy: $userId,
                ));

                $service->post(new StockPostingData(
                    itemId: $itemId,
                    branchId: (int) $destination->branch_id,
                    warehouseId: (int) $destination->id,
                    movementType: StockMovementType::TransferIn,
                    direction: StockDirection::In,
                    quantity: $line->base_quantity,
                    sourceType: 'stock_transfer',
                    sourceId: (int) $transfer->id,
                    postingKey: "stock_transfer:{$transfer->id}:{$domain->value}:{$line->id}:in",
                    movementAt: $transfer->transfer_at,
                    unitCost: $line->unit_cost,
                    sourceItemId: (int) $line->id,
                    note: $transfer->note,
                    postedBy: $userId,
                ));
            }

            $transfer->update([
                'status' => StockTransferStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->load($transfer);
        }, 3);
    }

    public function deleteDraft(StockTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $transfer = $this->lock($transfer);
            $this->assertDraft($transfer, 'Only a draft stock transfer can be deleted.');
            $transfer->delete();
        }, 3);
    }

    /** @return array<int, array{0:StockDomain, 1:Model}> */
    private function domainLines(StockTransfer $transfer): array
    {
        $transfer->loadMissing(['rawMaterialItems', 'partItems', 'finishedProductItems']);
        $lines = [];

        foreach ($transfer->rawMaterialItems->sortBy('id') as $line) {
            $lines[] = [StockDomain::RawMaterial, $line];
        }
        foreach ($transfer->partItems->sortBy('id') as $line) {
            $lines[] = [StockDomain::Part, $line];
        }
        foreach ($transfer->finishedProductItems->sortBy('id') as $line) {
            $lines[] = [StockDomain::FinishedProduct, $line];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidStockTransferException('At least one stock transfer line is required.');
        }

        $seen = [];
        $normalized = [];

        foreach ($lines as $index => $line) {
            $domain = StockDomain::tryFrom((string) ($line['domain'] ?? ''));
            if (! $domain) {
                throw new InvalidStockTransferException('Line '.($index + 1).' has an invalid inventory type.');
            }

            $config = self::DOMAINS[$domain->value];
            $itemId = (int) ($line['item_id'] ?? 0);
            $unitId = (int) ($line['unit_id'] ?? 0);
            $key = $domain->value.':'.$itemId;

            if (isset($seen[$key])) {
                throw new InvalidStockTransferException('The same inventory item cannot be entered more than once in one transfer.');
            }
            $seen[$key] = true;

            $item = DB::table($config['item_table'])
                ->where('id', $itemId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first(['id', 'base_unit_id']);

            if (! $item) {
                throw new InvalidStockTransferException('Line '.($index + 1).' contains an unavailable inventory item.');
            }

            $unitExists = DB::table('units')
                ->where('id', $unitId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->exists();

            if (! $unitExists) {
                throw new InvalidStockTransferException('Line '.($index + 1).' contains an unavailable unit.');
            }

            $factor = '1.000000';
            if ((int) $item->base_unit_id !== $unitId) {
                $factor = DB::table($config['unit_table'])
                    ->where($config['item_column'], $itemId)
                    ->where('unit_id', $unitId)
                    ->value('conversion_to_base');

                if ($factor === null) {
                    throw new InvalidStockTransferException('Line '.($index + 1).' uses a unit that is not mapped to the selected item.');
                }
            }

            $quantity = $this->decimal($line['quantity'] ?? '', 6, 14, false, 'quantity');
            $baseQuantity = $this->decimal(
                $this->multiplyDecimal($quantity, (string) $factor, 6),
                6,
                14,
                false,
                'base quantity',
            );

            $normalized[] = [
                'domain' => $domain->value,
                'item_id' => $itemId,
                'unit_id' => $unitId,
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_cost' => null,
            ];
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function insertLines(StockTransfer $transfer, array $lines): void
    {
        $timestamp = now();

        foreach ($lines as $line) {
            $config = self::DOMAINS[$line['domain']];
            DB::table($config['transfer_item_table'])->insert([
                'stock_transfer_id' => $transfer->id,
                $config['item_column'] => $line['item_id'],
                'unit_id' => $line['unit_id'],
                'quantity' => $line['quantity'],
                'base_quantity' => $line['base_quantity'],
                'unit_cost' => $line['unit_cost'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function deleteLines(StockTransfer $transfer): void
    {
        foreach (self::DOMAINS as $config) {
            DB::table($config['transfer_item_table'])
                ->where('stock_transfer_id', $transfer->id)
                ->delete();
        }
    }

    /**
     * @param  array{source_warehouse_id:int, destination_warehouse_id:int}  $header
     * @return array{Warehouse, Warehouse}
     */
    private function warehouses(array $header, bool $lock = false): array
    {
        $sourceId = (int) $header['source_warehouse_id'];
        $destinationId = (int) $header['destination_warehouse_id'];

        if ($sourceId === $destinationId) {
            throw new InvalidStockTransferException('Source and destination warehouses must be different.');
        }

        $query = Warehouse::query()
            ->whereIn('id', [$sourceId, $destinationId])
            ->where('is_active', true)
            ->whereHas('branch', fn ($branch) => $branch->where('is_active', true))
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $warehouses = $query->get()->keyBy('id');
        $source = $warehouses->get($sourceId);
        $destination = $warehouses->get($destinationId);

        if (! $source || ! $destination) {
            throw new InvalidStockTransferException('The selected source or destination warehouse is unavailable.');
        }

        return [$source, $destination];
    }

    private function assertDraft(StockTransfer $transfer, string $message): void
    {
        if ($transfer->status !== StockTransferStatus::Draft) {
            throw new InvalidStockTransferException($message);
        }
    }

    private function lock(StockTransfer $transfer): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->first();

        if (! $locked) {
            throw new InvalidStockTransferException('The stock transfer does not exist.');
        }

        return $locked;
    }

    private function load(StockTransfer $transfer): StockTransfer
    {
        return $transfer->fresh([
            'branch', 'sourceWarehouse.branch', 'destinationWarehouse.branch', 'createdBy', 'postedBy',
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
            $number = 'TRF-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (StockTransfer::query()->where('transfer_no', $number)->exists());

        return $number;
    }

    private function decimal(mixed $value, int $scale, int $maxIntegerDigits, bool $allowZero, string $field): string
    {
        $raw = is_float($value)
            ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.')
            : trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidStockTransferException("The {$field} must be a non-negative decimal number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidStockTransferException("The {$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');
        if (! $allowZero && $normalized === '0.'.str_repeat('0', $scale)) {
            throw new InvalidStockTransferException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    private function multiplyDecimal(string $left, string $right, int $scale): string
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $product = $this->multiplyIntegers(
            ltrim($leftInteger.$leftFraction, '0') ?: '0',
            ltrim($rightInteger.$rightFraction, '0') ?: '0',
        );
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
