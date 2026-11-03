<?php

namespace Tests\Feature;

use App\Enums\Finance\JournalStatus;
use App\Http\Controllers\backend\JournalController;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\VoucherType;
use Database\Seeders\CorePermissionSeeder;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_list_and_details_are_read_only_and_branch_scoped(): void
    {
        [$branch, $otherBranch, $journal] = $this->foundation();
        session(['branch_scope' => $branch->id]);
        $controller = app(JournalController::class);

        $this->assertSame('backend.modules.journals.index', $controller->index()->name());
        $list = $controller->listAjax(Request::create('/finance/journals/list', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))->getData(true);

        $this->assertSame(1, $list['iTotalRecords']);
        $this->assertSame(1, $list['iTotalDisplayRecords']);
        $this->assertStringContainsString($journal->voucher_no, $list['aaData'][0][1]);
        $this->assertStringContainsString('Draft', $list['aaData'][0][9]);

        $details = $controller->show($journal->id);
        $this->assertSame('backend.modules.journals.show', $details->name());
        $html = $details->render();
        $this->assertStringContainsString($journal->voucher_no, $html);
        $this->assertStringContainsString('Balanced', $html);
        $this->assertStringContainsString('Journal workflow', $html);
        $this->assertStringNotContainsString('Post Journal', $html);

        $otherJournalId = JournalEntry::query()->where('branch_id', $otherBranch->id)->value('id');
        $this->expectException(ModelNotFoundException::class);
        $controller->show($otherJournalId);
    }

    public function test_journal_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);
        $permissionId = DB::table('permissions')->where('key', 'finance.journals')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(7, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'finance.journals.reverse',
        ]);
    }

    /** @return array{Branch, Branch, JournalEntry} */
    private function foundation(): array
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $otherBranch = Branch::query()->create(['name' => 'Other Branch', 'code' => 'BR-002', 'is_active' => true]);
        $this->seed(FinanceFoundationSeeder::class);
        $year = FiscalYear::query()->create([
            'name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true,
        ]);
        $voucher = VoucherType::query()->where('code', 'PURCHASE')->firstOrFail();
        $inventory = Account::query()->where('code', '1100')->firstOrFail();
        $payable = Account::query()->where('code', '2000')->firstOrFail();

        $journal = $this->journal($branch, $year, $voucher, 'PURCHASE-2026-000001', 'journal:test:1');
        $journal->lines()->createMany([
            ['account_id' => $inventory->id, 'branch_id' => $branch->id, 'debit' => 100, 'credit' => 0, 'memo' => 'Inventory received'],
            ['account_id' => $payable->id, 'branch_id' => $branch->id, 'debit' => 0, 'credit' => 100, 'memo' => 'Supplier payable'],
        ]);
        $otherJournal = $this->journal($otherBranch, $year, $voucher, 'PURCHASE-2026-000002', 'journal:test:2');
        $otherJournal->lines()->createMany([
            ['account_id' => $inventory->id, 'branch_id' => $otherBranch->id, 'debit' => 50, 'credit' => 0],
            ['account_id' => $payable->id, 'branch_id' => $otherBranch->id, 'debit' => 0, 'credit' => 50],
        ]);

        return [$branch, $otherBranch, $journal];
    }

    private function journal(Branch $branch, FiscalYear $year, VoucherType $voucher, string $number, string $postingKey): JournalEntry
    {
        return JournalEntry::query()->create([
            'voucher_no' => $number,
            'voucher_type_id' => $voucher->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $year->id,
            'source_type' => 'purchase_receipt',
            'source_id' => $branch->id,
            'posting_key' => $postingKey,
            'entry_date' => '2026-11-02',
            'status' => JournalStatus::Draft,
            'narration' => 'Read-only UI test journal',
        ]);
    }
}
