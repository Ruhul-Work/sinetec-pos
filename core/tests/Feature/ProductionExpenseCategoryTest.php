<?php

namespace Tests\Feature;

use App\Http\Controllers\backend\ProductionExpenseCategoryController;
use App\Models\Production\ProductionExpenseCategory;
use Database\Seeders\FinanceFoundationSeeder;
use Database\Seeders\ProductionFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProductionExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_is_managed_without_an_individual_expense_account(): void
    {
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        $category = ProductionExpenseCategory::query()
            ->where('code', 'DIRECT_LABOUR')
            ->firstOrFail();

        $response = app(ProductionExpenseCategoryController::class)->update(
            Request::create('/production/expense-categories/'.$category->id, 'PUT', [
                'code' => 'DIRECT_LABOUR',
                'name' => 'Direct Labour and Overtime',
                'is_active' => 1,
            ]),
            $category->id,
        );

        $this->assertTrue($response->getData(true)['success']);
        $this->assertDatabaseHas('production_expense_categories', [
            'id' => $category->id,
            'name' => 'Direct Labour and Overtime',
            'expense_account_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('expense_categories', [
            'code' => 'DIRECT_LABOUR',
            'category_scope' => 'PRODUCTION',
        ]);
    }

    public function test_foundation_keeps_production_categories_report_only(): void
    {
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        $this->assertDatabaseHas('production_expense_categories', [
            'code' => 'PRODUCTION_POWER',
            'name' => 'Production Electricity / Power',
            'expense_account_id' => null,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('finance_account_mappings', [
            'mapping_key' => 'PRODUCTION_EXPENSE_PAYABLE',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('accounts', ['code' => '2050']);
        $this->assertDatabaseMissing('accounts', ['code' => '5100']);
    }
}
