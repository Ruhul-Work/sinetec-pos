<?php

namespace Tests\Feature;

use App\Enums\Finance\LoanReceiptStatus;
use App\Http\Controllers\backend\LoanRepaymentController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\Finance\LoanReceipt;
use App\Models\Finance\LoanRepayment;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanRepaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_scope_protects_loan_due_and_repayment_details(): void
    {
        [$ownBranch, $otherBranch, $receipt] = $this->foundation();
        session(['branch_scope' => $ownBranch->id]);
        $controller = app(LoanRepaymentController::class);

        try {
            $controller->due($receipt->id);
            $this->fail('Another branch loan due must not be visible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $repayment = LoanRepayment::query()->create([
            'repayment_no' => 'LRP-2026-00001',
            'branch_id' => $otherBranch->id,
            'loan_receipt_id' => $receipt->id,
            'cash_bank_account_id' => $receipt->cash_bank_account_id,
            'repayment_date' => '2026-11-07',
            'amount' => 100,
        ]);

        $this->expectException(ModelNotFoundException::class);
        $controller->show($repayment->id);
    }

    public function test_all_branch_scope_allows_super_admin_report_access(): void
    {
        [, , $receipt] = $this->foundation();
        session(['branch_scope' => 'all']);

        $response = app(LoanRepaymentController::class)->due($receipt->id)->getData(true);

        $this->assertEquals(500.0, $response['due']);
    }

    /** @return array{Branch, Branch, LoanReceipt} */
    private function foundation(): array
    {
        $ownBranch = Branch::query()->create([
            'name' => 'Own Branch',
            'code' => 'BR-OWN',
            'is_active' => true,
        ]);
        $otherBranch = Branch::query()->create([
            'name' => 'Other Branch',
            'code' => 'BR-OTHER',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);

        $receipt = LoanReceipt::query()->create([
            'loan_no' => 'LOAN-2026-00001',
            'branch_id' => $otherBranch->id,
            'loan_type' => 'DIRECTOR_LOAN',
            'cash_bank_account_id' => Account::query()->where('code', '1000')->value('id'),
            'loan_account_id' => Account::query()->where('code', '2100')->value('id'),
            'receipt_date' => '2026-11-01',
            'amount' => 500,
            'status' => LoanReceiptStatus::Posted,
        ]);

        return [$ownBranch, $otherBranch, $receipt];
    }
}
