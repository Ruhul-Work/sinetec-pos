<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\backend\Account;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\Finance\LoanReceipt;
use App\Models\Finance\LoanRepayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LoanRepaymentService
{
    public function __construct(private readonly JournalDraftService $journals) {}

    public function due(LoanReceipt $receipt): float
    {
        return max(0, (float) $receipt->amount - (float) LoanRepayment::query()
            ->where('loan_receipt_id', $receipt->id)
            ->where('status', 'POSTED')
            ->sum('amount'));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): LoanRepayment
    {
        return DB::transaction(function () use ($data, $userId): LoanRepayment {
            $receipt = LoanReceipt::query()->whereKey($data['loan_receipt_id'])->where('status', 'POSTED')->lockForUpdate()->firstOrFail();
            $year = $this->activeYearFor((string) $data['repayment_date']);
            $amount = $this->amount($data['amount'] ?? null);
            if ((float) $amount > $this->due($receipt) + 0.00001) {
                throw new InvalidJournalDraftException('Repayment amount cannot exceed the loan’s current due.');
            }
            $this->assertCashAccount((int) $receipt->branch_id, (int) $data['cash_bank_account_id']);

            return LoanRepayment::query()->create([
                'repayment_no' => $this->nextNumber((int) $receipt->branch_id, $year->id, (string) $data['repayment_date']),
                'branch_id' => $receipt->branch_id,
                'loan_receipt_id' => $receipt->id,
                'cash_bank_account_id' => (int) $data['cash_bank_account_id'],
                'repayment_date' => CarbonImmutable::parse((string) $data['repayment_date'])->toDateString(),
                'amount' => $amount,
                'reference_no' => $this->text($data['reference_no'] ?? null, 100),
                'note' => $this->text($data['note'] ?? null, 5000),
                'created_by' => $userId,
            ]);
        }, 3);
    }

    public function post(LoanRepayment $repayment, int $userId): LoanRepayment
    {
        return DB::transaction(function () use ($repayment, $userId): LoanRepayment {
            $repayment = LoanRepayment::query()->whereKey($repayment->id)->lockForUpdate()->firstOrFail();
            if ($repayment->status === 'POSTED') {
                return $repayment;
            }
            $receipt = LoanReceipt::query()->whereKey($repayment->loan_receipt_id)->where('status', 'POSTED')->lockForUpdate()->firstOrFail();
            $this->activeYearFor($repayment->repayment_date->toDateString());
            if ((float) $repayment->amount > $this->due($receipt) + 0.00001) {
                throw new InvalidJournalDraftException('Loan due changed after this repayment Draft was created. Create a new repayment with the remaining due amount.');
            }
            $this->assertCashAccount((int) $repayment->branch_id, (int) $repayment->cash_bank_account_id);
            $this->journals->create(new JournalDraftData(
                voucherTypeCode: 'PAYMENT',
                branchId: (int) $repayment->branch_id,
                entryDate: $repayment->repayment_date,
                sourceType: 'loan_repayment',
                sourceId: (int) $repayment->id,
                postingKey: 'journal:loan_repayment:'.$repayment->id,
                lines: [
                    new JournalLineData(accountId: (int) $receipt->loan_account_id, debit: (string) $repayment->amount, memo: 'Loan liability repaid'),
                    new JournalLineData(accountId: (int) $repayment->cash_bank_account_id, credit: (string) $repayment->amount, memo: 'Cash / Bank loan repayment'),
                ],
                narration: 'Loan repayment '.$repayment->repayment_no,
                createdBy: $userId,
            ));
            $repayment->update(['status' => 'POSTED', 'posted_by' => $userId, 'posted_at' => now()]);

            return $repayment->fresh();
        }, 3);
    }

    private function assertCashAccount(int $branchId, int $accountId): void
    {
        $account = Account::query()->with('type')->find($accountId);
        if (! $account || ! $account->is_active || ! $account->is_cash_bank_account || $account->type?->code !== 'ASSET' || ! BranchAccount::query()->where('branch_id', $branchId)->where('account_id', $accountId)->where('is_active', true)->exists()) {
            throw new FinanceConfigurationException('Choose an assigned active Cash / Bank Asset account.');
        }
    }

    private function activeYearFor(string $date): FiscalYear
    {
        $year = FiscalYear::query()->where('is_active', true)->first();
        if (! $year || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw new FinanceConfigurationException('Repayment date must be inside the active fiscal year.');
        }

        return $year;
    }

    private function nextNumber(int $branchId, int $fiscalYearId, string $date): string
    {
        $now = now();
        DB::table('number_sequences')->insertOrIgnore(['key_name' => 'LOAN_REPAYMENT', 'branch_id' => $branchId, 'fiscal_year_id' => $fiscalYearId, 'current_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $sequence = DB::table('number_sequences')->where('key_name', 'LOAN_REPAYMENT')->where('branch_id', $branchId)->where('fiscal_year_id', $fiscalYearId)->lockForUpdate()->first();
        if (! $sequence) {
            throw new FinanceConfigurationException('Unable to prepare the loan repayment number sequence.');
        }
        $number = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['current_number' => $number, 'updated_at' => $now]);

        return sprintf('LRP-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function amount(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || (float) $value <= 0) {
            throw new InvalidJournalDraftException('Repayment amount must be greater than zero and may use up to four decimal places.');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
    }

    private function text(mixed $value, int $limit): ?string
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
