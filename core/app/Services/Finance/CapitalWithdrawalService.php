<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\CapitalWithdrawalStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidCapitalWithdrawalException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\Finance\CapitalWithdrawal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CapitalWithdrawalService
{
    public function __construct(
        private readonly JournalDraftService $journals,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): CapitalWithdrawal
    {
        return DB::transaction(function () use ($data, $userId): CapitalWithdrawal {
            $branchId = (int) $data['branch_id'];
            $withdrawalDate = $this->date($data['withdrawal_date'] ?? null);
            $fiscalYear = $this->activeYearFor($withdrawalDate);
            $cashBankAccountId = (int) $data['cash_bank_account_id'];
            $drawingsAccountId = (int) $data['drawings_account_id'];

            $this->assertAccounts(
                branchId: $branchId,
                cashBankAccountId: $cashBankAccountId,
                drawingsAccountId: $drawingsAccountId,
            );

            return CapitalWithdrawal::query()->create([
                'withdrawal_no' => $this->nextNumber($branchId, $fiscalYear->id, $withdrawalDate),
                'branch_id' => $branchId,
                'withdrawal_type' => $this->type($data['withdrawal_type'] ?? ''),
                'cash_bank_account_id' => $cashBankAccountId,
                'drawings_account_id' => $drawingsAccountId,
                'withdrawal_date' => $withdrawalDate,
                'amount' => $this->amount($data['amount'] ?? null),
                'reference_no' => $this->text($data['reference_no'] ?? null, 100),
                'note' => $this->text($data['note'] ?? null, 5000),
                'status' => CapitalWithdrawalStatus::Draft,
                'created_by' => $userId,
            ]);
        }, 3);
    }

    public function post(CapitalWithdrawal $withdrawal, int $userId): CapitalWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $userId): CapitalWithdrawal {
            $withdrawal = CapitalWithdrawal::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status === CapitalWithdrawalStatus::Posted) {
                return $withdrawal;
            }

            if ($withdrawal->status === CapitalWithdrawalStatus::Void) {
                throw new InvalidCapitalWithdrawalException('A void withdrawal cannot be posted.');
            }

            $this->activeYearFor($withdrawal->withdrawal_date->toDateString());
            $this->assertAccounts(
                branchId: (int) $withdrawal->branch_id,
                cashBankAccountId: (int) $withdrawal->cash_bank_account_id,
                drawingsAccountId: (int) $withdrawal->drawings_account_id,
            );

            $this->createJournalDraft($withdrawal, $userId);

            $withdrawal->update([
                'status' => CapitalWithdrawalStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $withdrawal->fresh();
        }, 3);
    }

    public function voidDraft(
        CapitalWithdrawal $withdrawal,
        string $reason,
        int $userId,
    ): CapitalWithdrawal {
        return DB::transaction(function () use ($withdrawal, $reason, $userId): CapitalWithdrawal {
            $withdrawal = CapitalWithdrawal::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status === CapitalWithdrawalStatus::Void) {
                return $withdrawal;
            }

            if ($withdrawal->status !== CapitalWithdrawalStatus::Draft) {
                throw new InvalidCapitalWithdrawalException('Only a Draft withdrawal can be voided.');
            }

            $voidReason = $this->text($reason, 1000);
            if ($voidReason === null) {
                throw new InvalidCapitalWithdrawalException('A void reason is required.');
            }

            $withdrawal->update([
                'status' => CapitalWithdrawalStatus::Void,
                'voided_by' => $userId,
                'voided_at' => now(),
                'void_reason' => $voidReason,
            ]);

            return $withdrawal->fresh();
        }, 3);
    }

    private function createJournalDraft(CapitalWithdrawal $withdrawal, int $userId): void
    {
        $withdrawalLabel = $this->label($withdrawal->withdrawal_type);

        $this->journals->create(new JournalDraftData(
            voucherTypeCode: 'PAYMENT',
            branchId: (int) $withdrawal->branch_id,
            entryDate: $withdrawal->withdrawal_date,
            sourceType: 'capital_withdrawal',
            sourceId: (int) $withdrawal->id,
            postingKey: 'journal:capital_withdrawal:'.$withdrawal->id,
            lines: [
                new JournalLineData(
                    accountId: (int) $withdrawal->drawings_account_id,
                    debit: (string) $withdrawal->amount,
                    memo: $withdrawalLabel.' drawings debited',
                ),
                new JournalLineData(
                    accountId: (int) $withdrawal->cash_bank_account_id,
                    credit: (string) $withdrawal->amount,
                    memo: 'Cash / Bank withdrawn',
                ),
            ],
            narration: $withdrawalLabel.' '.$withdrawal->withdrawal_no,
            createdBy: $userId,
        ));
    }

    private function assertAccounts(
        int $branchId,
        int $cashBankAccountId,
        int $drawingsAccountId,
    ): void {
        $branchExists = Branch::query()
            ->whereKey($branchId)
            ->where('is_active', true)
            ->exists();

        if (! $branchExists) {
            throw new FinanceConfigurationException('Choose an active branch.');
        }

        if ($cashBankAccountId === $drawingsAccountId) {
            throw new InvalidCapitalWithdrawalException(
                'Cash / Bank and Drawings accounts must be different.',
            );
        }

        $accounts = Account::query()
            ->with('type')
            ->whereIn('id', [$cashBankAccountId, $drawingsAccountId])
            ->get()
            ->keyBy('id');

        $cashBankAccount = $accounts->get($cashBankAccountId);
        $drawingsAccount = $accounts->get($drawingsAccountId);

        $cashBankAccountIsValid = $cashBankAccount
            && $cashBankAccount->is_active
            && $cashBankAccount->is_cash_bank_account
            && $cashBankAccount->type?->code === 'ASSET';

        if (! $cashBankAccountIsValid) {
            throw new InvalidCapitalWithdrawalException(
                'Choose an active Cash / Bank Asset account.',
            );
        }

        $drawingsAccountIsValid = $drawingsAccount
            && $drawingsAccount->is_active
            && $drawingsAccount->type?->code === 'EQUITY';

        if (! $drawingsAccountIsValid) {
            throw new InvalidCapitalWithdrawalException(
                'Choose an active Owner / Partner Drawings Equity account.',
            );
        }

        $assignedAccountIds = BranchAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereIn('account_id', [$cashBankAccountId, $drawingsAccountId])
            ->pluck('account_id')
            ->map(fn ($accountId): int => (int) $accountId)
            ->all();

        $cashAccountIsAssigned = in_array($cashBankAccountId, $assignedAccountIds, true);
        $drawingsAccountIsAssigned = in_array($drawingsAccountId, $assignedAccountIds, true);

        if (! $cashAccountIsAssigned || ! $drawingsAccountIsAssigned) {
            throw new FinanceConfigurationException(
                'Cash / Bank and Drawings accounts must both be assigned to the selected branch.',
            );
        }
    }

    private function activeYearFor(string $date): FiscalYear
    {
        $fiscalYear = FiscalYear::query()
            ->where('is_active', true)
            ->first();

        $dateIsOutsideFiscalYear = ! $fiscalYear
            || $date < $fiscalYear->start_date->toDateString()
            || $date > $fiscalYear->end_date->toDateString();

        if ($dateIsOutsideFiscalYear) {
            throw new FinanceConfigurationException(
                'Withdrawal date must be inside the active fiscal year.',
            );
        }

        return $fiscalYear;
    }

    private function nextNumber(int $branchId, int $fiscalYearId, string $date): string
    {
        $timestamp = now();

        DB::table('number_sequences')->insertOrIgnore([
            'key_name' => 'CAPITAL_WITHDRAWAL',
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'current_number' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $sequence = DB::table('number_sequences')
            ->where('key_name', 'CAPITAL_WITHDRAWAL')
            ->where('branch_id', $branchId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new FinanceConfigurationException(
                'Unable to prepare the withdrawal number sequence.',
            );
        }

        $number = (int) $sequence->current_number + 1;

        DB::table('number_sequences')
            ->where('id', $sequence->id)
            ->update([
                'current_number' => $number,
                'updated_at' => $timestamp,
            ]);

        return sprintf('CW-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function type(mixed $type): string
    {
        $normalizedType = strtoupper(trim((string) $type));
        $allowedTypes = ['OWNER_WITHDRAWAL', 'PARTNER_WITHDRAWAL'];

        if (! in_array($normalizedType, $allowedTypes, true)) {
            throw new InvalidCapitalWithdrawalException(
                'Choose Owner Withdrawal or Partner Withdrawal.',
            );
        }

        return $normalizedType;
    }

    private function label(string $type): string
    {
        return $type === 'PARTNER_WITHDRAWAL'
            ? 'Partner Withdrawal'
            : 'Owner Withdrawal';
    }

    private function date(mixed $date): string
    {
        try {
            return CarbonImmutable::parse((string) $date)->toDateString();
        } catch (Throwable) {
            throw new InvalidCapitalWithdrawalException('Withdrawal date is invalid.');
        }
    }

    private function amount(mixed $amount): string
    {
        $value = trim((string) $amount);
        $isValidDecimal = preg_match('/^\d+(?:\.\d{1,4})?$/', $value) === 1;

        if (! $isValidDecimal || (float) $value <= 0) {
            throw new InvalidCapitalWithdrawalException(
                'Withdrawal amount must be greater than zero and may use up to four decimal places.',
            );
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalizedInteger = ltrim($integer, '0') ?: '0';

        return $normalizedInteger.'.'.str_pad($fraction, 4, '0');
    }

    private function text(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $limit) {
            throw new InvalidCapitalWithdrawalException(
                "Text may not exceed {$limit} characters.",
            );
        }

        return $text;
    }
}
