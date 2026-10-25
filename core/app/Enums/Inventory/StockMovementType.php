<?php

namespace App\Enums\Inventory;

enum StockMovementType: string
{
    case Opening = 'OPENING';
    case PurchaseReceipt = 'PURCHASE_RECEIPT';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case TransferIn = 'TRANSFER_IN';
    case TransferOut = 'TRANSFER_OUT';
    case Adjustment = 'ADJUSTMENT';
    case Damage = 'DAMAGE';
    case Wastage = 'WASTAGE';
    case PartProductionConsumption = 'PART_PRODUCTION_CONSUMPTION';
    case PartProductionOutput = 'PART_PRODUCTION_OUTPUT';
    case AssemblyConsumption = 'ASSEMBLY_CONSUMPTION';
    case AssemblyOutput = 'ASSEMBLY_OUTPUT';
    case Sale = 'SALE';
    case SaleReturn = 'SALE_RETURN';
    case Reversal = 'REVERSAL';

    public function allowsDirection(StockDirection $direction): bool
    {
        return match ($this) {
            self::Opening,
            self::PurchaseReceipt,
            self::TransferIn,
            self::PartProductionOutput,
            self::AssemblyOutput,
            self::SaleReturn => $direction === StockDirection::In,

            self::PurchaseReturn,
            self::TransferOut,
            self::Damage,
            self::Wastage,
            self::PartProductionConsumption,
            self::AssemblyConsumption,
            self::Sale => $direction === StockDirection::Out,

            self::Adjustment,
            self::Reversal => true,
        };
    }
}
