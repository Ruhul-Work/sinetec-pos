<?php

namespace Tests\Feature;

use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\VoucherType;
use App\Services\Finance\CashBankTransferService;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashBankTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_keeps_exact_amount_and_creates_one_balanced_journal_draft(): void
    {
        [$branch, $userId] = $this->foundation();
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $bank = Account::query()->where('code', '1010')->firstOrFail();
        $service = app(CashBankTransferService::class);

        $transfer = $service->create([
            'branch_id' => $branch->id,
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'transfer_date' => '2026-11-07',
            'amount' => '0.1001',
            'reference_no' => 'TEST-TRANSFER',
        ], $userId);

        $this->assertSame('0.1001', $transfer->amount);
        $posted = $service->post($transfer, $userId);
        $service->post($posted, $userId);

        $journal = JournalEntry::query()
            ->where('source_type', 'cash_bank_transfer')
            ->where('source_id', $transfer->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame('POSTED', $posted->status);
        $this->assertSame('DRAFT', $journal->status->value);
        $this->assertCount(2, $journal->lines);
        $this->assertSame('0.1001', $journal->lines->max('debit'));
        $this->assertSame('0.1001', $journal->lines->max('credit'));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    /** @return array{Branch, int} */
    private function foundation(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'BR-001',
            'is_active' => true,
        ]);
        $this->seed(FinanceFoundationSeeder::class);
        FiscalYear::query()->create([
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
        VoucherType::query()->updateOrCreate(
            ['code' => 'TRANSFER'],
            ['name' => 'Account Transfer Voucher'],
        );
        $userId = DB::table('users')->insertGetId([
            'name' => 'Finance Tester',
            'email' => 'finance@example.test',
            'password' => 'test-password-hash',
            'branch_id' => $branch->id,
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$branch, $userId];
    }
}
