<?php

namespace Tests\Feature;

use App\Http\Controllers\backend\AccountLedgerController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\VoucherType;
use Database\Seeders\CorePermissionSeeder;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountLedgerControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_ledger_includes_only_posted_rows_and_keeps_true_running_balance_after_date_filter(): void
    {
        [$branch, $account] = $this->foundation();
        $this->createOpening($branch, $account, 50);
        $this->createJournal($branch, $account, 'POSTED', 100, 'LEDGER-POSTED');
        $this->createJournal($branch, $account, 'DRAFT', 80, 'LEDGER-DRAFT');
        $this->createJournal($branch, $account, 'VOID', 70, 'LEDGER-VOID');
        session(['branch_scope' => $branch->id]);
        $controller = app(AccountLedgerController::class);

        $this->assertSame('backend.modules.account_ledger.index', $controller->index()->name());
        $list = $controller->listAjax(Request::create('/finance/account-ledger/list', 'POST', [
            'draw' => 1, 'start' => 0, 'length' => 25, 'account_id' => $account->id,
        ]))->getData(true);

        $this->assertSame(2, $list['iTotalRecords']);
        $this->assertSame(2, $list['iTotalDisplayRecords']);
        $html = implode(' ', array_merge(...$list['aaData']));
        $this->assertStringContainsString('LEDGER-POSTED', $html);
        $this->assertStringNotContainsString('LEDGER-DRAFT', $html);
        $this->assertStringNotContainsString('LEDGER-VOID', $html);
        $this->assertStringContainsString('Dr 150.00', $html);

        $filtered = $controller->listAjax(Request::create('/finance/account-ledger/list', 'POST', [
            'draw' => 2, 'start' => 0, 'length' => 25, 'account_id' => $account->id,
            'date_from' => '2026-11-02', 'date_to' => '2026-11-02',
        ]))->getData(true);

        $this->assertSame(1, $filtered['iTotalDisplayRecords']);
        $this->assertStringContainsString('Dr 150.00', implode(' ', $filtered['aaData'][0]));

        $summary = $controller->summary(Request::create('/finance/account-ledger/summary', 'POST', [
            'account_id' => $account->id, 'date_from' => '2026-11-02', 'date_to' => '2026-11-02',
        ]))->getData(true);
        $this->assertTrue($summary['selected']);
        $this->assertSame('50.00', $summary['opening']['amount']);
        $this->assertSame('100.00', $summary['debit']);
        $this->assertSame('150.00', $summary['closing']['amount']);
    }

    public function test_account_ledger_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'finance.account_ledger')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(4, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'finance.account-ledger.accounts.select2',
        ]);
    }

    /** @return array{Branch, Account} */
    private function foundation(): array
    {
        $branch = Branch::query()->create(['name' => 'Ledger Branch', 'code' => 'BR-LDG', 'is_active' => true]);
        $this->seed(FinanceFoundationSeeder::class);
        FiscalYear::query()->create([
            'name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true,
        ]);

        return [$branch, Account::query()->where('code', '1100')->firstOrFail()];
    }

    private function createOpening(Branch $branch, Account $account, float $debit): void
    {
        DB::table('account_opening_balances')->insert([
            'fiscal_year_id' => FiscalYear::query()->value('id'),
            'branch_id' => $branch->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => 0,
            'status' => 'POSTED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createJournal(Branch $branch, Account $account, string $status, float $debit, string $number): void
    {
        $journal = JournalEntry::query()->create([
            'voucher_no' => $number,
            'voucher_type_id' => VoucherType::query()->where('code', 'PURCHASE')->value('id'),
            'branch_id' => $branch->id,
            'fiscal_year_id' => FiscalYear::query()->value('id'),
            'source_type' => 'test_ledger',
            'source_id' => crc32($number),
            'posting_key' => 'ledger:test:'.strtolower($number),
            'entry_date' => '2026-11-02',
            'status' => $status,
            'narration' => 'Ledger row '.$number,
        ]);
        $journal->lines()->create([
            'account_id' => $account->id,
            'branch_id' => $branch->id,
            'debit' => $debit,
            'credit' => 0,
            'memo' => 'Line '.$number,
        ]);
    }
}
