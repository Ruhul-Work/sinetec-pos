<?php

namespace Database\Seeders;

use App\Models\Production\ProductionExpenseCategory;
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
            ProductionExpenseCategory::query()->updateOrCreate(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                ],
            );
        }
    }
}
