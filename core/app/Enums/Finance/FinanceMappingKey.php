<?php

namespace App\Enums\Finance;

enum FinanceMappingKey: string
{
    case RawMaterialInventory = 'RAW_MATERIAL_INVENTORY';
    case PartInventory = 'PART_INVENTORY';
    case SupplierPayable = 'SUPPLIER_PAYABLE';
    case InputTax = 'INPUT_TAX';
    case PurchaseDiscount = 'PURCHASE_DISCOUNT';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterialInventory => 'Raw Material Inventory',
            self::PartInventory => 'Part Inventory',
            self::SupplierPayable => 'Supplier Payable',
            self::InputTax => 'Input Tax',
            self::PurchaseDiscount => 'Purchase Discount',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::RawMaterialInventory => 'Stores the accounting value of received raw materials.',
            self::PartInventory => 'Stores the accounting value of purchased parts.',
            self::SupplierPayable => 'Tracks amounts owed to suppliers.',
            self::InputTax => 'Tracks recoverable purchase tax.',
            self::PurchaseDiscount => 'Tracks supplier discounts separately from inventory.',
        };
    }

    public function expectedTypeCode(): string
    {
        return match ($this) {
            self::RawMaterialInventory, self::PartInventory, self::InputTax => 'ASSET',
            self::SupplierPayable => 'LIABILITY',
            self::PurchaseDiscount => 'INCOME',
        };
    }

    public function expectedNormalBalance(): AccountNormalBalance
    {
        return match ($this) {
            self::RawMaterialInventory, self::PartInventory, self::InputTax => AccountNormalBalance::Debit,
            self::SupplierPayable, self::PurchaseDiscount => AccountNormalBalance::Credit,
        };
    }
}
