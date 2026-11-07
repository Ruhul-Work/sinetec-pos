<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\backend\Account;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\Finance\CashBankTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CashBankTransferService
{
    public function __construct(private readonly JournalDraftService $journals) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): CashBankTransfer
    {
        return DB::transaction(function () use ($data, $userId): CashBankTransfer {
            $transferDate = CarbonImmutable::parse((string) $data['transfer_date'])->toDateString();
            $fiscalYear = $this->activeFiscalYearFor($transferDate);
            $branchId = (int) $data['branch_id'];
            $fromAccountId = (int) $data['from_account_id'];
            $toAccountId = (int) $data['to_account_id'];

            $this->assertTransferAccounts($branchId, $fromAccountId, $toAccountId);

            return CashBankTransfer::query()->create([
                'transfer_no' => $this->nextNumber($branchId, $fiscalYear->id, $transferDate),
                'branch_id' => $branchId,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'transfer_date' => $transferDate,
                'amount' => $this->amount($data['amount'] ?? null),
                'reference_no' => $this->nullableText($data['reference_no'] ?? null, 100),
                'note' => $this->nullableText($data['note'] ?? null, 5000),
                'created_by' => $userId,
            ]);
        }, 3);
    }

    public function post(CashBankTransfer $transfer, int $userId): CashBankTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): CashBankTransfer {
            $transfer = CashBankTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($transfer->status === 'POSTED') {
                return $transfer;
            }
            if ($transfer->status === 'VOID') {
                throw new InvalidJournalDraftException('A void Cash / Bank Transfer cannot be posted.');
            }

            $this->activeFiscalYearFor($transfer->transfer_date->toDateString());
            $this->assertTransferAccounts(
                (int) $transfer->branch_id,
                (int) $transfer->from_account_id,
                (int) $transfer->to_account_id,
            );

            $this->journals->create(new JournalDraftData(
                voucherTypeCode: 'TRANSFER',
                branchId: (int) $transfer->branch_id,
                entryDate: $transfer->transfer_date,
                sourceType: 'cash_bank_transfer',
                sourceId: (int) $transfer->id,
                postingKey: 'journal:cash_bank_transfer:'.$transfer->id,
                lines: [
                    new JournalLineData(
                        accountId: (int) $transfer->to_account_id,
                        debit: (string) $transfer->amount,
                        memo: 'Cash / Bank received',
                    ),
                    new JournalLineData(
                        accountId: (int) $transfer->from_account_id,
                        credit: (string) $transfer->amount,
                        memo: 'Cash / Bank transferred out',
                    ),
                ],
                narration: 'Cash / Bank Transfer '.$transfer->transfer_no,
                createdBy: $userId,
            ));

            $transfer->update([
                'status' => 'POSTED',
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $transfer->fresh();
        }, 3);
    }

    private function assertTransferAccounts(int $branchId, int $fromAccountId, int $toAccountId): void
    {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidJournalDraftException('Source and destination Cash / Bank accounts must be different.');
        }

        $accounts = Account::query()
            ->with('type')
            ->whereIn('id', [$fromAccountId, $toAccountId])
            ->get()
            ->keyBy('id');
        $assignedAccountIds = BranchAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereIn('account_id', [$fromAccountId, $toAccountId])
            ->pluck('account_id')
            ->map(fn ($accountId): int => (int) $accountId)
            ->all();

        foreach ([$fromAccountId, $toAccountId] as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account
                || ! $account->is_active
                || ! $account->is_cash_bank_account
                || $account->type?->code !== 'ASSET'
                || ! in_array($accountId, $assignedAccountIds, true)) {
                throw new FinanceConfigurationException(
                    'Both transfer accounts must be active Cash / Bank Asset accounts assigned to the selected branch.',
                );
            }
        }
    }

    private function activeFiscalYearFor(string $date): FiscalYear
    {
        $years = FiscalYear::query()->where('is_active', true)->get();
        if ($years->count() !== 1) {
            throw new FinanceConfigurationException('Exactly one active fiscal year is required.');
        }

        $year = $years->first();
        if ($date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw new FinanceConfigurationException('Transfer date must be inside the active fiscal year.');
        }

        return $year;
    }

    private function nextNumber(int $branchId, int $fiscalYearId, string $date): string
    {
        $timestamp = now();
        DB::table('number_sequences')->insertOrIgnore([
            'key_name' => 'CASH_BANK_TRANSFER',
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'current_number' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $sequence = DB::table('number_sequences')
            ->where('key_name', 'CASH_BANK_TRANSFER')
            ->where('branch_id', $branchId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->first();
        if (! $sequence) {
            throw new FinanceConfigurationException('Unable to prepare the Cash / Bank Transfer number sequence.');
        }

        $number = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update([
            'current_number' => $number,
            'updated_at' => $timestamp,
        ]);

        return sprintf('CBT-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function amount(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || $this->isZero($value)) {
            throw new InvalidJournalDraftException(
                'Transfer amount must be greater than zero and may use up to four decimal places.',
            );
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
    }

    private function isZero(string $amount): bool
    {
        return trim($amount, '0.') === '';
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            throw new InvalidJournalDraftException("Text may not exceed {$limit} characters.");
        }

        return $value;
    }
}
