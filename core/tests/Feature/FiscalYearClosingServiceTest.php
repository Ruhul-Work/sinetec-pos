<?php

namespace Tests\Feature;

use App\Enums\Finance\FiscalYearClosingStatus;
use App\Enums\Finance\JournalStatus;
use App\Exceptions\Finance\InvalidFiscalYearClosingException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\User;
use App\Models\backend\VoucherType;
use App\Services\Finance\FiscalYearClosingService;
use Database\Seeders\FinanceFoundationSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawings_closing_uses_posted_balance_and_creates_a_balanced_journal_draft(): void
    {
        $this->travelTo('2026-12-31 10:00:00');
        [$branch, $year, $drawings, $capital, $user] = $this->foundation();
        $this->postDrawingsDebit($branch, $year, $drawings, 1250.50);

        $service = app(FiscalYearClosingService::class);
        $closing = $service->create([
            'fiscal_year_id' => $year->id,
            'branch_id' => $branch->id,
            'drawings_account_id' => $drawings->id,
            'capital_account_id' => $capital->id,
            'closing_date' => '2026-12-31',
            'reference_no' => 'YEAR-END',
        ], $user->id);

        $this->assertSame(FiscalYearClosingStatus::Draft, $closing->status);
        $this->assertSame('1250.5000', $closing->amount);

        $prepared = $service->post($closing, $user->id);
        $this->assertSame(FiscalYearClosingStatus::Posted, $prepared->status);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'fiscal_year_closing',
            'source_id' => $closing->id,
            'status' => JournalStatus::Draft->value,
        ]);
        $journal = JournalEntry::query()->where('source_type', 'fiscal_year_closing')->firstOrFail();
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $journal->id, 'account_id' => $capital->id, 'debit' => 1250.5000, 'credit' => 0]);
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $journal->id, 'account_id' => $drawings->id, 'debit' => 0, 'credit' => 1250.5000]);
    }

    public function test_closing_rejects_a_drawings_account_without_debit_balance(): void
    {
        $this->travelTo('2026-12-31 10:00:00');
        [$branch, $year, $drawings, $capital, $user] = $this->foundation();

        $this->expectException(InvalidFiscalYearClosingException::class);
        $this->expectExceptionMessage('no debit balance');
        app(FiscalYearClosingService::class)->create([
            'fiscal_year_id' => $year->id,
            'branch_id' => $branch->id,
            'drawings_account_id' => $drawings->id,
            'capital_account_id' => $capital->id,
            'closing_date' => '2026-12-31',
        ], $user->id);
    }

    /** @return array{Branch, FiscalYear, Account, Account, User} */
    private function foundation(): array
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(VoucherTypeSeeder::class);
        $year = FiscalYear::query()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]);
        $user = User::query()->create(['name' => 'Finance User', 'password' => bcrypt('password')]);

        return [
            $branch,
            $year,
            Account::query()->where('code', '3020')->firstOrFail(),
            Account::query()->where('code', '3000')->firstOrFail(),
            $user,
        ];
    }

    private function postDrawingsDebit(Branch $branch, FiscalYear $year, Account $drawings, float $amount): void
    {
        $journal = JournalEntry::query()->create([
            'voucher_no' => 'TEST-DRAWINGS-000001',
            'voucher_type_id' => VoucherType::query()->where('code', 'PAYMENT')->value('id'),
            'branch_id' => $branch->id,
            'fiscal_year_id' => $year->id,
            'posting_key' => 'test:drawings:1',
            'entry_date' => '2026-06-01',
            'status' => JournalStatus::Posted,
            'narration' => 'Posted drawings for closing test',
        ]);
        $journal->lines()->create(['account_id' => $drawings->id, 'branch_id' => $branch->id, 'debit' => $amount, 'credit' => 0, 'memo' => 'Drawings']);
        $journal->lines()->create(['account_id' => Account::query()->where('code', '1000')->value('id'), 'branch_id' => $branch->id, 'debit' => 0, 'credit' => $amount, 'memo' => 'Cash']);
    }
}
