<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\AccountNormalBalance;
use App\Http\Controllers\Controller;
use App\Models\backend\AccountType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountTypeController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.accountType.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'name', 'code', 'normal_balance', 'is_system', 'id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = min(max((int) $request->input('length', 10), 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 1);
        $direction = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) $request->input('search.value', ''));
        $query = AccountType::query()->withCount('accounts');
        $total = (clone $query)->count();
        $query->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
            ->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")));
        $filtered = (clone $query)->count();
        $data = $query
            ->orderBy($columns[$orderIndex] ?? 'name', $direction)
            ->skip($start)
            ->take($length)
            ->get()
            ->map(fn (AccountType $type): array => $this->formatTableRow($type))
            ->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function createModal(): View
    {
        return view('backend.modules.accountType.modal.create', [
            'normalBalances' => AccountNormalBalance::cases(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        AccountType::query()->create([...$data, 'is_system' => false]);

        return response()->json(['success' => true, 'msg' => 'Account type created.']);
    }

    public function editModal(AccountType $type): View
    {
        return view('backend.modules.accountType.modal.edit', [
            'type' => $type,
            'normalBalances' => AccountNormalBalance::cases(),
        ]);
    }

    public function update(Request $request, AccountType $type): JsonResponse
    {
        $data = $this->validated($request, $type);
        if ($type->is_system) {
            $data['code'] = $type->code;
            $data['normal_balance'] = $type->normal_balance->value;
        } elseif ($type->accounts()->exists() && ($data['code'] !== $type->code || $data['normal_balance'] !== $type->normal_balance->value)) {
            return response()->json(['success' => false, 'message' => 'Code and normal balance cannot change after accounts use this type.'], 422);
        }
        $type->update($data);

        return response()->json(['success' => true, 'msg' => 'Account type updated.']);
    }

    public function destroy(AccountType $type): JsonResponse
    {
        if ($type->is_system || $type->accounts()->exists()) {
            return response()->json(['success' => false, 'message' => 'System or referenced account types cannot be deleted.'], 422);
        }
        $type->delete();

        return response()->json(['success' => true, 'msg' => 'Account type deleted.']);
    }

    /** @return array{name: string, code: string, normal_balance: string} */
    private function validated(Request $request, ?AccountType $type = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('account_types', 'name')->ignore($type?->id)],
            'code' => ['required', 'string', 'max:20', Rule::unique('account_types', 'code')->ignore($type?->id)],
            'normal_balance' => ['required', Rule::enum(AccountNormalBalance::class)],
        ]);
        $data['name'] = trim($data['name']);
        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }

    /** @return array<int, int|string> */
    private function formatTableRow(AccountType $type): array
    {
        $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">'
            .$this->editAction($type);

        if (! $type->is_system && $type->accounts_count === 0) {
            $actions .= $this->deleteAction($type);
        }

        $categoryBadge = $type->is_system
            ? '<span class="badge bg-info-focus text-info-main">System</span>'
            : '<span class="badge bg-light text-dark">Custom</span>';

        return [
            $type->id,
            e($type->name),
            '<code>'.e($type->code).'</code>',
            e($type->normal_balance->label()),
            $categoryBadge,
            $actions.'</div>',
        ];
    }

    private function editAction(AccountType $type): string
    {
        return '<a href="#" '
            .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
            .'justify-content-center bg-success-focus text-success-main AjaxModal" '
            .'data-ajax-modal="'.e(route('account-types.editModal', $type)).'" '
            .'data-size="sm" data-onsuccess="AccountTypesIndex.onSaved" title="Edit">'
            .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
    }

    private function deleteAction(AccountType $type): string
    {
        return '<a href="#" '
            .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
            .'justify-content-center bg-danger-focus text-danger-main btn-account-type-del" '
            .'data-url="'.e(route('account-types.destroy', $type)).'" title="Delete">'
            .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
    }
}
