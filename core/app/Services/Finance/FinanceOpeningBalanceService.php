<?php

namespace App\Services\Finance;

use App\Exceptions\Finance\InvalidOpeningBalanceException;
use App\Models\backend\AccountOpeningBalance;
use App\Models\backend\Branch;
use App\Models\backend\FiscalYear;
use Illuminate\Support\Facades\DB;

final class FinanceOpeningBalanceService
{
    /** @param array<int, array{account_id: mixed, debit: mixed, credit: mixed}> $lines */
    public function saveDraft(int $fiscalYearId, int $branchId, array $lines, int $userId): void
    {
        DB::transaction(function () use ($fiscalYearId, $branchId, $lines, $userId): void {
            $this->scope($fiscalYearId, $branchId, true);
            $lines = $this->normalise($lines, $branchId);
            $existing = AccountOpeningBalance::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->get();
            if ($existing->contains(fn (AccountOpeningBalance $row): bool => $row->status === 'POSTED')) {
                throw new InvalidOpeningBalanceException('This opening balance set is already Posted and cannot be changed.');
            }

            AccountOpeningBalance::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('branch_id', $branchId)
                ->delete();

            AccountOpeningBalance::query()->insert(array_map(fn (array $line): array => [
                'fiscal_year_id' => $fiscalYearId,
                'branch_id' => $branchId,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'status' => 'DRAFT',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ], $lines));
        }, 3);
    }

    public function post(int $fiscalYearId, int $branchId, int $userId): void
    {
        DB::transaction(function () use ($fiscalYearId, $branchId, $userId): void {
            $this->scope($fiscalYearId, $branchId, true);
            $rows = AccountOpeningBalance::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                throw new InvalidOpeningBalanceException('Add balanced opening lines before posting.');
            }
            if ($rows->every(fn (AccountOpeningBalance $row): bool => $row->status === 'POSTED')) {
                return;
            }
            if ($rows->contains(fn (AccountOpeningBalance $row): bool => $row->status !== 'DRAFT')) {
                throw new InvalidOpeningBalanceException('Opening balance lines are in an invalid workflow state.');
            }

            $this->assertBalanced($rows->map(fn (AccountOpeningBalance $row): array => [
                'debit' => (string) $row->debit,
                'credit' => (string) $row->credit,
            ])->all());

            AccountOpeningBalance::query()
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('branch_id', $branchId)
                ->where('status', 'DRAFT')
                ->update(['status' => 'POSTED', 'posted_by' => $userId, 'posted_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    /**
     * @param  array<int, array{account_id: mixed, debit: mixed, credit: mixed}>  $lines
     * @return array<int, array{account_id: int, debit: string, credit: string}>
     */
    private function normalise(array $lines, int $branchId): array
    {
        $normalised = [];
        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            if ($accountId < 1) {
                continue;
            }
            if (array_key_exists($accountId, $normalised)) {
                throw new InvalidOpeningBalanceException('An account may appear only once in one opening balance set.');
            }
            $debit = $this->amount($line['debit'] ?? '0');
            $credit = $this->amount($line['credit'] ?? '0');
            if (($debit === '0.0000') === ($credit === '0.0000')) {
                throw new InvalidOpeningBalanceException('Every opening line requires exactly one Debit or Credit amount.');
            }
            $normalised[$accountId] = ['account_id' => $accountId, 'debit' => $debit, 'credit' => $credit];
        }
        if (count($normalised) < 2) {
            throw new InvalidOpeningBalanceException('At least two balanced opening lines are required.');
        }

        $allowed = DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->where('assignment.branch_id', $branchId)
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('account.currency', 'BDT')
            ->whereIn('account.id', array_keys($normalised))
            ->pluck('account.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        foreach (array_keys($normalised) as $accountId) {
            if (! in_array($accountId, $allowed, true)) {
                throw new InvalidOpeningBalanceException('Every opening account must be active, BDT and assigned to the selected branch.');
            }
        }

        $this->assertBalanced(array_values($normalised));

        return array_values($normalised);
    }

    private function scope(int $fiscalYearId, int $branchId, bool $lock): void
    {
        $year = FiscalYear::query()->whereKey($fiscalYearId)->where('is_active', true);
        $branch = Branch::query()->whereKey($branchId)->where('is_active', true);
        if ($lock) {
            $year->lockForUpdate();
            $branch->lockForUpdate();
        }
        if (! $year->first() || ! $branch->first()) {
            throw new InvalidOpeningBalanceException('Select an active fiscal year and active branch.');
        }
    }

    /** @param array<int, array{debit: string, credit: string}> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = '0';
        $credit = '0';
        foreach ($lines as $line) {
            $debit = $this->add($debit, $this->scaled($line['debit']));
            $credit = $this->add($credit, $this->scaled($line['credit']));
        }
        if ($debit !== $credit) {
            throw new InvalidOpeningBalanceException('Opening Balance is not balanced. Total Debit and Total Credit must be equal.');
        }
    }

    private function amount(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = '0';
        }
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new InvalidOpeningBalanceException('Opening Debit/Credit accepts a non-negative amount with up to four decimal places.');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
    }

    private function scaled(string $amount): string
    {
        return ltrim(str_replace('.', '', $amount), '0') ?: '0';
    }

    private function add(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $sum = '';
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $digit = $carry;
            $digit += $leftIndex >= 0 ? (int) $left[$leftIndex--] : 0;
            $digit += $rightIndex >= 0 ? (int) $right[$rightIndex--] : 0;
            $sum = ($digit % 10).$sum;
            $carry = intdiv($digit, 10);
        }

        return ltrim($sum, '0') ?: '0';
    }
}
