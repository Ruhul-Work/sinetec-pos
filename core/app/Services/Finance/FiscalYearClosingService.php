<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\FiscalYearClosingStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidFiscalYearClosingException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\Finance\FiscalYearClosing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FiscalYearClosingService
{
    public function __construct(private readonly JournalDraftService $journals) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): FiscalYearClosing
    {
        return DB::transaction(function () use ($data, $userId): FiscalYearClosing {
            $year = $this->activeYear((int) $data['fiscal_year_id']);
            $branchId = (int) $data['branch_id'];
            $drawingsAccountId = (int) $data['drawings_account_id'];
            $capitalAccountId = (int) $data['capital_account_id'];
            $this->assertClosingDate($year, (string) $data['closing_date']);
            $this->assertAccounts($branchId, $drawingsAccountId, $capitalAccountId);
            $this->assertNoExistingOpenClosing($year->id, $branchId, $drawingsAccountId);

            $amount = $this->drawingsBalance($year->id, $branchId, $drawingsAccountId);
            if ($amount <= 0.00005) {
                throw new InvalidFiscalYearClosingException('The selected Drawings account has no debit balance to close for this fiscal year.');
            }

            return FiscalYearClosing::query()->create([
                'closing_no' => $this->nextNumber($branchId, $year->id, $year->end_date->toDateString()),
                'fiscal_year_id' => $year->id,
                'branch_id' => $branchId,
                'drawings_account_id' => $drawingsAccountId,
                'capital_account_id' => $capitalAccountId,
                'closing_date' => $year->end_date->toDateString(),
                'amount' => number_format($amount, 4, '.', ''),
                'reference_no' => $this->text($data['reference_no'] ?? null, 100),
                'note' => $this->text($data['note'] ?? null, 5000),
                'status' => FiscalYearClosingStatus::Draft,
                'created_by' => $userId,
            ]);
        }, 3);
    }

    public function post(FiscalYearClosing $closing, int $userId): FiscalYearClosing
    {
        return DB::transaction(function () use ($closing, $userId): FiscalYearClosing {
            $closing = FiscalYearClosing::query()->whereKey($closing->id)->lockForUpdate()->firstOrFail();
            if ($closing->status === FiscalYearClosingStatus::Posted) {
                return $closing;
            }
            if ($closing->status === FiscalYearClosingStatus::Void) {
                throw new InvalidFiscalYearClosingException('A void fiscal year closing cannot be prepared.');
            }

            $year = $this->activeYear((int) $closing->fiscal_year_id);
            $this->assertClosingDate($year, $closing->closing_date->toDateString());
            $this->assertAccounts((int) $closing->branch_id, (int) $closing->drawings_account_id, (int) $closing->capital_account_id);
            $this->assertNoPendingJournals($year->id, (int) $closing->branch_id);

            $currentAmount = $this->drawingsBalance($year->id, (int) $closing->branch_id, (int) $closing->drawings_account_id);
            if (abs($currentAmount - (float) $closing->amount) > 0.00005) {
                throw new InvalidFiscalYearClosingException('The Drawings balance changed after this Draft was created. Void it and prepare a fresh closing.');
            }

            $this->journals->create(new JournalDraftData(
                voucherTypeCode: 'PAYMENT',
                branchId: (int) $closing->branch_id,
                entryDate: $closing->closing_date,
                sourceType: 'fiscal_year_closing',
                sourceId: (int) $closing->id,
                postingKey: 'journal:fiscal_year_closing:'.$closing->id,
                lines: [
                    new JournalLineData(accountId: (int) $closing->capital_account_id, debit: (string) $closing->amount, memo: 'Year-end Drawings adjustment'),
                    new JournalLineData(accountId: (int) $closing->drawings_account_id, credit: (string) $closing->amount, memo: 'Close Drawings to Capital'),
                ],
                narration: 'Fiscal year closing '.$closing->closing_no,
                createdBy: $userId,
            ));

            $closing->update([
                'status' => FiscalYearClosingStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $closing->fresh();
        }, 3);
    }

    public function voidDraft(FiscalYearClosing $closing, string $reason, int $userId): FiscalYearClosing
    {
        return DB::transaction(function () use ($closing, $reason, $userId): FiscalYearClosing {
            $closing = FiscalYearClosing::query()->whereKey($closing->id)->lockForUpdate()->firstOrFail();
            if ($closing->status === FiscalYearClosingStatus::Void) {
                return $closing;
            }
            if ($closing->status !== FiscalYearClosingStatus::Draft) {
                throw new InvalidFiscalYearClosingException('Only a Draft fiscal year closing can be voided.');
            }

            $reason = $this->text($reason, 1000);
            if ($reason === null) {
                throw new InvalidFiscalYearClosingException('A void reason is required.');
            }
            $closing->update([
                'status' => FiscalYearClosingStatus::Void,
                'voided_by' => $userId,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $closing->fresh();
        }, 3);
    }

    public function drawingsBalance(int $fiscalYearId, int $branchId, int $accountId): float
    {
        $opening = DB::table('account_opening_balances as opening')
            ->where('opening.status', 'POSTED')
            ->where('opening.fiscal_year_id', $fiscalYearId)
            ->where('opening.branch_id', $branchId)
            ->where('opening.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(opening.debit), 0) as debit, COALESCE(SUM(opening.credit), 0) as credit');

        $journals = DB::table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('journal.status', 'POSTED')
            ->where('journal.fiscal_year_id', $fiscalYearId)
            ->where('line.branch_id', $branchId)
            ->where('line.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(line.debit), 0) as debit, COALESCE(SUM(line.credit), 0) as credit');

        $totals = DB::query()
            ->fromSub($opening->unionAll($journals), 'drawings_balance')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return (float) $totals->debit - (float) $totals->credit;
    }

    private function activeYear(int $yearId): FiscalYear
    {
        $year = FiscalYear::query()->whereKey($yearId)->where('is_active', true)->lockForUpdate()->first();
        if (! $year) {
            throw new FinanceConfigurationException('Choose the active fiscal year before preparing its closing.');
        }

        return $year;
    }

    private function assertClosingDate(FiscalYear $year, string $date): void
    {
        if ($date !== $year->end_date->toDateString()) {
            throw new InvalidFiscalYearClosingException('Fiscal Year Closing date must be the fiscal year end date.');
        }
        if ($date > now()->toDateString()) {
            throw new InvalidFiscalYearClosingException('Fiscal Year Closing can be prepared only on or after the fiscal year end date. Use a separate test database for an early workflow test.');
        }
    }

    private function assertAccounts(int $branchId, int $drawingsAccountId, int $capitalAccountId): void
    {
        if (! Branch::query()->whereKey($branchId)->where('is_active', true)->exists()) {
            throw new FinanceConfigurationException('Choose an active branch.');
        }
        if ($drawingsAccountId === $capitalAccountId) {
            throw new InvalidFiscalYearClosingException('Drawings and Capital accounts must be different.');
        }
        $accounts = Account::query()->with('type')->whereIn('id', [$drawingsAccountId, $capitalAccountId])->get()->keyBy('id');
        $drawings = $accounts->get($drawingsAccountId);
        $capital = $accounts->get($capitalAccountId);
        if (! $drawings || ! $drawings->is_active || $drawings->type?->code !== 'EQUITY') {
            throw new InvalidFiscalYearClosingException('Choose an active Equity Drawings account.');
        }
        if (! $capital || ! $capital->is_active || $capital->type?->code !== 'EQUITY') {
            throw new InvalidFiscalYearClosingException('Choose an active Equity Capital account.');
        }
        $assigned = BranchAccount::query()->where('branch_id', $branchId)->where('is_active', true)->whereIn('account_id', [$drawingsAccountId, $capitalAccountId])->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
        if (! in_array($drawingsAccountId, $assigned, true) || ! in_array($capitalAccountId, $assigned, true)) {
            throw new FinanceConfigurationException('Drawings and Capital accounts must both be assigned to the selected branch.');
        }
    }

    private function assertNoExistingOpenClosing(int $yearId, int $branchId, int $drawingsAccountId): void
    {
        if (FiscalYearClosing::query()->where('fiscal_year_id', $yearId)->where('branch_id', $branchId)->where('drawings_account_id', $drawingsAccountId)->whereIn('status', [FiscalYearClosingStatus::Draft, FiscalYearClosingStatus::Posted])->exists()) {
            throw new InvalidFiscalYearClosingException('A Draft or prepared closing already exists for this Drawings account in this fiscal year and branch.');
        }
    }

    private function assertNoPendingJournals(int $yearId, int $branchId): void
    {
        if (DB::table('journal_entries')->where('fiscal_year_id', $yearId)->where('branch_id', $branchId)->where('status', 'DRAFT')->exists()) {
            throw new InvalidFiscalYearClosingException('Post or void all Draft journals for this branch and fiscal year before preparing year closing.');
        }
    }

    private function nextNumber(int $branchId, int $yearId, string $date): string
    {
        $now = now();
        DB::table('number_sequences')->insertOrIgnore(['key_name' => 'FISCAL_YEAR_CLOSING', 'branch_id' => $branchId, 'fiscal_year_id' => $yearId, 'current_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $sequence = DB::table('number_sequences')->where('key_name', 'FISCAL_YEAR_CLOSING')->where('branch_id', $branchId)->where('fiscal_year_id', $yearId)->lockForUpdate()->first();
        if (! $sequence) {
            throw new FinanceConfigurationException('Unable to prepare the fiscal year closing number sequence.');
        }
        $number = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['current_number' => $number, 'updated_at' => $now]);

        return sprintf('FYC-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            throw new InvalidFiscalYearClosingException("Text may not exceed {$limit} characters.");
        }

        return $value;
    }
}
