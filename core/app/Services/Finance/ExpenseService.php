<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\ExpenseStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidExpenseException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\backend\PaymentType;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExpenseService
{
    public function __construct(private readonly JournalDraftService $journals) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): Expense
    {
        return DB::transaction(function () use ($data, $userId): Expense {
            $branchId = (int) $data['branch_id'];
            $expenseDate = $this->date($data['expense_date'] ?? null);
            $fiscalYear = $this->activeYearFor($expenseDate);

            $this->activeBranch($branchId);

            $category = $this->generalCategory((int) ($data['expense_category_id'] ?? 0));
            $amount = $this->amount($data['amount'] ?? null);
            $this->assertExpenseAccount($category, $branchId);

            $cashBankAccount = $this->cashBankAccount(
                (int) ($data['account_id'] ?? 0),
                $branchId,
            );
            $paymentType = $this->paymentType((int) ($data['payment_type_id'] ?? 0));

            $expense = Expense::query()->create([
                'expense_no' => $this->nextNumber($branchId, $fiscalYear->id, $expenseDate),
                'branch_id' => $branchId,
                'expense_category_id' => $category->id,
                'part_production_batch_id' => null,
                'expense_date' => $expenseDate,
                'payee' => $this->nullableText($data['payee'] ?? null, 150),
                'amount' => $amount,
                'status' => ExpenseStatus::Draft,
                'description' => $this->nullableText($data['description'] ?? null, 5000),
                'created_by' => $userId,
            ]);

            $expense->payment()->create([
                'payment_type_id' => $paymentType->id,
                'account_id' => $cashBankAccount->id,
                'amount' => $amount,
                'reference_no' => $this->nullableText($data['reference_no'] ?? null, 100),
                'created_by' => $userId,
            ]);

            return $this->load($expense);
        }, 3);
    }

    public function post(Expense $expense, int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $userId): Expense {
            $expense = Expense::query()
                ->with([
                    'category',
                    'payment',
                ])
                ->whereKey($expense->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($expense->status === ExpenseStatus::Posted) {
                return $this->load($expense);
            }

            if ($expense->status === ExpenseStatus::Void) {
                throw new InvalidExpenseException('A void expense document cannot be recorded.');
            }

            $this->activeYearFor($expense->expense_date->toDateString());
            $this->activeBranch((int) $expense->branch_id);

            if (! $expense->payment) {
                throw new InvalidExpenseException('The expense payment information is missing.');
            }

            $this->cashBankAccount(
                (int) $expense->payment->account_id,
                (int) $expense->branch_id,
            );
            $this->paymentType((int) $expense->payment->payment_type_id);
            $this->assertPaymentAmountMatches($expense);

            $this->journals->create(new JournalDraftData(
                voucherTypeCode: 'EXPENSE',
                branchId: (int) $expense->branch_id,
                entryDate: $expense->expense_date,
                sourceType: 'expense',
                sourceId: (int) $expense->id,
                postingKey: 'journal:expense:'.$expense->id,
                lines: $this->generalJournalLines($expense),
                narration: 'Expense '.$expense->expense_no,
                createdBy: $userId,
            ));

            $expense->update([
                'status' => ExpenseStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->load($expense);
        }, 3);
    }

    public function voidDraft(Expense $expense, string $reason, int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $userId): Expense {
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->status === ExpenseStatus::Void) {
                return $this->load($expense);
            }

            if ($expense->status !== ExpenseStatus::Draft) {
                throw new InvalidExpenseException(
                    'Only a Draft expense can be voided. A recorded expense requires a controlled reversal.',
                );
            }

            $reason = $this->nullableText($reason, 1000);
            if ($reason === null) {
                throw new InvalidExpenseException('A void reason is required.');
            }

            $expense->update([
                'status' => ExpenseStatus::Void,
                'voided_by' => $userId,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $this->load($expense);
        }, 3);
    }

    /** @return array<int, JournalLineData> */
    private function generalJournalLines(Expense $expense): array
    {
        if (! $expense->category) {
            throw new InvalidExpenseException('The general expense category is missing.');
        }

        $this->assertExpenseAccount($expense->category, (int) $expense->branch_id);

        return [
            new JournalLineData(
                accountId: (int) $expense->category->expense_account_id,
                debit: (string) $expense->amount,
                memo: $expense->category->name,
            ),
            new JournalLineData(
                accountId: (int) $expense->payment->account_id,
                credit: (string) $expense->amount,
                memo: 'Direct payment from Cash / Bank',
            ),
        ];
    }

    private function generalCategory(int $categoryId): ExpenseCategory
    {
        $category = ExpenseCategory::query()
            ->with('expenseAccount.type')
            ->whereKey($categoryId)
            ->where('is_active', true)
            ->where('category_scope', 'GENERAL')
            ->first();

        return $category
            ?? throw new InvalidExpenseException('Choose an active expense category.');
    }

    private function assertExpenseAccount(ExpenseCategory $category, int $branchId): void
    {
        $account = Account::query()
            ->with('type')
            ->withCount('children')
            ->find($category->expense_account_id);

        if (! $account || ! $account->is_active || $account->type?->code !== 'EXPENSE') {
            throw new FinanceConfigurationException(
                "Expense category [{$category->name}] requires an active Expense account.",
            );
        }

        if ($account->children_count > 0) {
            throw new FinanceConfigurationException(
                "Expense category [{$category->name}] must use a leaf Expense account.",
            );
        }

        $this->assertAssignedToBranch((int) $account->id, $branchId, 'Expense');
    }

    private function cashBankAccount(int $accountId, int $branchId): Account
    {
        $account = Account::query()->with('type')->find($accountId);

        if (! $account
            || ! $account->is_active
            || ! $account->is_cash_bank_account
            || $account->type?->code !== 'ASSET') {
            throw new InvalidExpenseException('Choose an active Cash / Bank Asset account.');
        }

        $this->assertAssignedToBranch($accountId, $branchId, 'Cash / Bank');

        return $account;
    }

    private function assertAssignedToBranch(int $accountId, int $branchId, string $label): void
    {
        $isAssigned = BranchAccount::query()
            ->where('branch_id', $branchId)
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->exists();

        if (! $isAssigned) {
            throw new FinanceConfigurationException("{$label} account is not assigned to the selected branch.");
        }
    }

    private function paymentType(int $paymentTypeId): PaymentType
    {
        return PaymentType::query()
            ->whereKey($paymentTypeId)
            ->where('is_active', true)
            ->first()
            ?? throw new InvalidExpenseException('Choose an active payment type.');
    }

    private function assertPaymentAmountMatches(Expense $expense): void
    {
        if (bccomp((string) $expense->amount, (string) $expense->payment->amount, 4) !== 0) {
            throw new InvalidExpenseException('The payment amount must equal the expense amount.');
        }
    }

    private function activeBranch(int $branchId): Branch
    {
        return Branch::query()
            ->whereKey($branchId)
            ->where('is_active', true)
            ->first()
            ?? throw new FinanceConfigurationException('Choose an active branch.');
    }

    private function activeYearFor(string $date): FiscalYear
    {
        $years = FiscalYear::query()->where('is_active', true)->get();
        if ($years->count() !== 1) {
            throw new FinanceConfigurationException('Exactly one active fiscal year is required.');
        }

        $year = $years->first();
        if ($date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw new FinanceConfigurationException('Expense date must be inside the active fiscal year.');
        }

        return $year;
    }

    private function nextNumber(int $branchId, int $fiscalYearId, string $date): string
    {
        $timestamp = now();

        DB::table('number_sequences')->insertOrIgnore([
            'key_name' => 'GENERAL_EXPENSE',
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'current_number' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $sequence = DB::table('number_sequences')
            ->where('key_name', 'GENERAL_EXPENSE')
            ->where('branch_id', $branchId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new FinanceConfigurationException('Unable to prepare the expense number sequence.');
        }

        $next = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update([
            'current_number' => $next,
            'updated_at' => $timestamp,
        ]);

        return sprintf('EXP-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $next);
    }

    private function date(mixed $date): string
    {
        try {
            return CarbonImmutable::parse((string) $date)->toDateString();
        } catch (Throwable) {
            throw new InvalidExpenseException('Expense date is invalid.');
        }
    }

    private function amount(mixed $amount): string
    {
        $value = trim((string) $amount);

        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || (float) $value <= 0) {
            throw new InvalidExpenseException(
                'Expense amount must be greater than zero and may use up to four decimal places.',
            );
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 4, '0');
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $limit) {
            throw new InvalidExpenseException("Text may not exceed {$limit} characters.");
        }

        return $value;
    }

    private function load(Expense $expense): Expense
    {
        return $expense->fresh([
            'branch',
            'category.expenseAccount',
            'payment.account',
            'payment.paymentType',
            'createdBy',
            'postedBy',
            'voidedBy',
            'journal',
        ]);
    }
}
