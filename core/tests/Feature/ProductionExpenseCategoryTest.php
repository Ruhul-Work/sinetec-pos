<?php

namespace Tests\Feature;

use App\Http\Controllers\backend\ProductionExpenseCategoryController;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use App\Models\backend\Branch;
use App\Models\Production\ProductionExpenseCategory;
use Database\Seeders\FinanceFoundationSeeder;
use Database\Seeders\ProductionFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductionExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_category_maps_a_leaf_account_and_assigns_it_to_active_branches(): void
    {
        $branch = $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        [$parent, $labour] = $this->productionExpenseAccounts();
        $category = ProductionExpenseCategory::query()
            ->where('code', 'DIRECT_LABOUR')
            ->firstOrFail();

        $response = app(ProductionExpenseCategoryController::class)->update(
            Request::create('/production/expense-categories/'.$category->id, 'PUT', [
                'code' => 'DIRECT_LABOUR',
                'name' => 'Direct Labour',
                'expense_account_id' => $labour->id,
                'is_active' => 1,
            ]),
            $category->id,
        );

        $this->assertTrue($response->getData(true)['success']);
        $this->assertDatabaseHas('production_expense_categories', [
            'id' => $category->id,
            'expense_account_id' => $labour->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('expense_categories', [
            'code' => 'DIRECT_LABOUR',
            'category_scope' => 'PRODUCTION',
            'expense_account_id' => $labour->id,
        ]);
        $this->assertDatabaseHas('branch_accounts', [
            'branch_id' => $branch->id,
            'account_id' => $labour->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('branch_accounts', [
            'branch_id' => $branch->id,
            'account_id' => $parent->id,
        ]);
    }

    public function test_parent_expense_account_cannot_be_mapped_to_a_category(): void
    {
        $this->branch();
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(ProductionFoundationSeeder::class);

        [$parent] = $this->productionExpenseAccounts();
        $category = ProductionExpenseCategory::query()
            ->where('code', 'DIRECT_LABOUR')
            ->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('child/leaf Expense account');

        app(ProductionExpenseCategoryController::class)->update(
            Request::create('/production/expense-categories/'.$category->id, 'PUT', [
                'code' => 'DIRECT_LABOUR',
                'name' => 'Direct Labour',
                'expense_account_id' => $parent->id,
                'is_active' => 1,
            ]),
            $category->id,
        );
    }

    /** @return array{Account, Account} */
    private function productionExpenseAccounts(): array
    {
        $expenseTypeId = AccountType::query()->where('code', 'EXPENSE')->value('id');
        $parent = Account::query()->create([
            'account_type_id' => $expenseTypeId,
            'name' => 'Production Expenses',
            'code' => '5200',
            'currency' => 'BDT',
            'is_active' => true,
        ]);
        $labour = Account::query()->create([
            'account_type_id' => $expenseTypeId,
            'parent_id' => $parent->id,
            'name' => 'Direct Labour Expense',
            'code' => '5210',
            'currency' => 'BDT',
            'is_active' => true,
        ]);

        return [$parent, $labour];
    }

    private function branch(): Branch
    {
        return Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'BR-001',
            'is_active' => true,
        ]);
    }
}
