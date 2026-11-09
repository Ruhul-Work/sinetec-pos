<?php

namespace App\Enums\Finance;

enum FinanceMappingKey: string
{
    case RawMaterialInventory = 'RAW_MATERIAL_INVENTORY';
    case PartInventory = 'PART_INVENTORY';
    case SupplierPayable = 'SUPPLIER_PAYABLE';
    case InputTax = 'INPUT_TAX';
    case PurchaseDiscount = 'PURCHASE_DISCOUNT';
    case ProductionLoss = 'PRODUCTION_LOSS';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterialInventory => 'Raw Material Inventory',
            self::PartInventory => 'Part Inventory',
            self::SupplierPayable => 'Supplier Payable',
            self::InputTax => 'Input Tax',
            self::PurchaseDiscount => 'Purchase Discount',
            self::ProductionLoss => 'Production Loss',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::RawMaterialInventory => 'Stores the accounting value of received raw materials and production consumption.',
            self::PartInventory => 'Stores the accounting value of purchased and internally produced parts.',
            self::SupplierPayable => 'Tracks amounts owed to suppliers.',
            self::InputTax => 'Tracks recoverable purchase tax.',
            self::PurchaseDiscount => 'Tracks supplier discounts separately from inventory.',
            self::ProductionLoss => 'Records raw-material and allocated batch cost when a production batch fully fails QC.',
        };
    }

    public function expectedTypeCode(): string
    {
        return match ($this) {
            self::RawMaterialInventory, self::PartInventory, self::InputTax => 'ASSET',
            self::SupplierPayable => 'LIABILITY',
            self::PurchaseDiscount => 'INCOME',
            self::ProductionLoss => 'EXPENSE',
        };
    }

    public function expectedNormalBalance(): AccountNormalBalance
    {
        return match ($this) {
            self::RawMaterialInventory, self::PartInventory, self::InputTax => AccountNormalBalance::Debit,
            self::SupplierPayable, self::PurchaseDiscount => AccountNormalBalance::Credit,
            self::ProductionLoss => AccountNormalBalance::Debit,
        };
    }
}
