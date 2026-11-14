<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\FinanceMappingKey;
use App\Enums\Finance\FinanceMappingScope;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Http\Controllers\Controller;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Services\Finance\FinanceAccountMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinanceAccountMappingController extends Controller
{
    public function __construct(private readonly FinanceAccountMappingService $mappingService) {}

    public function index(Request $request): View
    {
        $canManageCompany = (bool) $request->user()?->isSuper();
        $branches = Branch::query()
            ->where('is_active', true)
            ->when(! $canManageCompany, fn ($query) => $query->whereKey((int) $request->user()?->branch_id))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $company = $this->mappingService->mappingsForScope(FinanceMappingScope::Company)
            ->keyBy(fn ($mapping) => $mapping->mapping_key->value);
        $branchStatuses = $branches->mapWithKeys(function (Branch $branch): array {
            $effective = $this->mappingService->effectiveMappings($branch->id);

            return [$branch->id => collect($effective)->filter(fn ($row) => $row['account'])->count()];
        });

        return view('backend.modules.finance_account_mapping.index', [
            'definitions' => $this->mappingService->definitions(),
            'branches' => $branches,
            'companyMappings' => $company,
            'companyAccounts' => $this->compatibleAccounts(),
            'branchStatuses' => $branchStatuses,
            'canManageCompany' => $canManageCompany,
        ]);
    }

    public function branchData(Request $request, Branch $branch): JsonResponse
    {
        $this->ensureBranchAccess($request, $branch);

        $overrides = $this->mappingService->mappingsForScope(FinanceMappingScope::Branch, $branch->id)
            ->keyBy(fn ($mapping) => $mapping->mapping_key->value);
        $effective = $this->mappingService->effectiveMappings($branch->id);
        $assignedIds = BranchAccount::query()->where('branch_id', $branch->id)->where('is_active', true)->pluck('account_id');

        return response()->json([
            'overrides' => collect(FinanceMappingKey::configurableCases())->mapWithKeys(fn (FinanceMappingKey $key) => [
                $key->value => $overrides->get($key->value)?->account_id,
            ]),
            'effective' => collect($effective)->map(fn (array $row) => [
                'account_id' => $row['account']?->id,
                'account' => $row['account'] ? $row['account']->code.' - '.$row['account']->name : null,
                'source' => $row['source'],
            ]),
            'accounts' => $this->compatibleAccounts($assignedIds->all()),
        ]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuper(), 403, 'Only a super user can update company account mappings.');

        try {
            $this->mappingService->saveCompanyMappings($this->mappingInput($request));
        } catch (FinanceConfigurationException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Company finance account mappings updated.');
    }

    public function updateBranch(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($request, $branch);

        try {
            $this->mappingService->saveBranchMappings($branch, $this->mappingInput($request));
        } catch (FinanceConfigurationException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Finance account overrides updated for {$branch->name}.");
    }

    /** @return array<string, int|null> */
    private function mappingInput(Request $request): array
    {
        $request->validate(['mappings' => ['nullable', 'array']]);
        $input = (array) $request->input('mappings', []);
        $mappings = [];
        foreach (FinanceMappingKey::configurableCases() as $key) {
            $value = $input[$key->value] ?? null;
            if ($value !== null && $value !== '') {
                $request->validate(["mappings.{$key->value}" => ['integer', 'exists:accounts,id']]);
            }
            $mappings[$key->value] = $value === null || $value === '' ? null : (int) $value;
        }

        return $mappings;
    }

    /** @param array<int, int>|null $accountIds @return array<string, array<int, array{id: int, text: string}>> */
    private function compatibleAccounts(?array $accountIds = null): array
    {
        $accounts = Account::query()->with('type')->where('is_active', true)
            ->when($accountIds !== null, fn ($query) => $query->whereKey($accountIds))
            ->orderBy('code')->get();
        $result = [];
        foreach (FinanceMappingKey::configurableCases() as $key) {
            $result[$key->value] = $accounts->filter(fn (Account $account) => $this->mappingService->isCompatible($key, $account))
                ->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' - '.$account->name])->values()->all();
        }

        return $result;
    }

    private function ensureBranchAccess(Request $request, Branch $branch): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->isSuper() || (int) $user->branch_id === (int) $branch->id),
            403,
            'You cannot access finance settings for another branch.',
        );
    }
}
