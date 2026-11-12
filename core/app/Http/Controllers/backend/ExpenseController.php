<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\ExpenseStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidExpenseException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Services\Finance\ExpenseService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
    ) {}

    public function index(): View
    {
        return view('backend.modules.expense.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $draw = (int) $request->input('draw');
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $search = trim((string) $request->input('search.value', ''));

        $query = DB::table('expenses as expense')
            ->join('branches as branch', 'branch.id', '=', 'expense.branch_id')
            ->leftJoin('expense_categories as category', 'category.id', '=', 'expense.expense_category_id')
            ->leftJoin('expense_payments as payment', 'payment.expense_id', '=', 'expense.id')
            ->leftJoin('accounts as account', 'account.id', '=', 'payment.account_id')
            ->leftJoin(
                'part_production_batches as production_batch',
                'production_batch.id',
                '=',
                'expense.part_production_batch_id',
            )
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('expense.branch_id', $branchId),
            )
            ->select([
                'expense.id',
                'expense.expense_no',
                'expense.expense_date',
                'expense.payee',
                'expense.amount',
                'expense.status',
                'category.name as category_name',
                'branch.name as branch_name',
                'branch.code as branch_code',
                'account.name as account_name',
                'account.code as account_code',
                'production_batch.batch_no',
            ]);

        $total = (clone $query)->count();

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('expense.expense_no', 'like', "%{$search}%")
                    ->orWhere('category.name', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%")
                    ->orWhere('account.name', 'like', "%{$search}%")
                    ->orWhere('production_batch.batch_no', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query
            ->orderByDesc('expense.id')
            ->skip($start)
            ->take($length)
            ->get();

        $data = $rows->map(function (object $row): array {
            $status = ExpenseStatus::from($row->status);
            $source = $row->batch_no
                ? '<small class="text-muted d-block">Production Batch</small><strong>'.e($row->batch_no).'</strong>'
                : '<span class="text-muted">General Expense</span>';
            $account = $row->account_name
                ? '<strong>'.e($row->account_name).'</strong><br><code>'.e($row->account_code).'</code>'
                : '<span class="text-danger">Missing</span>';
            $viewUrl = route('finance.expenses.show', $row->id);

            return [
                $row->id,
                '<a class="fw-semibold text-primary-600" href="'.e($viewUrl).'">'.e($row->expense_no).'</a>',
                e(date('d M Y', strtotime($row->expense_date))),
                '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
                e($row->category_name ?: 'Production Cost Settlement'),
                $source,
                $account,
                '<div class="text-end fw-semibold">'.number_format((float) $row->amount, 2).'</div>',
                '<span class="badge '.$status->badgeClass().' text-white px-12 py-7 radius-4">'
                    .e($status->label()).'</span>',
                '<div class="d-inline-flex justify-content-end w-100">'
                    .'<a href="'.e($viewUrl).'" class="w-32-px h-32-px rounded-circle '
                    .'d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" '
                    .'title="View expense"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a></div>',
            ];
        });

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $selectedProductionBatchId = $request->integer('production_batch');
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('id', $branchId),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $productionBranchId = $selectedProductionBatchId
            ? $this->productionBatchBranchId($selectedProductionBatchId)
            : null;
        $branchId = (int) (
            $productionBranchId
            ?: $request->integer('branch_id')
            ?: current_branch_id()
            ?: $branches->first()?->id
        );

        abort_if($branchId < 1, 422, 'An active branch is required before recording an expense.');
        $this->assertBranchScope($branchId);

        return view('backend.modules.expense.form', [
            'branches' => $branches,
            'branchId' => $branchId,
            'selectedProductionBatchId' => $selectedProductionBatchId,
            'productionBatch' => $selectedProductionBatchId
                ? $this->productionBatchForPayment($selectedProductionBatchId)
                : null,
            'productionAllocations' => $selectedProductionBatchId
                ? $this->productionAllocations($selectedProductionBatchId)
                : collect(),
            'options' => $this->optionsForBranch($branchId),
        ]);
    }

    public function branchOptions(int $branch): JsonResponse
    {
        $this->assertBranchScope($branch);

        return response()->json($this->optionsForBranch($branch));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBranchScope((int) $data['branch_id']);

        try {
            $expense = $this->expenses->create($data, (int) $request->user()->id);
        } catch (InvalidExpenseException|FinanceConfigurationException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'msg' => $exception->getMessage(),
                ], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = 'Expense saved as Draft. Review it before recording the payment.';
        $redirectUrl = route('finance.expenses.show', $expense);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'msg' => $message,
                'redirect_url' => $redirectUrl,
            ]);
        }

        return redirect($redirectUrl)->with('success', $message);
    }

    public function show(int $expense): View
    {
        $expense = $this->record($expense)->load([
            'branch',
            'category.expenseAccount',
            'productionBatch.bom.part',
            'items.productionExpense.category',
            'payment.account',
            'payment.paymentType',
            'createdBy',
            'postedBy',
            'voidedBy',
            'journal',
        ]);

        return view('backend.modules.expense.show', compact('expense'));
    }

    public function post(int $expense, Request $request): RedirectResponse
    {
        try {
            $expense = $this->expenses->post(
                $this->record($expense),
                (int) $request->user()->id,
            );
        } catch (InvalidExpenseException|FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('finance.expenses.show', $expense)
            ->with(
                'success',
                'Expense payment recorded. Its finance journal is Draft and requires approval and posting.',
            );
    }

    public function void(int $expense, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->expenses->voidDraft(
                $this->record($expense),
                $data['void_reason'],
                (int) $request->user()->id,
            );
        } catch (InvalidExpenseException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Draft expense voided. No Cash/Bank or ledger balance was changed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $sourceMode = strtoupper(trim((string) $request->input('source_mode', 'GENERAL')));

        $data = $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'source_mode' => ['required', Rule::in(['GENERAL', 'PRODUCTION'])],
            'part_production_batch_id' => [
                Rule::requiredIf($sourceMode === 'PRODUCTION'),
                'nullable',
                'integer',
                'exists:part_production_batches,id',
            ],
            'production_items' => [Rule::requiredIf($sourceMode === 'PRODUCTION'), 'nullable', 'array'],
            'production_items.*.selected' => ['nullable', 'boolean'],
            'production_items.*.part_production_expense_id' => [
                'required_with:production_items.*.selected',
                'integer',
                'distinct',
                'exists:part_production_expenses,id',
            ],
            'production_items.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'expense_category_id' => [
                Rule::requiredIf($sourceMode === 'GENERAL'),
                'nullable',
                'integer',
                'exists:expense_categories,id',
            ],
            'expense_date' => ['required', 'date'],
            'payee' => ['nullable', 'string', 'max:150'],
            'amount' => [
                Rule::requiredIf($sourceMode === 'GENERAL'),
                'nullable',
                'numeric',
                'gt:0',
            ],
            'payment_type_id' => ['required', 'integer', 'exists:payment_types,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['part_production_batch_id'] = $sourceMode === 'PRODUCTION'
            ? (int) $data['part_production_batch_id']
            : null;
        $data['expense_category_id'] = $sourceMode === 'GENERAL'
            ? (int) $data['expense_category_id']
            : null;

        return $data;
    }

    /** @return array<string, mixed> */
    private function optionsForBranch(int $branchId): array
    {
        return [
            'categories' => $this->generalCategories($branchId),
            'cash_accounts' => $this->cashBankAccounts($branchId),
            'payment_types' => DB::table('payment_types')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /** @return Collection<int, object> */
    private function productionAllocations(int $batchId): Collection
    {
        return DB::table('part_production_expenses as production_expense')
            ->join(
                'production_expense_categories as category',
                'category.id',
                '=',
                'production_expense.production_expense_category_id',
            )
            ->leftJoinSub(
                DB::table('expense_items as settlement_item')
                    ->join('expenses as settlement', 'settlement.id', '=', 'settlement_item.expense_id')
                    ->where('settlement.status', '!=', ExpenseStatus::Void->value)
                    ->groupBy('settlement_item.part_production_expense_id')
                    ->selectRaw(
                        'settlement_item.part_production_expense_id, '
                        .'SUM(settlement_item.amount) as booked_amount',
                    ),
                'booked',
                'booked.part_production_expense_id',
                '=',
                'production_expense.id',
            )
            ->where('production_expense.part_production_batch_id', $batchId)
            ->orderBy('category.name')
            ->get([
                'production_expense.id',
                'production_expense.amount',
                'production_expense.note',
                'category.name as category_name',
                DB::raw('COALESCE(booked.booked_amount, 0) as booked_amount'),
                DB::raw(
                    'GREATEST(production_expense.amount - COALESCE(booked.booked_amount, 0), 0) '
                    .'as remaining_amount',
                ),
            ])
            ->filter(fn (object $allocation): bool => (float) $allocation->remaining_amount > 0);
    }

    /** @return Collection<int, object> */
    private function generalCategories(int $branchId): Collection
    {
        return DB::table('expense_categories as category')
            ->join('accounts as account', 'account.id', '=', 'category.expense_account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('branch_accounts as assignment', function ($join) use ($branchId): void {
                $join
                    ->on('assignment.account_id', '=', 'account.id')
                    ->where('assignment.branch_id', '=', $branchId)
                    ->where('assignment.is_active', '=', true);
            })
            ->where('category.is_active', true)
            ->where('category.category_scope', 'GENERAL')
            ->where('account.is_active', true)
            ->where('type.code', 'EXPENSE')
            ->orderBy('category.name')
            ->get([
                'category.id',
                'category.name',
                'category.code',
                'account.name as account_name',
                'account.code as account_code',
            ]);
    }

    /** @return Collection<int, object> */
    private function cashBankAccounts(int $branchId): Collection
    {
        return DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->where('assignment.branch_id', $branchId)
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('account.is_cash_bank_account', true)
            ->where('type.code', 'ASSET')
            ->orderBy('account.code')
            ->get(['account.id', 'account.code', 'account.name']);
    }

    private function record(int $id): Expense
    {
        $expense = Expense::query()->findOrFail($id);
        $this->assertBranchScope((int) $expense->branch_id);

        return $expense;
    }

    private function assertBranchScope(int $branchId): void
    {
        if (current_branch_id() && current_branch_id() !== $branchId) {
            abort(403, 'You cannot access another branch\'s expense document.');
        }
    }

    private function productionBatchBranchId(int $batchId): int
    {
        $branchId = DB::table('part_production_batches')
            ->where('id', $batchId)
            ->where('status', 'COMPLETED')
            ->value('branch_id');

        abort_unless($branchId, 404, 'The completed production batch does not exist.');
        $this->assertBranchScope((int) $branchId);

        return (int) $branchId;
    }

    private function productionBatchForPayment(int $batchId): object
    {
        return DB::table('part_production_batches as batch')
            ->join('part_boms as bom', 'bom.id', '=', 'batch.part_bom_id')
            ->join('parts as part', 'part.id', '=', 'bom.part_id')
            ->where('batch.id', $batchId)
            ->where('batch.status', 'COMPLETED')
            ->firstOrFail([
                'batch.id',
                'batch.batch_no',
                'batch.production_date',
                'part.name as part_name',
                'part.part_code',
            ]);
    }
}
