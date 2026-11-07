<?php

namespace Tests\Feature;

use App\Http\Controllers\backend\TrialBalanceController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrialBalanceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_report_filter_uses_the_requested_branch_when_topbar_scope_is_all(): void
    {
        [$firstBranch, $secondBranch, $cashAccount, $capitalAccount] = $this->foundation();

        $this->createOpening($firstBranch, $cashAccount, $capitalAccount, 100);
        $this->createOpening($secondBranch, $cashAccount, $capitalAccount, 250);
        session(['branch_scope' => 'all']);

        $controller = app(TrialBalanceController::class);
        $first = $controller->listAjax(Request::create('/finance/trial-balance/list', 'POST', [
            'draw' => 1,
            'branch_id' => $firstBranch->id,
            'date_to' => '2026-12-31',
        ]))->getData(true);
        $second = $controller->listAjax(Request::create('/finance/trial-balance/list', 'POST', [
            'draw' => 2,
            'branch_id' => $secondBranch->id,
            'date_to' => '2026-12-31',
        ]))->getData(true);

        $this->assertStringContainsString('100.00', $this->tableHtml($first));
        $this->assertStringNotContainsString('250.00', $this->tableHtml($first));
        $this->assertStringContainsString('250.00', $this->tableHtml($second));
        $this->assertStringNotContainsString('100.00', $this->tableHtml($second));
    }

    public function test_fixed_topbar_branch_scope_cannot_be_overridden_by_the_report_filter(): void
    {
        [$firstBranch, $secondBranch, $cashAccount, $capitalAccount] = $this->foundation();

        $this->createOpening($firstBranch, $cashAccount, $capitalAccount, 100);
        $this->createOpening($secondBranch, $cashAccount, $capitalAccount, 250);
        session(['branch_scope' => $firstBranch->id]);

        $response = app(TrialBalanceController::class)->listAjax(
            Request::create('/finance/trial-balance/list', 'POST', [
                'draw' => 1,
                'branch_id' => $secondBranch->id,
                'date_to' => '2026-12-31',
            ]),
        )->getData(true);

        $this->assertStringContainsString('100.00', $this->tableHtml($response));
        $this->assertStringNotContainsString('250.00', $this->tableHtml($response));
    }

    /** @return array{Branch, Branch, Account, Account} */
    private function foundation(): array
    {
        $firstBranch = Branch::query()->create([
            'name' => 'First Branch',
            'code' => 'BR-001',
            'is_active' => true,
        ]);
        $secondBranch = Branch::query()->create([
            'name' => 'Second Branch',
            'code' => 'BR-002',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);
        FiscalYear::query()->create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);

        return [
            $firstBranch,
            $secondBranch,
            Account::query()->where('code', '1000')->firstOrFail(),
            Account::query()->where('code', '3000')->firstOrFail(),
        ];
    }

    private function createOpening(Branch $branch, Account $cashAccount, Account $capitalAccount, int $amount): void
    {
        foreach ([[$cashAccount->id, $amount, 0], [$capitalAccount->id, 0, $amount]] as [$accountId, $debit, $credit]) {
            DB::table('account_opening_balances')->insert([
                'fiscal_year_id' => FiscalYear::query()->value('id'),
                'branch_id' => $branch->id,
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'status' => 'POSTED',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string, mixed> $response */
    private function tableHtml(array $response): string
    {
        return implode(' ', array_merge(...$response['aaData']));
    }
}
