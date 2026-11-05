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
    public function __construct(private readonly JournalDraftService $journals) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): CapitalWithdrawal
    {
        return DB::transaction(function () use ($data, $userId): CapitalWithdrawal {
            $branchId = (int) $data['branch_id'];
            $date = $this->date($data['withdrawal_date'] ?? null);
            $year = $this->activeYearFor($date);
            $this->assertAccounts($branchId, (int) $data['cash_bank_account_id'], (int) $data['drawings_account_id']);

            return CapitalWithdrawal::query()->create(['withdrawal_no' => $this->nextNumber($branchId, $year->id, $date), 'branch_id' => $branchId, 'withdrawal_type' => $this->type($data['withdrawal_type'] ?? ''), 'cash_bank_account_id' => (int) $data['cash_bank_account_id'], 'drawings_account_id' => (int) $data['drawings_account_id'], 'withdrawal_date' => $date, 'amount' => $this->amount($data['amount'] ?? null), 'reference_no' => $this->text($data['reference_no'] ?? null, 100), 'note' => $this->text($data['note'] ?? null, 5000), 'status' => CapitalWithdrawalStatus::Draft, 'created_by' => $userId]);
        }, 3);
    }

    public function post(CapitalWithdrawal $withdrawal, int $userId): CapitalWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $userId): CapitalWithdrawal {
            $withdrawal = CapitalWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === CapitalWithdrawalStatus::Posted) {
                return $withdrawal;
            }
            if ($withdrawal->status === CapitalWithdrawalStatus::Void) {
                throw new InvalidCapitalWithdrawalException('A void withdrawal cannot be posted.');
            }
            $this->activeYearFor($withdrawal->withdrawal_date->toDateString());
            $this->assertAccounts((int) $withdrawal->branch_id, (int) $withdrawal->cash_bank_account_id, (int) $withdrawal->drawings_account_id);
            $this->journals->create(new JournalDraftData(voucherTypeCode: 'PAYMENT', branchId: (int) $withdrawal->branch_id, entryDate: $withdrawal->withdrawal_date, sourceType: 'capital_withdrawal', sourceId: (int) $withdrawal->id, postingKey: 'journal:capital_withdrawal:'.$withdrawal->id, lines: [new JournalLineData(accountId: (int) $withdrawal->drawings_account_id, debit: (string) $withdrawal->amount, memo: $this->label($withdrawal->withdrawal_type).' drawings debited'), new JournalLineData(accountId: (int) $withdrawal->cash_bank_account_id, credit: (string) $withdrawal->amount, memo: 'Cash / Bank withdrawn')], narration: $this->label($withdrawal->withdrawal_type).' '.$withdrawal->withdrawal_no, createdBy: $userId));
            $withdrawal->update(['status' => CapitalWithdrawalStatus::Posted, 'posted_by' => $userId, 'posted_at' => now()]);

            return $withdrawal->fresh();
        }, 3);
    }

    public function voidDraft(CapitalWithdrawal $withdrawal, string $reason, int $userId): CapitalWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $reason, $userId): CapitalWithdrawal {
            $withdrawal = CapitalWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($withdrawal->status === CapitalWithdrawalStatus::Void) {
                return $withdrawal;
            }
            if ($withdrawal->status !== CapitalWithdrawalStatus::Draft) {
                throw new InvalidCapitalWithdrawalException('Only a Draft withdrawal can be voided.');
            }
            $reason = $this->text($reason, 1000);
            if ($reason === null) {
                throw new InvalidCapitalWithdrawalException('A void reason is required.');
            }
            $withdrawal->update(['status' => CapitalWithdrawalStatus::Void, 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);

            return $withdrawal->fresh();
        }, 3);
    }

    private function assertAccounts(int $branchId, int $cashId, int $drawingsId): void
    {
        if (! Branch::query()->whereKey($branchId)->where('is_active', true)->exists()) {
            throw new FinanceConfigurationException('Choose an active branch.');
        }
        if ($cashId === $drawingsId) {
            throw new InvalidCapitalWithdrawalException('Cash / Bank and Drawings accounts must be different.');
        }
        $accounts = Account::query()->with('type')->whereIn('id', [$cashId, $drawingsId])->get()->keyBy('id');
        $cash = $accounts->get($cashId);
        $drawings = $accounts->get($drawingsId);
        if (! $cash || ! $cash->is_active || ! $cash->is_cash_bank_account || $cash->type?->code !== 'ASSET') {
            throw new InvalidCapitalWithdrawalException('Choose an active Cash / Bank Asset account.');
        }
        if (! $drawings || ! $drawings->is_active || $drawings->type?->code !== 'EQUITY') {
            throw new InvalidCapitalWithdrawalException('Choose an active Owner / Partner Drawings Equity account.');
        }
        $assigned = BranchAccount::query()->where('branch_id', $branchId)->where('is_active', true)->whereIn('account_id', [$cashId, $drawingsId])->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
        if (! in_array($cashId, $assigned, true) || ! in_array($drawingsId, $assigned, true)) {
            throw new FinanceConfigurationException('Cash / Bank and Drawings accounts must both be assigned to the selected branch.');
        }
    }

    private function activeYearFor(string $date): FiscalYear
    {
        $year = FiscalYear::query()->where('is_active', true)->first();
        if (! $year || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw new FinanceConfigurationException('Withdrawal date must be inside the active fiscal year.');
        }

return $year;
    }

    private function nextNumber(int $branchId, int $yearId, string $date): string
    {
        $time = now();
        DB::table('number_sequences')->insertOrIgnore(['key_name' => 'CAPITAL_WITHDRAWAL', 'branch_id' => $branchId, 'fiscal_year_id' => $yearId, 'current_number' => 0, 'created_at' => $time, 'updated_at' => $time]);
        $row = DB::table('number_sequences')->where('key_name', 'CAPITAL_WITHDRAWAL')->where('branch_id', $branchId)->where('fiscal_year_id', $yearId)->lockForUpdate()->first();
        if (! $row) {
            throw new FinanceConfigurationException('Unable to prepare the withdrawal number sequence.');
        } $number = (int) $row->current_number + 1;
        DB::table('number_sequences')->where('id', $row->id)->update(['current_number' => $number, 'updated_at' => $time]);

        return sprintf('CW-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function type(mixed $type): string
    {
        $type = strtoupper(trim((string) $type));
        if (! in_array($type, ['OWNER_WITHDRAWAL', 'PARTNER_WITHDRAWAL'], true)) {
            throw new InvalidCapitalWithdrawalException('Choose Owner Withdrawal or Partner Withdrawal.');
        }

return $type;
    }

    private function label(string $type): string
    {
        return $type === 'PARTNER_WITHDRAWAL' ? 'Partner Withdrawal' : 'Owner Withdrawal';
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
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || (float) $value <= 0) {
            throw new InvalidCapitalWithdrawalException('Withdrawal amount must be greater than zero and may use up to four decimal places.');
        } [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        } if (mb_strlen($value) > $limit) {
            throw new InvalidCapitalWithdrawalException("Text may not exceed {$limit} characters.");
        }

return $value;
    }
}
