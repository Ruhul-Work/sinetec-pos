<?php
namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\LoanReceiptStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidLoanReceiptException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\Finance\LoanReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class LoanReceiptService
{
    public function __construct(private readonly JournalDraftService $journals)
    {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): LoanReceipt
    {
        return DB::transaction(function () use ($data, $userId): LoanReceipt {
            $branchId = (int) $data['branch_id'];
            $date     = $this->date($data['receipt_date'] ?? null);
            $year     = $this->activeYearFor($date);
            $this->assertAccounts($branchId, (int) $data['cash_bank_account_id'], (int) $data['loan_account_id']);

            return LoanReceipt::query()->create([
                'loan_no'              => $this->nextNumber($branchId, $year->id, $date),
                'branch_id'            => $branchId,
                'loan_type'            => $this->type($data['loan_type'] ?? ''),
                'cash_bank_account_id' => (int) $data['cash_bank_account_id'],
                'loan_account_id'      => (int) $data['loan_account_id'],
                'receipt_date'         => $date,
                'amount'               => $this->amount($data['amount'] ?? null),
                'reference_no'         => $this->nullableText($data['reference_no'] ?? null, 100),
                'note'                 => $this->nullableText($data['note'] ?? null, 5000),
                'status'               => LoanReceiptStatus::Draft,
                'created_by'           => $userId,
            ]);
        }, 3);
    }

    public function post(LoanReceipt $loan, int $userId): LoanReceipt
    {
        return DB::transaction(function () use ($loan, $userId): LoanReceipt {
            $loan = LoanReceipt::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status === LoanReceiptStatus::Posted) {
                return $loan;
            }
            if ($loan->status === LoanReceiptStatus::Void) {
                throw new InvalidLoanReceiptException('A void loan receipt cannot be posted.');
            }

            $this->activeYearFor($loan->receipt_date->toDateString());
            $this->assertAccounts((int) $loan->branch_id, (int) $loan->cash_bank_account_id, (int) $loan->loan_account_id);
            $this->journals->create(new JournalDraftData(
                voucherTypeCode: 'RECEIPT', branchId: (int) $loan->branch_id, entryDate: $loan->receipt_date,
                sourceType: 'loan_receipt', sourceId: (int) $loan->id, postingKey: 'journal:loan_receipt:' . $loan->id,
                lines: [
                    new JournalLineData(
                        accountId: (int) $loan->cash_bank_account_id,
                        debit: (string) $loan->amount,
                        memo: 'Loan received in Cash / Bank',
                    ),
                    new JournalLineData(
                        accountId: (int) $loan->loan_account_id,
                        credit: (string) $loan->amount,
                        memo: $this->typeLabel($loan->loan_type) . ' payable credited',
                    ),
                ],
                narration: $this->typeLabel($loan->loan_type) . ' ' . $loan->loan_no,
                createdBy: $userId,
            ));
            $loan->update(['status' => LoanReceiptStatus::Posted, 'posted_by' => $userId, 'posted_at' => now()]);

            return $loan->fresh();
        }, 3);
    }

    public function voidDraft(LoanReceipt $loan, string $reason, int $userId): LoanReceipt
    {
        return DB::transaction(function () use ($loan, $reason, $userId): LoanReceipt {
            $loan = LoanReceipt::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($loan->status === LoanReceiptStatus::Void) {
                return $loan;
            }
            if ($loan->status !== LoanReceiptStatus::Draft) {
                throw new InvalidLoanReceiptException('Only a Draft loan receipt can be voided.');
            }
            $reason = $this->nullableText($reason, 1000);
            if ($reason === null) {
                throw new InvalidLoanReceiptException('A void reason is required.');
            }
            $loan->update(['status' => LoanReceiptStatus::Void, 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);

            return $loan->fresh();
        }, 3);
    }

    private function assertAccounts(int $branchId, int $cashBankAccountId, int $loanAccountId): void
    {
        if (! Branch::query()->whereKey($branchId)->where('is_active', true)->exists()) {
            throw new FinanceConfigurationException('Choose an active branch.');
        }
        if ($cashBankAccountId === $loanAccountId) {
            throw new InvalidLoanReceiptException('Cash / Bank and Loan accounts must be different.');
        }
        $accounts = Account::query()->with('type')->whereIn('id', [$cashBankAccountId, $loanAccountId])->get()->keyBy('id');
        $cash     = $accounts->get($cashBankAccountId);
        $loan     = $accounts->get($loanAccountId);
        if (! $cash || ! $cash->is_active || ! $cash->is_cash_bank_account || $cash->type?->code !== 'ASSET') {
            throw new InvalidLoanReceiptException('Choose an active Cash / Bank Asset account.');
        }
        if (! $loan || ! $loan->is_active || $loan->type?->code !== 'LIABILITY') {
            throw new InvalidLoanReceiptException('Choose an active Liability account for the loan.');
        }
        $assignedAccountIds = BranchAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereIn('account_id', [$cashBankAccountId, $loanAccountId])
            ->pluck('account_id')
            ->map(fn($accountId): int => (int) $accountId)
            ->all();

        $cashAccountIsAssigned = in_array($cashBankAccountId, $assignedAccountIds, true);
        $loanAccountIsAssigned = in_array($loanAccountId, $assignedAccountIds, true);

        if (! $cashAccountIsAssigned || ! $loanAccountIsAssigned) {
            throw new FinanceConfigurationException('Cash / Bank and Loan accounts must both be assigned to the selected branch.');
        }
    }

    private function activeYearFor(string $date): FiscalYear
    {
        $year = FiscalYear::query()->where('is_active', true)->first();
        if (! $year || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw new FinanceConfigurationException('Loan receipt date must be inside the active fiscal year.');
        }

        return $year;
    }

    private function nextNumber(int $branchId, int $yearId, string $date): string
    {
        $time = now();
        DB::table('number_sequences')->insertOrIgnore([
            'key_name'       => 'LOAN_RECEIPT',
            'branch_id'      => $branchId,
            'fiscal_year_id' => $yearId,
            'current_number' => 0,
            'created_at'     => $time,
            'updated_at'     => $time,
        ]);

        $sequence = DB::table('number_sequences')
            ->where('key_name', 'LOAN_RECEIPT')
            ->where('branch_id', $branchId)
            ->where('fiscal_year_id', $yearId)
            ->lockForUpdate()
            ->first();
        if (! $sequence) {
            throw new FinanceConfigurationException('Unable to prepare the loan receipt number sequence.');
        }
        $number = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['current_number' => $number, 'updated_at' => $time]);

        return sprintf('LR-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function type(mixed $type): string
    {
        $type = strtoupper(trim((string) $type));
        if (! in_array($type, ['DIRECTOR_LOAN', 'PARTNER_LOAN'], true)) {
            throw new InvalidLoanReceiptException('Choose Director Loan or Partner Loan.');
        }

        return $type;
    }

    private function typeLabel(string $type): string
    {
        return $type === 'PARTNER_LOAN' ? 'Partner Loan' : 'Director Loan';
    }

    private function date(mixed $date): string
    {
        try {
            return CarbonImmutable::parse((string) $date)->toDateString();
        } catch (Throwable) {
            throw new InvalidLoanReceiptException('Loan receipt date is invalid.');
        }
    }

    private function amount(mixed $amount): string
    {
        $value = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || (float) $value <= 0) {
            throw new InvalidLoanReceiptException('Loan amount must be greater than zero and may use up to four decimal places.');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0') . '.' . str_pad($fraction, 4, '0');
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $limit) {
            throw new InvalidLoanReceiptException("Text may not exceed {$limit} characters.");
        }

        return $value;
    }
}
