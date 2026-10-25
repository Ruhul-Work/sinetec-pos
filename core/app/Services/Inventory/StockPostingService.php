<?php

namespace App\Services\Inventory;

use App\Data\Inventory\StockPostingData;
use App\Data\Inventory\StockPostingResult;
use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockDomain;
use App\Enums\Inventory\StockMovementType;
use App\Exceptions\Inventory\DuplicateStockPostingException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InvalidStockPostingException;
use App\Exceptions\Inventory\StockMovementAlreadyReversedException;
use App\Models\backend\Warehouse;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class StockPostingService
{
    abstract protected function domain(): StockDomain;

    public function post(StockPostingData $posting): StockPostingResult
    {
        $normalized = $this->normalize($posting);

        return DB::transaction(
            fn () => $this->postWithinTransaction($normalized),
            3,
        );
    }

    public function reverse(
        int $movementId,
        string $postingKey,
        ?int $postedBy = null,
        ?string $note = null,
    ): StockPostingResult {
        return DB::transaction(function () use ($movementId, $postingKey, $postedBy, $note) {
            $movementClass = $this->domain()->movementModel();

            /** @var StockMovement|null $original */
            $original = $movementClass::query()->lockForUpdate()->find($movementId);

            if (! $original) {
                throw new InvalidStockPostingException('The stock movement to reverse does not exist.');
            }

            if ($original->reversal_of_id !== null) {
                throw new InvalidStockPostingException('A reversal movement cannot be reversed directly.');
            }

            /** @var StockMovement|null $existingReversal */
            $existingReversal = $movementClass::query()
                ->where('reversal_of_id', $original->getKey())
                ->lockForUpdate()
                ->first();

            if ($existingReversal) {
                if ($existingReversal->posting_key !== $postingKey) {
                    throw new StockMovementAlreadyReversedException('The stock movement has already been reversed.');
                }

                return new StockPostingResult(
                    $existingReversal,
                    $this->lockedBalance($existingReversal->{$this->domain()->itemForeignKey()}, $existingReversal->warehouse_id),
                    true,
                );
            }

            return $this->postWithinTransaction($this->normalize(new StockPostingData(
                itemId: (int) $original->{$this->domain()->itemForeignKey()},
                branchId: (int) $original->branch_id,
                warehouseId: (int) $original->warehouse_id,
                movementType: StockMovementType::Reversal,
                direction: $original->direction->opposite(),
                quantity: $original->quantity,
                sourceType: 'stock_movement_reversal',
                sourceId: (int) $original->getKey(),
                postingKey: $postingKey,
                unitCost: $original->unit_cost,
                sourceItemId: (int) $original->getKey(),
                reversalOfId: (int) $original->getKey(),
                note: $note,
                postedBy: $postedBy,
            )));
        }, 3);
    }

    private function postWithinTransaction(array $posting): StockPostingResult
    {
        $domain = $this->domain();
        $itemClass = $domain->itemModel();

        /** @var Model|null $item */
        $item = $itemClass::query()->lockForUpdate()->find($posting['item_id']);
        if (! $item) {
            throw new InvalidStockPostingException('The inventory item does not exist.');
        }

        $warehouse = Warehouse::query()->lockForUpdate()->find($posting['warehouse_id']);
        if (! $warehouse) {
            throw new InvalidStockPostingException('The warehouse does not exist.');
        }

        if ((int) $warehouse->branch_id !== $posting['branch_id']) {
            throw new InvalidStockPostingException('The warehouse does not belong to the selected branch.');
        }

        $balance = $this->lockedBalance($posting['item_id'], $posting['warehouse_id']);
        $movementClass = $domain->movementModel();

        /** @var StockMovement|null $existing */
        $existing = $movementClass::query()
            ->where('posting_key', $posting['posting_key'])
            ->first();

        if ($existing) {
            $this->assertSamePosting($existing, $posting);

            return new StockPostingResult($existing, $balance, true);
        }

        $balanceQuery = $balance->newQuery()->whereKey($balance->getKey());
        $balanceChanges = [
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ];

        if ($posting['direction'] === StockDirection::Out) {
            $affected = $balanceQuery
                ->where('quantity', '>=', $posting['quantity'])
                ->decrement('quantity', $posting['quantity'], $balanceChanges);

            if ($affected !== 1) {
                throw new InsufficientStockException(sprintf(
                    'Insufficient %s stock: available %s, requested %s.',
                    str_replace('_', ' ', $domain->value),
                    $balance->quantity,
                    $posting['quantity'],
                ));
            }
        } else {
            $balanceQuery->increment('quantity', $posting['quantity'], $balanceChanges);
        }

        /** @var StockMovement $movement */
        $movement = $movementClass::query()->create([
            $domain->itemForeignKey() => $posting['item_id'],
            'branch_id' => $posting['branch_id'],
            'warehouse_id' => $posting['warehouse_id'],
            'movement_at' => $posting['movement_at'],
            'movement_type' => $posting['movement_type'],
            'direction' => $posting['direction'],
            'quantity' => $posting['quantity'],
            'unit_cost' => $posting['unit_cost'],
            'source_type' => $posting['source_type'],
            'source_id' => $posting['source_id'],
            'source_item_id' => $posting['source_item_id'],
            'posting_key' => $posting['posting_key'],
            'reversal_of_id' => $posting['reversal_of_id'],
            'note' => $posting['note'],
            'posted_by' => $posting['posted_by'],
        ]);

        /** @var StockBalance $freshBalance */
        $freshBalance = $balance->fresh();

        return new StockPostingResult($movement, $freshBalance);
    }

    private function lockedBalance(int $itemId, int $warehouseId): StockBalance
    {
        $domain = $this->domain();
        $balanceClass = $domain->balanceModel();
        $itemForeignKey = $domain->itemForeignKey();
        $balanceTable = (new $balanceClass)->getTable();
        $timestamp = now();

        DB::table($balanceTable)->insertOrIgnore([
            $itemForeignKey => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => 0,
            'version' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        /** @var StockBalance $balance */
        $balance = $balanceClass::query()
            ->where($itemForeignKey, $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();

        return $balance;
    }

    /** @return array<string, mixed> */
    private function normalize(StockPostingData $posting): array
    {
        $domain = $this->domain();

        if (! $domain->allowsMovement($posting->movementType)) {
            throw new InvalidStockPostingException(sprintf(
                'Movement type %s is not allowed for %s stock.',
                $posting->movementType->value,
                str_replace('_', ' ', $domain->value),
            ));
        }

        if (! $posting->movementType->allowsDirection($posting->direction)) {
            throw new InvalidStockPostingException(sprintf(
                'Movement type %s cannot use direction %s.',
                $posting->movementType->value,
                $posting->direction->value,
            ));
        }

        foreach ([
            'item ID' => $posting->itemId,
            'branch ID' => $posting->branchId,
            'warehouse ID' => $posting->warehouseId,
            'source ID' => $posting->sourceId,
        ] as $field => $value) {
            if ($value < 1) {
                throw new InvalidStockPostingException("The {$field} must be a positive integer.");
            }
        }

        foreach ([
            'source item ID' => $posting->sourceItemId,
            'reversal movement ID' => $posting->reversalOfId,
            'posted-by user ID' => $posting->postedBy,
        ] as $field => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidStockPostingException("The {$field} must be a positive integer when provided.");
            }
        }

        $sourceType = trim($posting->sourceType);
        $postingKey = trim($posting->postingKey);

        if ($sourceType === '' || mb_strlen($sourceType) > 80) {
            throw new InvalidStockPostingException('The source type is required and may not exceed 80 characters.');
        }

        if ($postingKey === '' || mb_strlen($postingKey) > 191) {
            throw new InvalidStockPostingException('The posting key is required and may not exceed 191 characters.');
        }

        return [
            'item_id' => $posting->itemId,
            'branch_id' => $posting->branchId,
            'warehouse_id' => $posting->warehouseId,
            'movement_type' => $posting->movementType,
            'direction' => $posting->direction,
            'quantity' => $this->decimal($posting->quantity, 6, 14, false, 'quantity'),
            'unit_cost' => $posting->unitCost === null
                ? null
                : $this->decimal($posting->unitCost, 4, 16, true, 'unit cost'),
            'source_type' => $sourceType,
            'source_id' => $posting->sourceId,
            'source_item_id' => $posting->sourceItemId,
            'posting_key' => $postingKey,
            'reversal_of_id' => $posting->reversalOfId,
            'movement_at' => CarbonImmutable::parse($posting->movementAt ?? now()),
            'note' => $posting->note,
            'posted_by' => $posting->postedBy,
        ];
    }

    private function decimal(
        int|float|string $value,
        int $scale,
        int $maxIntegerDigits,
        bool $allowZero,
        string $field,
    ): string {
        $raw = is_float($value)
            ? rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.')
            : trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidStockPostingException("The {$field} must be a non-negative decimal number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new InvalidStockPostingException("The {$field} exceeds the supported precision.");
        }

        $normalized = $integer.'.'.str_pad($fraction, $scale, '0');

        if (! $allowZero && $normalized === '0.'.str_repeat('0', $scale)) {
            throw new InvalidStockPostingException("The {$field} must be greater than zero.");
        }

        return $normalized;
    }

    /** @param array<string, mixed> $posting */
    private function assertSamePosting(StockMovement $existing, array $posting): void
    {
        $itemForeignKey = $this->domain()->itemForeignKey();

        $matches = (int) $existing->{$itemForeignKey} === $posting['item_id']
            && (int) $existing->branch_id === $posting['branch_id']
            && (int) $existing->warehouse_id === $posting['warehouse_id']
            && $existing->movement_type === $posting['movement_type']
            && $existing->direction === $posting['direction']
            && $existing->quantity === $posting['quantity']
            && $existing->unit_cost === $posting['unit_cost']
            && $existing->source_type === $posting['source_type']
            && (int) $existing->source_id === $posting['source_id']
            && $existing->source_item_id === $posting['source_item_id']
            && $existing->reversal_of_id === $posting['reversal_of_id'];

        if (! $matches) {
            throw new DuplicateStockPostingException(
                'The posting key already exists with different stock movement data.',
            );
        }
    }
}
