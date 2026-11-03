<?php

namespace Tests\Feature;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\JournalStatus;
use App\Exceptions\Finance\JournalWorkflowException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\User;
use App\Services\Finance\JournalDraftService;
use App\Services\Finance\JournalWorkflowService;
use Database\Seeders\FinanceFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_must_be_approved_before_posting_and_posting_is_idempotent(): void
    {
        [$branch, $user, $rawInventory, $payable] = $this->foundation();
        $journal = $this->draft($branch, $user, $rawInventory, $payable, 'journal:workflow:post');
        $workflow = app(JournalWorkflowService::class);

        try {
            $workflow->post($journal, $user->id);
            $this->fail('An unapproved draft must not post.');
        } catch (JournalWorkflowException $exception) {
            $this->assertStringContainsString('approval', strtolower($exception->getMessage()));
        }

        $approved = $workflow->approve($journal, $user->id);
        $this->assertSame(JournalStatus::Draft, $approved->status);
        $this->assertSame($user->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $posted = $workflow->post($approved, $user->id);
        $retry = $workflow->post($posted, $user->id);
        $this->assertSame(JournalStatus::Posted, $retry->status);
        $this->assertSame($user->id, $retry->posted_by);
        $this->assertNotNull($retry->posted_at);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_draft_can_be_voided_with_an_audit_reason_but_cannot_post(): void
    {
        [$branch, $user, $rawInventory, $payable] = $this->foundation();
        $journal = $this->draft($branch, $user, $rawInventory, $payable, 'journal:workflow:void');
        $workflow = app(JournalWorkflowService::class);

        $voided = $workflow->void($journal, 'Supplier invoice was entered twice.', $user->id);
        $this->assertSame(JournalStatus::Void, $voided->status);
        $this->assertSame($user->id, $voided->voided_by);
        $this->assertSame('Supplier invoice was entered twice.', $voided->void_reason);

        $this->expectException(JournalWorkflowException::class);
        $workflow->post($voided, $user->id);
    }

    public function test_posted_journal_creates_one_reversal_draft_with_swapped_lines(): void
    {
        [$branch, $user, $rawInventory, $payable] = $this->foundation();
        $workflow = app(JournalWorkflowService::class);
        $original = $this->draft($branch, $user, $rawInventory, $payable, 'journal:workflow:reverse');
        $original = $workflow->post($workflow->approve($original, $user->id), $user->id);

        $reversal = $workflow->createReversalDraft($original, '2026-11-03', 'Supplier credit note received.', $user->id);
        $retry = $workflow->createReversalDraft($original, '2026-11-03', 'A changed retry reason is ignored after reversal creation.', $user->id);

        $this->assertSame($reversal->id, $retry->id);
        $this->assertSame(JournalStatus::Draft, $reversal->status);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('journal_reversal', $reversal->source_type);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reversal->id,
            'account_id' => $rawInventory->id,
            'debit' => '0.0000',
            'credit' => '100.0000',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reversal->id,
            'account_id' => $payable->id,
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]);

        $reversal = $workflow->post($workflow->approve($reversal, $user->id), $user->id);
        $this->assertSame(JournalStatus::Posted, $reversal->status);
    }

    /** @return array{Branch, User, Account, Account} */
    private function foundation(): array
    {
        $branch = Branch::query()->create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $this->seed(FinanceFoundationSeeder::class);
        FiscalYear::query()->create([
            'name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Accountant', 'email' => 'accountant@example.test', 'password' => 'secret', 'branch_id' => $branch->id,
        ]);

        return [
            $branch,
            $user,
            Account::query()->where('code', '1100')->firstOrFail(),
            Account::query()->where('code', '2000')->firstOrFail(),
        ];
    }

    private function draft(Branch $branch, User $user, Account $rawInventory, Account $payable, string $postingKey): JournalEntry
    {
        return app(JournalDraftService::class)->create(new JournalDraftData(
            voucherTypeCode: 'PURCHASE',
            branchId: $branch->id,
            entryDate: '2026-11-02',
            sourceType: 'test_journal',
            sourceId: random_int(10000, 99999),
            postingKey: $postingKey,
            lines: [
                new JournalLineData($rawInventory->id, debit: '100.0000', memo: 'Inventory'),
                new JournalLineData($payable->id, credit: '100.0000', memo: 'Payable'),
            ],
            narration: 'Workflow test journal',
            createdBy: $user->id,
        ))->journal;
    }
}
