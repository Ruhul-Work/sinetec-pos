<?php

namespace App\Data\Inventory;

use App\Enums\Inventory\StockDirection;
use App\Enums\Inventory\StockMovementType;
use DateTimeInterface;

final readonly class StockPostingData
{
    public function __construct(
        public int $itemId,
        public int $branchId,
        public int $warehouseId,
        public StockMovementType $movementType,
        public StockDirection $direction,
        public int|float|string $quantity,
        public string $sourceType,
        public int $sourceId,
        public string $postingKey,
        public DateTimeInterface|string|null $movementAt = null,
        public int|float|string|null $unitCost = null,
        public ?int $sourceItemId = null,
        public ?int $reversalOfId = null,
        public ?string $note = null,
        public ?int $postedBy = null,
    ) {}
}
