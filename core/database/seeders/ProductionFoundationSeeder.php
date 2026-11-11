<?php

namespace Database\Seeders;

use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Finance\FinanceMappingScope;
use App\Models\backend\Account;
use App\Models\Finance\FinanceAccountMapping;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Finance\ExpenseCategorySyncService;
use Illuminate\Database\Seeder;

class ProductionFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'DIRECT_LABOUR', 'name' => 'Direct Labour'],
            ['code' => 'PRODUCTION_POWER', 'name' => 'Production Electricity / Power'],
            ['code' => 'PACKING_HANDLING', 'name' => 'Packing and Handling'],
            ['code' => 'PRODUCTION_TRANSPORT', 'name' => 'Production Transport'],
            ['code' => 'OTHER_DIRECT', 'name' => 'Other Direct Production Cost'],
        ];

        foreach ($categories as $category) {
            $productionCategory = ProductionExpenseCategory::query()->updateOrCreate(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                ],
            );

            app(ExpenseCategorySyncService::class)
                ->syncProductionCategory($productionCategory);
        }

        $productionLoss = Account::query()->where('code', '5100')->first();
        if ($productionLoss) {
            FinanceAccountMapping::query()->updateOrCreate(
                [
                    'scope_key' => FinanceMappingScope::Company->scopeKey(),
                    'mapping_key' => FinanceMappingKey::ProductionLoss,
                ],
                [
                    'scope' => FinanceMappingScope::Company,
                    'branch_id' => null,
                    'account_id' => $productionLoss->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
