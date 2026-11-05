<?php
namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\CapitalInvestmentStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidCapitalInvestmentException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\Finance\CapitalInvestment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CapitalInvestmentService
{
    public function __construct(private readonly JournalDraftService $journals)
    {}

    /** @param array<string, mixed> $data */
    public function create(array $data, int $userId): CapitalInvestment
    {
        return DB::transaction(function () use ($data, $userId): CapitalInvestment {
            $branchId = (int) $data['branch_id'];
            $date     = $this->date($data['investment_date'] ?? null);
            $year     = $this->activeYearFor($date);
            $this->assertAccounts($branchId, (int) $data['cash_bank_account_id'], (int) $data['capital_account_id']);

            return CapitalInvestment::query()->create([
                'investment_no'        => $this->nextNumber($branchId, $year->id, $date),
                'branch_id'            => $branchId,
                'investment_type'      => $this->type($data['investment_type'] ?? ''),
                'cash_bank_account_id' => (int) $data['cash_bank_account_id'],
                'capital_account_id'   => (int) $data['capital_account_id'],
                'investment_date'      => $date,
                'amount'               => $this->amount($data['amount'] ?? null),
                'reference_no'         => $this->nullableText($data['reference_no'] ?? null, 100),
                'note'                 => $this->nullableText($data['note'] ?? null, 5000),
                'status'               => CapitalInvestmentStatus::Draft,
                'created_by'           => $userId,
            ]);
        }, 3);
    }

    public function post(CapitalInvestment $investment, int $userId): CapitalInvestment
    {
        return DB::transaction(function () use ($investment, $userId): CapitalInvestment {
            $investment = CapitalInvestment::query()->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            if ($investment->status === CapitalInvestmentStatus::Posted) {
                return $investment;
            }
            if ($investment->status === CapitalInvestmentStatus::Void) {
                throw new InvalidCapitalInvestmentException('A void investment document cannot be posted.');
            }

            $this->activeYearFor($investment->investment_date->toDateString());
            $this->assertAccounts((int) $investment->branch_id, (int) $investment->cash_bank_account_id, (int) $investment->capital_account_id);
            $this->journals->create(new JournalDraftData(
                voucherTypeCode: 'RECEIPT',
                branchId: (int) $investment->branch_id,
                entryDate: $investment->investment_date,
                sourceType: 'capital_investment',
                sourceId: (int) $investment->id,
                postingKey: 'journal:capital_investment:' . $investment->id,
                lines: [
                    new JournalLineData(accountId: (int) $investment->cash_bank_account_id, debit: (string) $investment->amount, memo: 'Investment received in Cash / Bank'),
                    new JournalLineData(accountId: (int) $investment->capital_account_id, credit: (string) $investment->amount, memo: $this->typeLabel($investment->investment_type) . ' credited'),
                ],
                narration: $this->typeLabel($investment->investment_type) . ' ' . $investment->investment_no,
                createdBy: $userId,
            ));
            $investment->update(['status' => CapitalInvestmentStatus::Posted, 'posted_by' => $userId, 'posted_at' => now()]);

            return $investment->fresh();
        }, 3);
    }

    public function voidDraft(CapitalInvestment $investment, string $reason, int $userId): CapitalInvestment
    {
        return DB::transaction(function () use ($investment, $reason, $userId): CapitalInvestment {
            $investment = CapitalInvestment::query()->whereKey($investment->id)->lockForUpdate()->firstOrFail();
            if ($investment->status === CapitalInvestmentStatus::Void) {
                return $investment;
            }
            if ($investment->status !== CapitalInvestmentStatus::Draft) {
                throw new InvalidCapitalInvestmentException('Only a Draft investment can be voided. A posted investment must be corrected through a reversal in the next finance phase.');
            }
            $reason = $this->nullableText($reason, 1000);
            if ($reason === null) {
                throw new InvalidCapitalInvestmentException('A void reason is required.');
            }
            $investment->update(['status' => CapitalInvestmentStatus::Void, 'voided_by' => $userId, 'voided_at' => now(), 'void_reason' => $reason]);

            return $investment->fresh();
        }, 3);
    }

    private function assertAccounts(int $branchId, int $cashBankAccountId, int $capitalAccountId): void
    {
        $branch = Branch::query()->whereKey($branchId)->where('is_active', true)->first();
        if (! $branch) {
            throw new FinanceConfigurationException('Choose an active branch.');
        }
        if ($cashBankAccountId === $capitalAccountId) {
            throw new InvalidCapitalInvestmentException('Cash / Bank and Capital accounts must be different.');
        }
        $accounts = Account::query()->with('type')->whereIn('id', [$cashBankAccountId, $capitalAccountId])->get()->keyBy('id');
        $cash     = $accounts->get($cashBankAccountId);
        $capital  = $accounts->get($capitalAccountId);
        if (! $cash || ! $cash->is_active || ! $cash->is_cash_bank_account || $cash->type?->code !== 'ASSET') {
            throw new InvalidCapitalInvestmentException('Choose an active Cash / Bank Asset account.');
        }
        if (! $capital || ! $capital->is_active || $capital->type?->code !== 'EQUITY') {
            throw new InvalidCapitalInvestmentException('Choose an active Equity account for Owner / Partner Capital.');
        }
        $assigned = BranchAccount::query()->where('branch_id', $branchId)->where('is_active', true)
            ->whereIn('account_id', [$cashBankAccountId, $capitalAccountId])->pluck('account_id')->map(fn($id): int => (int) $id)->all();
        if (! in_array($cashBankAccountId, $assigned, true) || ! in_array($capitalAccountId, $assigned, true)) {
            throw new FinanceConfigurationException('Cash / Bank and Capital accounts must both be assigned to the selected branch.');
        }
    }

    private function activeYearFor(string $date): FiscalYear
    {
        $year = FiscalYear::query()->where('is_active', true)->first();
        if (! $year || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw new FinanceConfigurationException('Investment date must be inside the active fiscal year.');
        }

        return $year;
    }

    private function nextNumber(int $branchId, int $yearId, string $date): string
    {
        $timestamp = now();
        DB::table('number_sequences')->insertOrIgnore(['key_name' => 'CAPITAL_INVESTMENT', 'branch_id' => $branchId, 'fiscal_year_id' => $yearId, 'current_number' => 0, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        $sequence = DB::table('number_sequences')->where('key_name', 'CAPITAL_INVESTMENT')->where('branch_id', $branchId)->where('fiscal_year_id', $yearId)->lockForUpdate()->first();
        if (! $sequence) {
            throw new FinanceConfigurationException('Unable to prepare the capital investment number sequence.');
        }
        $number = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['current_number' => $number, 'updated_at' => $timestamp]);

        return sprintf('CI-%s-%05d', CarbonImmutable::parse($date)->format('Y'), $number);
    }

    private function type(mixed $type): string
    {
        $type = strtoupper(trim((string) $type));
        if (! in_array($type, ['OWNER_CAPITAL', 'PARTNER_CAPITAL'], true)) {
            throw new InvalidCapitalInvestmentException('Choose Owner Capital or Partner Capital.');
        }

        return $type;
    }

    private function typeLabel(string $type): string
    {
        return $type === 'PARTNER_CAPITAL' ? 'Partner Capital Investment' : 'Owner Capital Investment';
    }

    private function date(mixed $date): string
    {
        try {
            return CarbonImmutable::parse((string) $date)->toDateString();
        } catch (Throwable) {
            throw new InvalidCapitalInvestmentException('Investment date is invalid.');
        }
    }

    private function amount(mixed $amount): string
    {
        $value = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value) || (float) $value <= 0) {
            throw new InvalidCapitalInvestmentException('Investment amount must be greater than zero and may use up to four decimal places.');
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
            throw new InvalidCapitalInvestmentException("Text may not exceed {$limit} characters.");
        }

        return $value;
    }
}
