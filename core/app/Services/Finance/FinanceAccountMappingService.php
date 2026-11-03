<?php

namespace App\Services\Finance;

use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Finance\FinanceMappingScope;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\Finance\FinanceAccountMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FinanceAccountMappingService
{
    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return array_map(fn (FinanceMappingKey $key): array => [
            'key' => $key->value,
            'label' => $key->label(),
            'purpose' => $key->purpose(),
            'expected_type' => $key->expectedTypeCode(),
            'normal_balance' => $key->expectedNormalBalance()->value,
        ], FinanceMappingKey::cases());
    }

    /** @param array<string, int|string|null> $mappings */
    public function saveCompanyMappings(array $mappings): void
    {
        DB::transaction(function () use ($mappings): void {
            $this->saveScope(FinanceMappingScope::Company, null, $mappings);
            Branch::query()->where('is_active', true)->each(function (Branch $branch): void {
                $this->assignCompanyMappedAccountsToBranch($branch);
            });
        }, 3);
    }

    public function assignCompanyMappedAccountsToBranch(Branch $branch): void
    {
        $accountIds = FinanceAccountMapping::query()
            ->where('scope', FinanceMappingScope::Company)
            ->where('is_active', true)
            ->pluck('account_id');

        foreach ($accountIds as $accountId) {
            BranchAccount::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'account_id' => $accountId],
                ['is_active' => true],
            );
        }
    }

    /** @param array<string, int|string|null> $mappings */
    public function saveBranchMappings(Branch $branch, array $mappings): void
    {
        DB::transaction(fn () => $this->saveScope(FinanceMappingScope::Branch, $branch, $mappings), 3);
    }

    public function resolve(FinanceMappingKey $key, int $branchId): ?Account
    {
        $mapping = FinanceAccountMapping::query()
            ->with('account.type')
            ->where('scope_key', FinanceMappingScope::Branch->scopeKey($branchId))
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->first();

        $mapping ??= FinanceAccountMapping::query()
            ->with('account.type')
            ->where('scope_key', FinanceMappingScope::Company->scopeKey())
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->first();

        return $mapping?->account?->is_active ? $mapping->account : null;
    }

    public function resolveRequired(FinanceMappingKey $key, int $branchId): Account
    {
        return $this->resolve($key, $branchId)
            ?? throw new FinanceConfigurationException("Finance account mapping [{$key->label()}] is not configured for this branch.");
    }

    /** @return array<string, array{account: ?Account, source: ?string}> */
    public function effectiveMappings(int $branchId): array
    {
        $company = $this->mappingsForScope(FinanceMappingScope::Company)->keyBy(fn (FinanceAccountMapping $mapping) => $mapping->mapping_key->value);
        $branch = $this->mappingsForScope(FinanceMappingScope::Branch, $branchId)->keyBy(fn (FinanceAccountMapping $mapping) => $mapping->mapping_key->value);
        $result = [];
        foreach (FinanceMappingKey::cases() as $key) {
            $mapping = $branch->get($key->value) ?? $company->get($key->value);
            $result[$key->value] = [
                'account' => $mapping?->account,
                'source' => $mapping ? ($branch->has($key->value) ? 'BRANCH' : 'COMPANY') : null,
            ];
        }

        return $result;
    }

    /** @return Collection<int, FinanceAccountMapping> */
    public function mappingsForScope(FinanceMappingScope $scope, ?int $branchId = null): Collection
    {
        return FinanceAccountMapping::query()
            ->with('account.type')
            ->where('scope_key', $scope->scopeKey($branchId))
            ->where('is_active', true)
            ->get();
    }

    /** @return array<int, int> */
    public function requiredAccountIdsForBranch(int $branchId): array
    {
        return collect($this->effectiveMappings($branchId))
            ->pluck('account.id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function isCompatible(FinanceMappingKey $key, Account $account): bool
    {
        $account->loadMissing('type');

        return $account->is_active
            && $account->type?->code === $key->expectedTypeCode()
            && $account->type?->normal_balance === $key->expectedNormalBalance();
    }

    /** @param array<string, int|string|null> $mappings */
    private function saveScope(FinanceMappingScope $scope, ?Branch $branch, array $mappings): void
    {
        foreach (FinanceMappingKey::cases() as $key) {
            $accountId = (int) ($mappings[$key->value] ?? 0);
            $scopeKey = $scope->scopeKey($branch?->id);
            if ($accountId === 0) {
                FinanceAccountMapping::query()->where('scope_key', $scopeKey)->where('mapping_key', $key)->delete();

                continue;
            }

            $account = Account::query()->with('type')->whereKey($accountId)->where('is_active', true)->first()
                ?? throw new FinanceConfigurationException("The selected account for {$key->label()} is inactive or unavailable.");
            if (! $this->isCompatible($key, $account)) {
                throw new FinanceConfigurationException("{$key->label()} requires an active {$key->expectedTypeCode()} account with {$key->expectedNormalBalance()->value} normal balance.");
            }
            if ($scope === FinanceMappingScope::Branch && ! BranchAccount::query()
                ->where('branch_id', $branch->id)->where('account_id', $account->id)->where('is_active', true)->exists()) {
                throw new FinanceConfigurationException("Account {$account->code} must be assigned to {$branch->name} before it can be used as an override.");
            }

            FinanceAccountMapping::query()->updateOrCreate(
                ['scope_key' => $scopeKey, 'mapping_key' => $key->value],
                [
                    'scope' => $scope,
                    'branch_id' => $scope === FinanceMappingScope::Branch ? $branch->id : null,
                    'account_id' => $account->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
