<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Services\Finance\FinanceAccountMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BranchAccountController extends Controller
{
    public function __construct(private readonly FinanceAccountMappingService $mappingService) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('backend.modules.accountBranch.index', [
            'branches' => Branch::query()
                ->where('is_active', true)
                ->when(! $user?->isSuper(), fn ($query) => $query->whereKey((int) $user?->branch_id))
                ->orderBy('name')
                ->get(),
            'accounts' => Account::query()->with('type:id,name')->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function assignedAccounts(Request $request, Branch $branch): JsonResponse
    {
        $this->ensureBranchAccess($request, $branch);

        $rows = BranchAccount::query()->where('branch_id', $branch->id)->where('is_active', true)->get();

        return response()->json([
            'account_ids' => $rows->pluck('account_id')->map(fn ($id) => (int) $id)->values(),
            'default_account_id' => $rows->firstWhere('is_default', true)?->account_id,
            'required_account_ids' => $this->mappingService->requiredAccountIdsForBranch($branch->id),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
            'default_account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);
        $this->ensureBranchAccess($request, Branch::query()->findOrFail((int) $data['branch_id']));

        $accountIds = collect($data['account_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        if (! $accountIds->contains((int) $data['default_account_id'])) {
            throw ValidationException::withMessages(['default_account_id' => 'Default account must be assigned to the branch.']);
        }
        $required = $this->mappingService->requiredAccountIdsForBranch((int) $data['branch_id']);
        $missing = collect($required)->diff($accountIds);
        if ($missing->isNotEmpty()) {
            $names = Account::query()->whereKey($missing)->orderBy('code')->pluck('name')->implode(', ');
            throw ValidationException::withMessages(['account_ids' => "Mapped control accounts cannot be removed: {$names}."]);
        }
        $activeCount = Account::query()->whereKey($accountIds)->where('is_active', true)->count();
        if ($activeCount !== $accountIds->count()) {
            throw ValidationException::withMessages(['account_ids' => 'Only active accounts can be assigned.']);
        }

        DB::transaction(function () use ($data, $accountIds): void {
            BranchAccount::query()->where('branch_id', $data['branch_id'])->whereNotIn('account_id', $accountIds)->delete();
            foreach ($accountIds as $accountId) {
                BranchAccount::query()->updateOrCreate(
                    ['branch_id' => $data['branch_id'], 'account_id' => $accountId],
                    ['is_active' => true, 'is_default' => $accountId === (int) $data['default_account_id']],
                );
            }
        }, 3);

        return back()->with('success', 'Branch accounts updated successfully.');
    }

    private function ensureBranchAccess(Request $request, Branch $branch): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->isSuper() || (int) $user->branch_id === (int) $branch->id),
            403,
            'You cannot access account assignments for another branch.',
        );
    }
}
