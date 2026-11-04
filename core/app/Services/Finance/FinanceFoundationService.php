<?php

namespace App\Services\Finance;

use App\Enums\Finance\AccountNormalBalance;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\VoucherType;
use Illuminate\Support\Facades\DB;

final class FinanceFoundationService
{
    private const TYPES = [
        'ASSET' => ['name' => 'Asset', 'normal_balance' => AccountNormalBalance::Debit],
        'LIABILITY' => ['name' => 'Liability', 'normal_balance' => AccountNormalBalance::Credit],
        'EQUITY' => ['name' => 'Equity', 'normal_balance' => AccountNormalBalance::Credit],
        'INCOME' => ['name' => 'Income', 'normal_balance' => AccountNormalBalance::Credit],
        'EXPENSE' => ['name' => 'Expense', 'normal_balance' => AccountNormalBalance::Debit],
    ];

    private const ACCOUNTS = [
        ['code' => '1000', 'name' => 'Cash in Hand', 'type' => 'ASSET', 'is_cash_bank_account' => true],
        ['code' => '1010', 'name' => 'Bank Account', 'type' => 'ASSET', 'is_cash_bank_account' => true],
        ['code' => '1100', 'name' => 'Raw Material Inventory', 'type' => 'ASSET'],
        ['code' => '1110', 'name' => 'Part Inventory', 'type' => 'ASSET'],
        ['code' => '1200', 'name' => 'Input Tax Receivable', 'type' => 'ASSET'],
        ['code' => '2000', 'name' => 'Supplier Payable', 'type' => 'LIABILITY'],
        ['code' => '4100', 'name' => 'Purchase Discount', 'type' => 'INCOME'],
    ];

    private const VOUCHER_TYPES = [
        'PURCHASE' => 'Purchase Voucher',
        'PURCHASE_RETURN' => 'Purchase Return Voucher',
    ];

    public function bootstrap(): void
    {
        DB::transaction(function (): void {
            $types = [];
            foreach (self::TYPES as $code => $definition) {
                $types[$code] = AccountType::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $definition['name'],
                        'normal_balance' => $definition['normal_balance'],
                        'is_system' => true,
                    ],
                );
            }

            foreach (self::ACCOUNTS as $definition) {
                Account::query()->updateOrCreate(
                    ['code' => $definition['code']],
                    [
                        'account_type_id' => $types[$definition['type']]->id,
                        'parent_id' => null,
                        'name' => $definition['name'],
                        'description' => 'Standard starter account for Sinetek POS.',
                        'currency' => 'BDT',
                        'is_cash_bank_account' => $definition['is_cash_bank_account'] ?? false,
                        'allow_negative' => false,
                        'is_active' => true,
                        'is_system' => true,
                    ],
                );
            }

            foreach (self::VOUCHER_TYPES as $code => $name) {
                VoucherType::query()->updateOrCreate(['code' => $code], ['name' => $name]);
            }

            Branch::query()->where('is_active', true)->each(fn (Branch $branch) => $this->assignStarterAccountsToBranch($branch));
        }, 3);
    }

    public function assignStarterAccountsToBranch(Branch $branch): void
    {
        $hasDefault = BranchAccount::query()->where('branch_id', $branch->id)->where('is_default', true)->exists();
        Account::query()->where('is_system', true)->where('is_active', true)->each(function (Account $account) use ($branch, &$hasDefault): void {
            $makeDefault = ! $hasDefault && $account->code === '1000';
            $values = ['is_active' => true];
            if ($makeDefault) {
                $values['is_default'] = true;
            }
            BranchAccount::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'account_id' => $account->id],
                $values,
            );
            if ($makeDefault) {
                $hasDefault = true;
            }
        });
    }
}
