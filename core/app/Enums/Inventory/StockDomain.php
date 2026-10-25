<?php

namespace App\Enums\Inventory;

use App\Models\backend\FinishedProduct;
use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\Inventory\FinishedProductStockBalance;
use App\Models\Inventory\FinishedProductStockMovement;
use App\Models\Inventory\PartStockBalance;
use App\Models\Inventory\PartStockMovement;
use App\Models\Inventory\RawMaterialStockBalance;
use App\Models\Inventory\RawMaterialStockMovement;

enum StockDomain: string
{
    case RawMaterial = 'raw_material';
    case Part = 'part';
    case FinishedProduct = 'finished_product';

    public function itemForeignKey(): string
    {
        return $this->value.'_id';
    }

    /** @return class-string<RawMaterial|Part|FinishedProduct> */
    public function itemModel(): string
    {
        return match ($this) {
            self::RawMaterial => RawMaterial::class,
            self::Part => Part::class,
            self::FinishedProduct => FinishedProduct::class,
        };
    }

    /** @return class-string<RawMaterialStockMovement|PartStockMovement|FinishedProductStockMovement> */
    public function movementModel(): string
    {
        return match ($this) {
            self::RawMaterial => RawMaterialStockMovement::class,
            self::Part => PartStockMovement::class,
            self::FinishedProduct => FinishedProductStockMovement::class,
        };
    }

    /** @return class-string<RawMaterialStockBalance|PartStockBalance|FinishedProductStockBalance> */
    public function balanceModel(): string
    {
        return match ($this) {
            self::RawMaterial => RawMaterialStockBalance::class,
            self::Part => PartStockBalance::class,
            self::FinishedProduct => FinishedProductStockBalance::class,
        };
    }

    public function allowsMovement(StockMovementType $type): bool
    {
        if (in_array($type, [
            StockMovementType::Opening,
            StockMovementType::TransferIn,
            StockMovementType::TransferOut,
            StockMovementType::Adjustment,
            StockMovementType::Damage,
            StockMovementType::Wastage,
            StockMovementType::Reversal,
        ], true)) {
            return true;
        }

        return match ($this) {
            self::RawMaterial => in_array($type, [
                StockMovementType::PurchaseReceipt,
                StockMovementType::PurchaseReturn,
                StockMovementType::PartProductionConsumption,
                StockMovementType::AssemblyConsumption,
            ], true),
            self::Part => in_array($type, [
                StockMovementType::PurchaseReceipt,
                StockMovementType::PurchaseReturn,
                StockMovementType::PartProductionOutput,
                StockMovementType::AssemblyConsumption,
            ], true),
            self::FinishedProduct => in_array($type, [
                StockMovementType::AssemblyOutput,
                StockMovementType::Sale,
                StockMovementType::SaleReturn,
            ], true),
        };
    }
}
