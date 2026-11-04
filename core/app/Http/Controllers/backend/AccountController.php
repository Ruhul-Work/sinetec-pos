<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\AccountNormalBalance;
use App\Enums\Finance\FinanceMappingKey;
use App\Http\Controllers\Controller;
use App\Models\backend\Account;
use App\Models\backend\AccountType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.account.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'code', 'name', 'account_type_id', 'parent_id', 'id', 'is_active', 'id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = min(max((int) $request->input('length', 10), 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 1);
        $direction = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) $request->input('search.value', ''));
        $query = Account::query()->with(['type:id,name,code,normal_balance', 'parent:id,name,code']);
        $total = (clone $query)->count();
        $query->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
            ->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")));
        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'code', $direction)->skip($start)->take($length)->get();
        $data = $rows->map(function (Account $account): array {
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100"><button class="border-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main btn-load-balance" data-account-id="'.$account->id.'" title="Load posted balance"><iconify-icon icon="mdi:reload"></iconify-icon></button><a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main AjaxModal" data-ajax-modal="'.e(route('accounts.editModal', $account)).'" data-size="lg" data-onsuccess="AccountsIndex.onSaved" title="Edit"><iconify-icon icon="lucide:edit"></iconify-icon></a>';
            if ($account->is_active) {
                $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-account-del" data-url="'.e(route('accounts.destroy', $account)).'" title="Disable"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
            }

            return [
                $account->id,
                '<code>'.e($account->code).'</code>',
                '<strong>'.e($account->name).'</strong>'.($account->is_cash_bank_account ? '<br><span class="badge bg-info-focus text-info-main">Cash / Bank</span>' : '').($account->is_system ? '<br><small class="text-info-main">Starter account</small>' : ''),
                e($account->type?->name ?? '-').'<br><small class="text-muted">'.e($account->type?->normal_balance?->label() ?? '').'</small>',
                $account->parent ? e($account->parent->code.' - '.$account->parent->name) : '<span class="text-muted">Root</span>',
                '<span class="account-balance" data-id="'.$account->id.'">--</span>',
                $account->is_active ? '<span class="badge bg-dark-success-gradient text-white">Active</span>' : '<span class="badge bg-dark-warning-gradient text-white">Inactive</span>',
                $actions.'</div>',
            ];
        })->all();

        return response()->json(['draw' => $draw, 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $filtered, 'aaData' => $data]);
    }

    public function createModal(): View
    {
        return $this->formView();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->validateCashBankType($data);
        Account::query()->create([...$data, 'currency' => 'BDT', 'is_system' => false]);

        return response()->json(['success' => true, 'msg' => 'Account created.']);
    }

    public function editModal(Account $account): View
    {
        return $this->formView($account);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $data = $this->validated($request, $account);
        if ($account->is_system) {
            $data['code'] = $account->code;
            $data['account_type_id'] = $account->account_type_id;
            $data['parent_id'] = $account->parent_id;
        }
        $this->validateCashBankType($data);
        if ($this->wouldCreateCycle($account, $data['parent_id'])) {
            return response()->json(['success' => false, 'message' => 'An account cannot be placed below itself or one of its descendants.'], 422);
        }
        $targetType = AccountType::query()->findOrFail($data['account_type_id']);
        foreach ($account->financeMappings()->get() as $mapping) {
            $key = $mapping->mapping_key instanceof FinanceMappingKey ? $mapping->mapping_key : FinanceMappingKey::from($mapping->mapping_key);
            if ($targetType->code !== $key->expectedTypeCode() || $targetType->normal_balance !== $key->expectedNormalBalance()) {
                return response()->json(['success' => false, 'message' => 'Replace this account in Finance Account Mapping before changing its account type.'], 422);
            }
        }
        $account->update($data);

        return response()->json(['success' => true, 'msg' => 'Account updated.']);
    }

    public function destroy(Account $account): JsonResponse
    {
        if (! $account->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is already inactive.'], 422);
        }
        if ($account->financeMappings()->where('is_active', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Replace this account in Finance Account Mapping before disabling it.'], 422);
        }
        if ($account->children()->where('is_active', true)->exists()) {
            return response()->json(['success' => false, 'message' => 'Disable or move active child accounts first.'], 422);
        }
        $account->update(['is_active' => false]);

        return response()->json(['success' => true, 'message' => 'Account disabled. Historical transactions remain unchanged.']);
    }

    public function balance(Account $account): JsonResponse
    {
        $branchId = current_branch_id();
        $journal = DB::table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->where('line.account_id', $account->id)->where('journal.status', 'POSTED')
            ->when($branchId, fn ($q, int $id) => $q->where('line.branch_id', $id))
            ->selectRaw('COALESCE(SUM(line.debit),0) debit, COALESCE(SUM(line.credit),0) credit')->first();
        $opening = DB::table('account_opening_balances')->where('account_id', $account->id)->where('status', 'POSTED')
            ->when($branchId, fn ($q, int $id) => $q->where('branch_id', $id))
            ->selectRaw('COALESCE(SUM(debit),0) debit, COALESCE(SUM(credit),0) credit')->first();
        $debit = (float) $journal->debit + (float) $opening->debit;
        $credit = (float) $journal->credit + (float) $opening->credit;
        $normal = $account->loadMissing('type')->type?->normal_balance ?? AccountNormalBalance::Debit;
        $balance = $normal === AccountNormalBalance::Debit ? $debit - $credit : $credit - $debit;

        return response()->json(['balance' => number_format($balance, 4), 'normal_balance' => $normal->value]);
    }

    private function formView(?Account $account = null): View
    {
        return view($account ? 'backend.modules.account.modal.edit' : 'backend.modules.account.modal.create', [
            'account' => $account,
            'types' => AccountType::query()->orderBy('name')->get(),
            'parents' => Account::query()->where('is_active', true)->when($account, fn ($q) => $q->whereKeyNot($account->id))->orderBy('code')->get(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:50', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'account_type_id' => ['required', 'integer', 'exists:account_types,id'],
            'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_details' => ['nullable', 'string', 'max:5000'],
            'is_cash_bank_account' => ['nullable', 'boolean'],
            'allow_negative' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['name'] = trim($data['name']);
        $data['code'] = strtoupper(trim($data['code']));
        $data['parent_id'] = $data['parent_id'] ?? null;
        $data['is_cash_bank_account'] = $request->boolean('is_cash_bank_account');
        $data['allow_negative'] = $request->boolean('allow_negative');
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateCashBankType(array $data): void
    {
        if (! empty($data['is_cash_bank_account']) && AccountType::query()->whereKey($data['account_type_id'])->value('code') !== 'ASSET') {
            abort(422, 'Only an Asset account can be used as a Cash / Bank account.');
        }
    }

    private function wouldCreateCycle(Account $account, ?int $parentId): bool
    {
        $seen = [$account->id => true];
        while ($parentId) {
            if (isset($seen[$parentId])) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = Account::query()->whereKey($parentId)->value('parent_id');
        }

        return false;
    }
}
