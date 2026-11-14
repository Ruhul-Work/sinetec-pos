<?php

namespace Tests\Feature;

use App\Enums\Finance\ExpenseStatus;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\backend\PaymentType;
use App\Models\backend\User;
use App\Models\Finance\ExpenseCategory;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\FinanceFoundationService;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpensePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_expense_creates_a_balanced_draft_journal(): void
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        app(FinanceFoundationService::class)->bootstrap();
        $this->seed(VoucherTypeSeeder::class);

        FiscalYear::query()->create([
            'name' => 'FY '.now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'is_active' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Finance User',
            'email' => 'finance@example.test',
            'password' => bcrypt('password'),
            'status' => true,
        ]);
        $paymentType = PaymentType::query()->create([
            'name' => 'Cash',
            'slug' => 'cash',
            'is_active' => true,
        ]);
        $cashAccount = Account::query()->where('code', '1000')->firstOrFail();
        $expenseAccount = Account::query()->create([
            'account_type_id' => AccountType::query()->where('code', 'EXPENSE')->value('id'),
            'name' => 'General Expense',
            'code' => '5900',
            'currency' => 'BDT',
            'is_active' => true,
        ]);
        BranchAccount::query()->create([
            'branch_id' => $branch->id,
            'account_id' => $expenseAccount->id,
            'is_active' => true,
        ]);
        $category = ExpenseCategory::query()->create([
            'code' => 'TEST_EXPENSE',
            'name' => 'Test Expense',
            'category_scope' => 'GENERAL',
            'expense_account_id' => $expenseAccount->id,
            'is_active' => true,
        ]);

        $service = app(ExpenseService::class);
        $expense = $service->create([
            'branch_id' => $branch->id,
            'expense_category_id' => $category->id,
            'expense_date' => now()->toDateString(),
            'amount' => '750',
            'payment_type_id' => $paymentType->id,
            'account_id' => $cashAccount->id,
            'payee' => 'Production Team',
        ], $user->id);

        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $recorded = $service->post($expense, $user->id);

        $this->assertSame(ExpenseStatus::Posted, $recorded->status);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'expense',
            'source_id' => $recorded->id,
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $recorded->journal->id,
            'account_id' => $expenseAccount->id,
            'debit' => 750,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $recorded->journal->id,
            'account_id' => $cashAccount->id,
            'debit' => 0,
            'credit' => 750,
        ]);
    }
}
