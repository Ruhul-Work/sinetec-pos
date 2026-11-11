<?php

namespace Tests\Feature;

use App\Http\Controllers\backend\ExpenseCategoryController;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use App\Models\backend\Branch;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class GeneralExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_category_maps_a_leaf_account_to_all_active_branches(): void
    {
        $mainBranch = $this->branch('Main Branch', 'MAIN');
        $secondBranch = $this->branch('Second Branch', 'SECOND');
        $this->seed(FinanceFoundationSeeder::class);

        $expenseAccount = $this->leafExpenseAccount();
        $response = app(ExpenseCategoryController::class)->store(
            Request::create('/finance/expense-categories', 'POST', [
                'code' => 'OFFICE_RENT',
                'name' => 'Office Rent',
                'expense_account_id' => $expenseAccount->id,
                'is_active' => 1,
            ]),
        );

        $this->assertTrue($response->getData(true)['success']);
        $this->assertDatabaseHas('expense_categories', [
            'code' => 'OFFICE_RENT',
            'category_scope' => 'GENERAL',
            'expense_account_id' => $expenseAccount->id,
        ]);

        foreach ([$mainBranch, $secondBranch] as $branch) {
            $this->assertDatabaseHas('branch_accounts', [
                'branch_id' => $branch->id,
                'account_id' => $expenseAccount->id,
                'is_active' => true,
            ]);
        }
    }

    private function leafExpenseAccount(): Account
    {
        $expenseTypeId = AccountType::query()->where('code', 'EXPENSE')->value('id');
        $parent = Account::query()->create([
            'account_type_id' => $expenseTypeId,
            'name' => 'Administrative Expenses',
            'code' => '5300',
            'currency' => 'BDT',
            'is_active' => true,
        ]);

        return Account::query()->create([
            'account_type_id' => $expenseTypeId,
            'parent_id' => $parent->id,
            'name' => 'Office Rent Expense',
            'code' => '5310',
            'currency' => 'BDT',
            'is_active' => true,
        ]);
    }

    private function branch(string $name, string $code): Branch
    {
        return Branch::query()->create([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);
    }
}
