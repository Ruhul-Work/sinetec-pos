<?php

namespace App\Services\Finance;

use App\Data\Finance\JournalDraftData;
use App\Data\Finance\JournalDraftResult;
use App\Data\Finance\JournalLineData;
use App\Enums\Finance\JournalStatus;
use App\Exceptions\Finance\DuplicateJournalDraftException;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\backend\FiscalYear;
use App\Models\backend\JournalEntry;
use App\Models\backend\VoucherType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class JournalDraftService
{
    public function create(JournalDraftData $draft): JournalDraftResult
    {
        $normalized = $this->normalize($draft);

        try {
            return DB::transaction(
                fn (): JournalDraftResult => $this->createWithinTransaction($normalized),
                3,
            );
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use ($normalized, $exception): JournalDraftResult {
                $existing = $this->findExisting($normalized, true);
                if (! $existing) {
                    throw $exception;
                }

                $this->assertSameDraft($existing, $normalized);

                return $this->result($existing, $normalized, true);
            }, 3);
        }
    }

    /** @param array<string, mixed> $draft */
    private function createWithinTransaction(array $draft): JournalDraftResult
    {
        $existing = $this->findExisting($draft, true);
        if ($existing) {
            $this->assertSameDraft($existing, $draft);

            return $this->result($existing, $draft, true);
        }

        $branch = Branch::query()
            ->whereKey($draft['branch_id'])
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if (! $branch) {
            throw new FinanceConfigurationException('The selected branch is inactive or unavailable.');
        }

        $voucherType = VoucherType::query()
            ->where('code', $draft['voucher_type_code'])
            ->lockForUpdate()
            ->first();
        if (! $voucherType) {
            throw new FinanceConfigurationException("Voucher type [{$draft['voucher_type_code']}] is not configured.");
        }

        $fiscalYears = FiscalYear::query()
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();
        if ($fiscalYears->count() !== 1) {
            throw new FinanceConfigurationException('Exactly one active fiscal year is required before creating a journal draft.');
        }

        $fiscalYear = $fiscalYears->first();
        if ($draft['entry_date'] < $fiscalYear->start_date->toDateString()
            || $draft['entry_date'] > $fiscalYear->end_date->toDateString()) {
            throw new FinanceConfigurationException("Journal date {$draft['entry_date']} is outside the active fiscal year {$fiscalYear->name}.");
        }

        $this->assertAccountsAvailable($draft['lines'], $branch->id);

        if ($draft['created_by'] !== null && ! DB::table('users')->where('id', $draft['created_by'])->exists()) {
            throw new InvalidJournalDraftException('The journal creator does not exist.');
        }

        $journal = JournalEntry::query()->create([
            'voucher_no' => $this->nextVoucherNumber($voucherType, $branch->id, $fiscalYear->id, $draft['entry_date']),
            'voucher_type_id' => $voucherType->id,
            'branch_id' => $branch->id,
            'fiscal_year_id' => $fiscalYear->id,
            'source_type' => $draft['source_type'],
            'source_id' => $draft['source_id'],
            'posting_key' => $draft['posting_key'],
            'entry_date' => $draft['entry_date'],
            'status' => JournalStatus::Draft,
            'narration' => $draft['narration'],
            'created_by' => $draft['created_by'],
        ]);

        foreach ($draft['lines'] as $line) {
            $journal->lines()->create([
                'account_id' => $line['account_id'],
                'branch_id' => $branch->id,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'memo' => $line['memo'],
            ]);
        }

        $journal->load(['lines', 'voucherType', 'fiscalYear', 'branch']);

        return $this->result($journal, $draft);
    }

    /** @param array<string, mixed> $draft */
    private function findExisting(array $draft, bool $lock = false): ?JournalEntry
    {
        $query = JournalEntry::query()
            ->with(['lines' => fn ($lineQuery) => $lineQuery->orderBy('id'), 'voucherType'])
            ->where(function ($builder) use ($draft): void {
                $builder->where('posting_key', $draft['posting_key'])
                    ->orWhere(function ($sourceQuery) use ($draft): void {
                        $sourceQuery->where('source_type', $draft['source_type'])
                            ->where('source_id', $draft['source_id']);
                    });
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $matches = $query->get();
        if ($matches->count() > 1) {
            throw new DuplicateJournalDraftException('The journal posting key and source reference point to different existing journals.');
        }

        return $matches->first();
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function assertAccountsAvailable(array $lines, int $branchId): void
    {
        $accountIds = collect($lines)->pluck('account_id')->unique()->values();
        $accounts = Account::query()->whereKey($accountIds)->get()->keyBy('id');
        $assignedIds = BranchAccount::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereIn('account_id', $accountIds)
            ->pluck('account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($accountIds as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account || ! $account->is_active) {
                throw new FinanceConfigurationException("Journal account ID {$accountId} is inactive or unavailable.");
            }
            if (strtoupper($account->currency) !== 'BDT') {
                throw new FinanceConfigurationException("Account {$account->code} must use BDT for this journal.");
            }
            if (! in_array((int) $accountId, $assignedIds, true)) {
                throw new FinanceConfigurationException("Account {$account->code} is not assigned to the selected branch.");
            }
        }
    }

    private function nextVoucherNumber(VoucherType $voucherType, int $branchId, int $fiscalYearId, string $entryDate): string
    {
        $key = 'JOURNAL_'.strtoupper($voucherType->code);
        $timestamp = now();

        DB::table('number_sequences')->insertOrIgnore([
            'key_name' => $key,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'current_number' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $sequence = DB::table('number_sequences')
            ->where('key_name', $key)
            ->where('branch_id', $branchId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->first();

        $next = (int) $sequence->current_number + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update([
            'current_number' => $next,
            'updated_at' => $timestamp,
        ]);

        $code = substr((string) preg_replace('/[^A-Z0-9]+/', '', strtoupper($voucherType->code)), 0, 18);

        return sprintf('%s-%s-%06d', $code, CarbonImmutable::parse($entryDate)->format('Y'), $next);
    }

    /** @return array<string, mixed> */
    private function normalize(JournalDraftData $draft): array
    {
        if ($draft->branchId < 1 || $draft->sourceId < 1) {
            throw new InvalidJournalDraftException('Branch ID and source ID must be positive integers.');
        }
        if ($draft->createdBy !== null && $draft->createdBy < 1) {
            throw new InvalidJournalDraftException('Creator ID must be a positive integer when provided.');
        }

        $voucherTypeCode = strtoupper(trim($draft->voucherTypeCode));
        $sourceType = trim($draft->sourceType);
        $postingKey = trim($draft->postingKey);
        if ($voucherTypeCode === '' || mb_strlen($voucherTypeCode) > 30) {
            throw new InvalidJournalDraftException('Voucher type code is required and may not exceed 30 characters.');
        }
        if ($sourceType === '' || mb_strlen($sourceType) > 80) {
            throw new InvalidJournalDraftException('Source type is required and may not exceed 80 characters.');
        }
        if ($postingKey === '' || mb_strlen($postingKey) > 191) {
            throw new InvalidJournalDraftException('Posting key is required and may not exceed 191 characters.');
        }

        try {
            $entryDate = CarbonImmutable::parse($draft->entryDate)->toDateString();
        } catch (Throwable) {
            throw new InvalidJournalDraftException('Journal entry date is invalid.');
        }

        if (count($draft->lines) < 2) {
            throw new InvalidJournalDraftException('A journal draft requires at least two lines.');
        }

        $lines = [];
        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($draft->lines as $index => $line) {
            if (! $line instanceof JournalLineData) {
                throw new InvalidJournalDraftException('Every journal line must be a JournalLineData instance.');
            }
            if ($line->accountId < 1) {
                throw new InvalidJournalDraftException('Every journal line requires a valid account ID.');
            }

            $debit = $this->decimal($line->debit, 'debit', $index);
            $credit = $this->decimal($line->credit, 'credit', $index);
            $hasDebit = $debit !== '0.0000';
            $hasCredit = $credit !== '0.0000';
            if ($hasDebit === $hasCredit) {
                throw new InvalidJournalDraftException('Each journal line must contain either a debit or a credit amount, but not both.');
            }

            $memo = $this->nullableText($line->memo);
            if ($memo !== null && mb_strlen($memo) > 255) {
                throw new InvalidJournalDraftException('Journal line memo may not exceed 255 characters.');
            }

            $lines[] = [
                'account_id' => $line->accountId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $memo,
            ];
            $totalDebit = $this->addScaled($totalDebit, $this->scaled($debit));
            $totalCredit = $this->addScaled($totalCredit, $this->scaled($credit));
        }

        if ($totalDebit !== $totalCredit) {
            throw new InvalidJournalDraftException(sprintf(
                'Journal draft is not balanced: debit %s, credit %s.',
                $this->unscaled($totalDebit),
                $this->unscaled($totalCredit),
            ));
        }

        return [
            'voucher_type_code' => $voucherTypeCode,
            'branch_id' => $draft->branchId,
            'entry_date' => $entryDate,
            'source_type' => $sourceType,
            'source_id' => $draft->sourceId,
            'posting_key' => $postingKey,
            'lines' => $lines,
            'narration' => $this->nullableText($draft->narration),
            'created_by' => $draft->createdBy,
            'total_debit' => $this->unscaled($totalDebit),
            'total_credit' => $this->unscaled($totalCredit),
        ];
    }

    private function decimal(int|float|string $value, string $field, int $index): string
    {
        $raw = is_float($value)
            ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.')
            : trim((string) $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw new InvalidJournalDraftException('Journal line '.($index + 1)." {$field} must be a non-negative decimal number.");
        }

        [$integer, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        if (strlen($integer) > 16 || strlen($fraction) > 4) {
            throw new InvalidJournalDraftException('Journal line '.($index + 1)." {$field} exceeds the supported precision.");
        }

        return $integer.'.'.str_pad($fraction, 4, '0');
    }

    /** @param array<string, mixed> $draft */
    private function assertSameDraft(JournalEntry $existing, array $draft): void
    {
        $existing->loadMissing(['lines' => fn ($query) => $query->orderBy('id'), 'voucherType']);
        $existingLines = $existing->lines->map(fn ($line): array => [
            'account_id' => (int) $line->account_id,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'memo' => $this->nullableText($line->memo),
        ])->values()->all();

        $matches = (int) $existing->branch_id === $draft['branch_id']
            && $existing->voucherType?->code === $draft['voucher_type_code']
            && $existing->entry_date->toDateString() === $draft['entry_date']
            && $existing->source_type === $draft['source_type']
            && (int) $existing->source_id === $draft['source_id']
            && $existing->posting_key === $draft['posting_key']
            && $this->nullableText($existing->narration) === $draft['narration']
            && $existingLines === $draft['lines'];

        if (! $matches) {
            throw new DuplicateJournalDraftException('The journal posting key or source reference already exists with different draft data.');
        }
    }

    /** @param array<string, mixed> $draft */
    private function result(JournalEntry $journal, array $draft, bool $existing = false): JournalDraftResult
    {
        return new JournalDraftResult(
            journal: $journal,
            totalDebit: $draft['total_debit'],
            totalCredit: $draft['total_credit'],
            wasAlreadyCreated: $existing,
        );
    }

    private function scaled(string $amount): string
    {
        return ltrim(str_replace('.', '', $amount), '0') ?: '0';
    }

    private function unscaled(string $amount): string
    {
        $amount = ltrim($amount, '0') ?: '0';
        $amount = str_pad($amount, 5, '0', STR_PAD_LEFT);

        return substr($amount, 0, -4).'.'.substr($amount, -4);
    }

    private function addScaled(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $sum = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $digit = $carry;
            if ($leftIndex >= 0) {
                $digit += (int) $left[$leftIndex--];
            }
            if ($rightIndex >= 0) {
                $digit += (int) $right[$rightIndex--];
            }
            $sum = ($digit % 10).$sum;
            $carry = intdiv($digit, 10);
        }

        return ltrim($sum, '0') ?: '0';
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505', '19'], true);
    }
}
