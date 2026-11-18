<?php

namespace App\Enums\Finance;

enum FinanceMappingKey: string
{
    case RawMaterialInventory = 'RAW_MATERIAL_INVENTORY';
    case PartInventory = 'PART_INVENTORY';
    case SupplierPayable = 'SUPPLIER_PAYABLE';
    case InputTax = 'INPUT_TAX';
    case PurchaseDiscount = 'PURCHASE_DISCOUNT';
    case CustomerReceivable = 'CUSTOMER_RECEIVABLE';
    case OutputTax = 'OUTPUT_TAX';
    case SalesRevenue = 'SALES_REVENUE';
    /** @deprecated Production costs are operational/reporting-only and no longer post to Finance. */
    case ProductionExpensePayable = 'PRODUCTION_EXPENSE_PAYABLE';
    /** @deprecated Production costs are operational/reporting-only and no longer post to Finance. */
    case ProductionLoss = 'PRODUCTION_LOSS';

    /** @return array<int, self> */
    public static function configurableCases(): array
    {
        return [
            self::RawMaterialInventory,
            self::PartInventory,
            self::SupplierPayable,
            self::InputTax,
            self::PurchaseDiscount,
            self::CustomerReceivable,
            self::OutputTax,
            self::SalesRevenue,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::RawMaterialInventory => 'Raw Material Inventory',
            self::PartInventory => 'Part Inventory',
            self::SupplierPayable => 'Supplier Payable',
            self::InputTax => 'Input Tax',
            self::PurchaseDiscount => 'Purchase Discount',
            self::CustomerReceivable => 'Customer Receivable',
            self::OutputTax => 'Output Tax Payable',
            self::SalesRevenue => 'Sales Revenue',
            self::ProductionExpensePayable => 'Production Expense Payable',
            self::ProductionLoss => 'Production Loss',
        };
    }

    public function purpose(): string
    {
        return match ($this) {
            self::RawMaterialInventory => 'Stores the accounting value of purchased raw materials.',
            self::PartInventory => 'Stores the accounting value of purchased parts.',
            self::SupplierPayable => 'Tracks amounts owed to suppliers.',
            self::InputTax => 'Tracks recoverable purchase tax.',
            self::PurchaseDiscount => 'Tracks supplier discounts separately from inventory.',
            self::CustomerReceivable => 'Tracks unpaid balances from registered customers.',
            self::OutputTax => 'Tracks tax collected from customers and payable to the authority.',
            self::SalesRevenue => 'Tracks net Finished Product sales revenue.',
            self::ProductionExpensePayable => 'Legacy production payable mapping retained only for historical data compatibility.',
            self::ProductionLoss => 'Legacy production loss mapping retained only for historical data compatibility.',
        };
    }

    public function expectedTypeCode(): string
    {
        return match ($this) {
            self::RawMaterialInventory, self::PartInventory, self::InputTax, self::CustomerReceivable => 'ASSET',
            self::SupplierPayable, self::OutputTax => 'LIABILITY',
            self::ProductionExpensePayable => 'LIABILITY',
            self::PurchaseDiscount, self::SalesRevenue => 'INCOME',
            self::ProductionLoss => 'EXPENSE',
        };
    }

    public function expectedNormalBalance(): AccountNormalBalance
    {
        return match ($this) {
            self::RawMaterialInventory, self::PartInventory, self::InputTax, self::CustomerReceivable => AccountNormalBalance::Debit,
            self::SupplierPayable, self::OutputTax, self::ProductionExpensePayable,
            self::PurchaseDiscount, self::SalesRevenue => AccountNormalBalance::Credit,
            self::ProductionLoss => AccountNormalBalance::Debit,
        };
    }
}
