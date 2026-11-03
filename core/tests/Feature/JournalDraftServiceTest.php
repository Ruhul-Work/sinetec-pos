<?php

namespace Tests\Feature;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\JournalStatus;
use App\Exceptions\Finance\DuplicateJournalDraftException;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Services\Finance\JournalDraftService;
use Database\Seeders\FinanceFoundationSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_draft_is_created_with_sequence_and_four_decimal_lines(): void
    {
        [$branch, $inventory, $payable] = $this->foundation();

        $result = app(JournalDraftService::class)->create($this->draft(
            $branch,
            $inventory,
            $payable,
            amount: '1250.1250',
        ));

        $this->assertFalse($result->wasAlreadyCreated);
        $this->assertSame(JournalStatus::Draft, $result->journal->status);
        $this->assertSame('1250.1250', $result->totalDebit);
        $this->assertSame('1250.1250', $result->totalCredit);
        $this->assertSame('PURCHASE-2026-000001', $result->journal->voucher_no);
        $this->assertSame(2, $result->journal->lines->count());
        $this->assertDatabaseHas('journal_entries', [
            'id' => $result->journal->id,
            'posting_key' => 'purchase_receipt:1',
            'source_type' => 'purchase_receipt',
            'source_id' => 1,
            'status' => 'DRAFT',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $result->journal->id,
            'account_id' => $inventory->id,
            'branch_id' => $branch->id,
            'debit' => 1250.1250,
            'credit' => 0,
        ]);
    }

    public function test_retry_with_same_source_and_data_returns_existing_draft_without_advancing_sequence(): void
    {
        [$branch, $inventory, $payable] = $this->foundation();
        $service = app(JournalDraftService::class);
        $draft = $this->draft($branch, $inventory, $payable);

        $first = $service->create($draft);
        $retry = $service->create($draft);

        $this->assertTrue($retry->wasAlreadyCreated);
        $this->assertSame($first->journal->id, $retry->journal->id);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->assertSame(1, (int) \DB::table('number_sequences')->value('current_number'));
    }

    public function test_unbalanced_or_invalid_sided_lines_are_rejected_atomically(): void
    {
        [$branch, $inventory, $payable] = $this->foundation();
        $service = app(JournalDraftService::class);

        try {
            $service->create(new JournalDraftData(
                voucherTypeCode: 'PURCHASE',
                branchId: $branch->id,
                entryDate: '2026-11-02',
                sourceType: 'purchase_receipt',
                sourceId: 1,
                postingKey: 'purchase_receipt:1',
                lines: [
                    new JournalLineData($inventory->id, debit: 100),
                    new JournalLineData($payable->id, credit: 99),
                ],
            ));
            $this->fail('An unbalanced journal must be rejected.');
        } catch (InvalidJournalDraftException $exception) {
            $this->assertStringContainsString('not balanced', $exception->getMessage());
        }

        $this->expectException(InvalidJournalDraftException::class);
        $this->expectExceptionMessage('either a debit or a credit');
        $service->create(new JournalDraftData(
            voucherTypeCode: 'PURCHASE',
            branchId: $branch->id,
            entryDate: '2026-11-02',
            sourceType: 'purchase_receipt',
            sourceId: 2,
            postingKey: 'purchase_receipt:2',
            lines: [
                new JournalLineData($inventory->id, debit: 100, credit: 1),
                new JournalLineData($payable->id, credit: 100),
            ],
        ));
    }

    public function test_source_or_posting_key_cannot_be_reused_with_different_data(): void
    {
        [$branch, $inventory, $payable] = $this->foundation();
        $service = app(JournalDraftService::class);
        $service->create($this->draft($branch, $inventory, $payable));

        try {
            $service->create($this->draft($branch, $inventory, $payable, amount: 200));
            $this->fail('A posting key must not accept different journal data.');
        } catch (DuplicateJournalDraftException $exception) {
            $this->assertStringContainsString('different draft data', $exception->getMessage());
        }

        $this->expectException(DuplicateJournalDraftException::class);
        $service->create($this->draft(
            $branch,
            $inventory,
            $payable,
            postingKey: 'purchase_receipt:changed-key',
        ));
    }

    public function test_accounts_must_be_active_bdt_and_assigned_to_the_branch(): void
    {
        [$branch, , $payable] = $this->foundation();
        $assetType = AccountType::query()->where('code', 'ASSET')->firstOrFail();
        $unassigned = Account::query()->create([
            'account_type_id' => $assetType->id,
            'name' => 'Unassigned Inventory',
            'code' => '1190',
            'currency' => 'BDT',
            'is_active' => true,
        ]);

        $this->expectException(FinanceConfigurationException::class);
        $this->expectExceptionMessage('not assigned');
        app(JournalDraftService::class)->create($this->draft($branch, $unassigned, $payable));
    }

    public function test_exactly_one_active_fiscal_year_must_cover_the_journal_date(): void
    {
        [$branch, $inventory, $payable] = $this->foundation(createFiscalYear: false);

        $this->expectException(FinanceConfigurationException::class);
        $this->expectExceptionMessage('Exactly one active fiscal year');
        app(JournalDraftService::class)->create($this->draft($branch, $inventory, $payable));
    }

    /** @return array{Branch, Account, Account} */
    private function foundation(bool $createFiscalYear = true): array
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => true]);
        $this->seed(FinanceFoundationSeeder::class);
        $this->seed(VoucherTypeSeeder::class);
        if ($createFiscalYear) {
            FiscalYear::query()->create([
                'name' => 'FY 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'is_active' => true,
            ]);
        }

        return [
            $branch,
            Account::query()->where('code', '1100')->firstOrFail(),
            Account::query()->where('code', '2000')->firstOrFail(),
        ];
    }

    private function draft(
        Branch $branch,
        Account $inventory,
        Account $payable,
        int|float|string $amount = 100,
        string $postingKey = 'purchase_receipt:1',
    ): JournalDraftData {
        return new JournalDraftData(
            voucherTypeCode: 'PURCHASE',
            branchId: $branch->id,
            entryDate: '2026-11-02',
            sourceType: 'purchase_receipt',
            sourceId: 1,
            postingKey: $postingKey,
            lines: [
                new JournalLineData($inventory->id, debit: $amount, memo: 'Inventory received'),
                new JournalLineData($payable->id, credit: $amount, memo: 'Supplier payable'),
            ],
            narration: 'Purchase Receipt PR-0001',
        );
    }
}
