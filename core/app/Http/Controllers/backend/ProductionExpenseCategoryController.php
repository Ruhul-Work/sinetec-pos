<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\Production\ProductionExpenseCategory;
use App\Services\Finance\ExpenseCategorySyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductionExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly ExpenseCategorySyncService $categorySync,
    ) {}

    public function index(): View
    {
        return view('backend.modules.production_expense_category.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $columns = ['id', 'code', 'name', 'expense_account_id', 'expense_account_id', 'is_active', 'id'];
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 0);
        $orderDirection = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';
        $search = trim((string) $request->input('search.value', ''));

        $query = ProductionExpenseCategory::query()
            ->with(['expenseAccount:id,code,name,is_active'])
            ->select(['id', 'code', 'name', 'expense_account_id', 'is_active', 'created_at']);
        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('expenseAccount', function (Builder $accountQuery) use ($search): void {
                        $accountQuery
                            ->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $filtered = (clone $query)->count();
        $orderColumn = $columns[$orderIndex] ?? 'id';
        $categories = $query
            ->orderBy($orderColumn, $orderDirection)
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canEdit = $user?->canDo('production.expense-categories.update', 'edit') ?? false;
        $canDelete = $user?->canDo('production.expense-categories.destroy', 'delete') ?? false;

        $rows = $categories->map(function (ProductionExpenseCategory $category) use ($canEdit, $canDelete): array {
            return [
                $category->id,
                '<code>'.e($category->code).'</code>',
                '<strong>'.e($category->name).'</strong>',
                $this->accountLabel($category),
                $this->configurationBadge($category),
                $this->statusBadge($category),
                $this->actions($category, $canEdit, $canDelete),
            ];
        })->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $rows,
        ]);
    }

    public function createModal(): View
    {
        return $this->formView(
            category: new ProductionExpenseCategory(['is_active' => true]),
            action: route('production.expense-categories.store'),
            method: 'POST',
            title: 'Add Production Expense Category',
        );
    }

    public function editModal(int $expenseCategory): View
    {
        $category = ProductionExpenseCategory::query()->findOrFail($expenseCategory);

        return $this->formView(
            category: $category,
            action: route('production.expense-categories.update', $category),
            method: 'PUT',
            title: 'Edit Production Expense Category',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $category = DB::transaction(function () use ($data): ProductionExpenseCategory {
            $category = ProductionExpenseCategory::query()->create($data);
            $this->assignAccountToActiveBranches($category->expense_account_id);
            $this->categorySync->syncProductionCategory($category);

            return $category;
        }, 3);

        return response()->json([
            'success' => true,
            'msg' => 'Production expense category created successfully.',
            'id' => $category->id,
        ], 201);
    }

    public function update(Request $request, int $expenseCategory): JsonResponse
    {
        $category = ProductionExpenseCategory::query()->findOrFail($expenseCategory);
        $previousCode = $category->code;
        $data = $this->validated($request, $category);

        DB::transaction(function () use ($category, $data, $previousCode): void {
            $category->update($data);
            $this->assignAccountToActiveBranches($category->expense_account_id);
            $this->categorySync->syncProductionCategory($category, $previousCode);
        }, 3);

        return response()->json([
            'success' => true,
            'msg' => 'Production expense category updated successfully.',
        ]);
    }

    public function destroy(int $expenseCategory): JsonResponse
    {
        $category = ProductionExpenseCategory::query()->findOrFail($expenseCategory);

        if ($category->partProductionExpenses()->exists()) {
            return response()->json([
                'success' => false,
                'msg' => 'This category is already used by a production batch. Make it inactive instead.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'msg' => 'Production expense category deleted successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(
        Request $request,
        ?ProductionExpenseCategory $category = null,
    ): array {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('production_expense_categories', 'code')->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'expense_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        $accountId = isset($data['expense_account_id'])
            ? (int) $data['expense_account_id']
            : null;

        if ($accountId !== null) {
            $this->assertExpenseAccountIsUsable($accountId);
        }

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'expense_account_id' => $accountId,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function assertExpenseAccountIsUsable(int $accountId): void
    {
        $account = Account::query()
            ->with('type:id,code')
            ->withCount('children')
            ->find($accountId);

        if (! $account || ! $account->is_active || $account->type?->code !== 'EXPENSE') {
            throw ValidationException::withMessages([
                'expense_account_id' => 'Select an active Expense account.',
            ]);
        }

        if ($account->children_count > 0) {
            throw ValidationException::withMessages([
                'expense_account_id' => 'Select a child/leaf Expense account, not a parent account.',
            ]);
        }
    }

    private function assignAccountToActiveBranches(?int $accountId): void
    {
        if (! $accountId) {
            return;
        }

        Branch::query()
            ->where('is_active', true)
            ->pluck('id')
            ->each(function ($branchId) use ($accountId): void {
                BranchAccount::query()->updateOrCreate(
                    [
                        'branch_id' => (int) $branchId,
                        'account_id' => $accountId,
                    ],
                    [
                        'is_active' => true,
                    ],
                );
            });
    }

    private function formView(
        ProductionExpenseCategory $category,
        string $action,
        string $method,
        string $title,
    ): View {
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereHas('type', fn (Builder $query) => $query->where('code', 'EXPENSE'))
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('backend.modules.production_expense_category.modal', compact(
            'category',
            'action',
            'method',
            'title',
            'accounts',
        ));
    }

    private function accountLabel(ProductionExpenseCategory $category): string
    {
        if (! $category->expenseAccount) {
            return '<span class="text-muted">Not assigned</span>';
        }

        $inactive = $category->expenseAccount->is_active
            ? ''
            : '<br><small class="text-danger">Account inactive</small>';

        return '<strong>'.e($category->expenseAccount->code).'</strong> — '
        .e($category->expenseAccount->name)
            .$inactive;
    }

    private function configurationBadge(ProductionExpenseCategory $category): string
    {
        if ($category->expenseAccount?->is_active) {
            return '<span class="badge bg-success-focus text-success-main px-12 py-7 radius-4">Configured</span>';
        }

        return '<span class="badge bg-warning-focus text-warning-main px-12 py-7 radius-4">Setup required</span>';
    }

    private function statusBadge(ProductionExpenseCategory $category): string
    {
        if ($category->is_active) {
            return '<span class="badge bg-dark-success-gradient text-white px-16 py-7 radius-4">Active</span>';
        }

        return '<span class="badge bg-dark-warning-gradient text-white px-16 py-7 radius-4">Inactive</span>';
    }

    private function actions(
        ProductionExpenseCategory $category,
        bool $canEdit,
        bool $canDelete,
    ): string {
        $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

        if ($canEdit) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
            .'align-items-center justify-content-center bg-success-focus text-success-main AjaxModal" '
            .'data-ajax-modal="'.e(route('production.expense-categories.editModal', $category)).'" '
                .'data-size="md" data-onload="ProductionExpenseCategoriesIndex.onLoad" '
                .'data-onsuccess="ProductionExpenseCategoriesIndex.onSaved" title="Edit category">'
                .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
        }

        if ($canDelete) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
            .'align-items-center justify-content-center bg-danger-focus text-danger-main '
            .'btn-production-expense-category-delete" '
            .'data-url="'.e(route('production.expense-categories.destroy', $category)).'" '
                .'title="Delete category">'
                .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
        }

        return $actions.'</div>';
    }
}
