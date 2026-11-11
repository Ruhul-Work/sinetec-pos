<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use App\Models\backend\Account;
use App\Models\backend\Branch;
use App\Models\backend\BranchAccount;
use App\Models\Finance\ExpenseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.expense_category.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $search = trim((string) $request->input('search.value', ''));

        $query = ExpenseCategory::query()
            ->with('expenseAccount:id,code,name,is_active')
            ->where('category_scope', 'GENERAL')
            ->select(['id', 'code', 'name', 'expense_account_id', 'is_active']);
        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query
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
        $categories = $query
            ->orderByDesc('id')
            ->skip($start)
            ->take($length)
            ->get();

        $user = $request->user();
        $canEdit = $user?->canDo('finance.expense-categories.update', 'edit') ?? false;
        $canDelete = $user?->canDo('finance.expense-categories.destroy', 'delete') ?? false;

        $rows = $categories->map(function (ExpenseCategory $category) use ($canEdit, $canDelete): array {
            return [
                $category->id,
                '<code>'.e($category->code).'</code>',
                '<strong>'.e($category->name).'</strong>',
                $this->accountLabel($category),
                $this->statusBadge($category),
                $this->actions($category, $canEdit, $canDelete),
            ];
        });

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $rows->all(),
        ]);
    }

    public function createModal(): View
    {
        return $this->formView(
            category: new ExpenseCategory(['is_active' => true]),
            action: route('finance.expense-categories.store'),
            method: 'POST',
            title: 'Add General Expense Category',
        );
    }

    public function editModal(int $expenseCategory): View
    {
        $category = $this->record($expenseCategory);

        return $this->formView(
            category: $category,
            action: route('finance.expense-categories.update', $category),
            method: 'PUT',
            title: 'Edit General Expense Category',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $category = DB::transaction(function () use ($data): ExpenseCategory {
            $category = ExpenseCategory::query()->create($data);
            $this->assignAccountToActiveBranches((int) $category->expense_account_id);

            return $category;
        }, 3);

        return response()->json([
            'success' => true,
            'msg' => 'General expense category created successfully.',
            'id' => $category->id,
        ], 201);
    }

    public function update(Request $request, int $expenseCategory): JsonResponse
    {
        $category = $this->record($expenseCategory);
        $data = $this->validated($request, $category);

        DB::transaction(function () use ($category, $data): void {
            $category->update($data);
            $this->assignAccountToActiveBranches((int) $category->expense_account_id);
        }, 3);

        return response()->json([
            'success' => true,
            'msg' => 'General expense category updated successfully.',
        ]);
    }

    public function destroy(int $expenseCategory): JsonResponse
    {
        $category = $this->record($expenseCategory);

        if ($category->expenses()->exists()) {
            return response()->json([
                'success' => false,
                'msg' => 'This category is already used by an expense document. Make it inactive instead.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'msg' => 'General expense category deleted successfully.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ExpenseCategory $category = null): array
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
        ]);

        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('expense_categories', 'code')->ignore($category?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'expense_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->assertExpenseAccountIsUsable((int) $data['expense_account_id']);

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'category_scope' => 'GENERAL',
            'expense_account_id' => (int) $data['expense_account_id'],
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

    private function assignAccountToActiveBranches(int $accountId): void
    {
        Branch::query()
            ->where('is_active', true)
            ->pluck('id')
            ->each(function ($branchId) use ($accountId): void {
                BranchAccount::query()->updateOrCreate(
                    [
                        'branch_id' => (int) $branchId,
                        'account_id' => $accountId,
                    ],
                    ['is_active' => true],
                );
            });
    }

    private function formView(
        ExpenseCategory $category,
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

        return view('backend.modules.expense_category.modal', compact(
            'category',
            'action',
            'method',
            'title',
            'accounts',
        ));
    }

    private function record(int $id): ExpenseCategory
    {
        return ExpenseCategory::query()
            ->where('category_scope', 'GENERAL')
            ->findOrFail($id);
    }

    private function accountLabel(ExpenseCategory $category): string
    {
        if (! $category->expenseAccount) {
            return '<span class="text-danger">Not assigned</span>';
        }

        return '<strong>'.e($category->expenseAccount->code).'</strong> — '
            .e($category->expenseAccount->name);
    }

    private function statusBadge(ExpenseCategory $category): string
    {
        $class = $category->is_active
            ? 'bg-dark-success-gradient'
            : 'bg-dark-warning-gradient';
        $label = $category->is_active ? 'Active' : 'Inactive';

        return '<span class="badge '.$class.' text-white px-16 py-7 radius-4">'.$label.'</span>';
    }

    private function actions(
        ExpenseCategory $category,
        bool $canEdit,
        bool $canDelete,
    ): string {
        $actions = '<div class="d-inline-flex justify-content-end align-items-center gap-1 w-100">';

        if ($canEdit) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center bg-success-focus text-success-main AjaxModal" '
                .'data-ajax-modal="'.e(route('finance.expense-categories.editModal', $category)).'" '
                .'data-size="md" data-onload="GeneralExpenseCategoriesIndex.onLoad" '
                .'data-onsuccess="GeneralExpenseCategoriesIndex.onSaved" title="Edit category">'
                .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
        }

        if ($canDelete) {
            $actions .= '<a href="#" class="w-32-px h-32-px rounded-circle d-inline-flex '
                .'align-items-center justify-content-center bg-danger-focus text-danger-main '
                .'btn-general-expense-category-delete" '
                .'data-url="'.e(route('finance.expense-categories.destroy', $category)).'" '
                .'title="Delete category">'
                .'<iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></a>';
        }

        return $actions.'</div>';
    }
}
