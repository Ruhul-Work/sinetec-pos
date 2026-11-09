<?php

namespace App\Http\Controllers\backend;

use App\Exceptions\Finance\InvalidOpeningBalanceException;
use App\Http\Controllers\Controller;
use App\Models\backend\AccountOpeningBalance;
use App\Services\Finance\FinanceOpeningBalanceService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceOpeningBalanceController extends Controller
{
    public function __construct(private readonly FinanceOpeningBalanceService $openings) {}

    public function index(): View
    {
        return view('backend.modules.finance_opening_balance.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $query = DB::table('account_opening_balances as opening')
            ->join('fiscal_years as year', 'year.id', '=', 'opening.fiscal_year_id')
            ->join('branches as branch', 'branch.id', '=', 'opening.branch_id')
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('opening.branch_id', $branchId))
            ->groupBy('opening.fiscal_year_id', 'opening.branch_id', 'year.name', 'year.start_date', 'year.end_date', 'branch.name', 'branch.code')
            ->select([
                'opening.fiscal_year_id', 'opening.branch_id', 'year.name as fiscal_year_name', 'year.start_date', 'year.end_date',
                'branch.name as branch_name', 'branch.code as branch_code', DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(opening.debit), 0) as total_debit'), DB::raw('COALESCE(SUM(opening.credit), 0) as total_credit'),
                DB::raw('MIN(opening.status) as min_status'), DB::raw('MAX(opening.status) as max_status'), DB::raw('MAX(opening.posted_at) as posted_at'),
            ]);
        $total = (clone $query)->get()->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->havingRaw('year.name LIKE ? OR branch.name LIKE ? OR branch.code LIKE ?', ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }
        $rows = $query->orderByDesc('year.start_date')->orderBy('branch.name')->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $status = $row->min_status === 'POSTED' && $row->max_status === 'POSTED' ? 'POSTED' : 'DRAFT';
            $view = route('finance.opening-balances.show', ['fiscalYear' => $row->fiscal_year_id, 'branch' => $row->branch_id]);
            $actions = '<div class="d-inline-flex justify-content-end gap-1 w-100">'
                .'<a href="'.e($view).'" class="w-32-px h-32-px rounded-circle '
                .'d-inline-flex align-items-center justify-content-center bg-info-focus '
                .'text-info-main" title="View">'
                .'<iconify-icon icon="mdi:eye-outline"></iconify-icon></a>';
            if ($status === 'DRAFT' && ($user?->canDo('finance.opening-balances.update', 'edit') ?? false)) {
                $edit = route('finance.opening-balances.edit', ['fiscalYear' => $row->fiscal_year_id, 'branch' => $row->branch_id]);
                $actions .= '<a href="'.e($edit).'" class="w-32-px h-32-px rounded-circle '
                    .'d-inline-flex align-items-center justify-content-center bg-success-focus '
                    .'text-success-main" title="Edit Draft">'
                    .'<iconify-icon icon="lucide:edit"></iconify-icon></a>';
            }
            $actions .= '</div>';

            return [
                $row->fiscal_year_id.'-'.$row->branch_id,
                '<strong>'.e($row->fiscal_year_name).'</strong><br><small class="text-muted">'
                    .e(date('d M Y', strtotime($row->start_date)))
                    .' – '.e(date('d M Y', strtotime($row->end_date)))
                    .'</small>',
                '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
                (int) $row->line_count,
                '<div class="text-end fw-semibold">'.number_format((float) $row->total_debit, 2).'</div>',
                '<div class="text-end fw-semibold">'.number_format((float) $row->total_credit, 2).'</div>',
                $status === 'POSTED'
                    ? '<span class="badge text-sm fw-semibold bg-dark-success-gradient px-12 py-7 radius-4 text-white">Posted</span>'
                    : '<span class="badge text-sm fw-semibold bg-dark-warning-gradient px-12 py-7 radius-4 text-white">Draft</span>',
                $actions,
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $data->count(),
            'aaData' => $data->all(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->formView((int) $request->input('fiscal_year_id'), (int) $request->input('branch_id'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBranchScope((int) $data['branch_id']);
        if (AccountOpeningBalance::query()
            ->where('fiscal_year_id', $data['fiscal_year_id'])
            ->where('branch_id', $data['branch_id'])
            ->exists()) {
            return back()
                ->withInput()
                ->with(
                    'error',
                    'An opening balance set already exists for this Fiscal Year and Branch. '
                    .'Open that Draft from the list to edit it.',
                );
        }
        try {
            $this->openings->saveDraft((int) $data['fiscal_year_id'], (int) $data['branch_id'], $data['lines'], (int) $request->user()->id);
        } catch (InvalidOpeningBalanceException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('finance.opening-balances.edit', ['fiscalYear' => $data['fiscal_year_id'], 'branch' => $data['branch_id']])
            ->with('success', 'Balanced opening balance Draft saved. Review the lines before posting.');
    }

    public function show(int $fiscalYear, int $branch): View
    {
        $this->assertBranchScope($branch);

        return $this->formView($fiscalYear, $branch, true);
    }

    public function edit(int $fiscalYear, int $branch): View
    {
        $this->assertBranchScope($branch);
        $status = $this->scopeStatus($fiscalYear, $branch);
        abort_if($status === 'POSTED', 409, 'Posted opening balances cannot be edited.');

        return $this->formView($fiscalYear, $branch);
    }

    public function update(Request $request, int $fiscalYear, int $branch): RedirectResponse
    {
        $this->assertBranchScope($branch);
        $data = $this->validated($request);
        if ((int) $data['fiscal_year_id'] !== $fiscalYear || (int) $data['branch_id'] !== $branch) {
            abort(422, 'Opening Balance scope cannot be changed while editing. Create a separate opening set instead.');
        }
        try {
            $this->openings->saveDraft($fiscalYear, $branch, $data['lines'], (int) $request->user()->id);
        } catch (InvalidOpeningBalanceException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening balance Draft updated.');
    }

    public function post(int $fiscalYear, int $branch, Request $request): RedirectResponse
    {
        $this->assertBranchScope($branch);
        try {
            $this->openings->post($fiscalYear, $branch, (int) $request->user()->id);
        } catch (InvalidOpeningBalanceException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('finance.opening-balances.show', compact('fiscalYear', 'branch'))
            ->with('success', 'Opening balance posted. It is now included in Account Ledger balances.');
    }

    private function formView(int $fiscalYearId, int $branchId, bool $readonly = false): View
    {
        $fiscalYears = DB::table('fiscal_years')->where('is_active', true)->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date']);
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $id) => $query->where('id', $id))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $fiscalYearId = $fiscalYearId ?: (int) $fiscalYears->first()?->id;
        $branchId = $branchId ?: (int) $branches->first()?->id;
        abort_if($fiscalYearId < 1 || $branchId < 1, 422, 'An active fiscal year and branch are required before entering opening balance.');

        $this->assertBranchScope($branchId);
        $lines = AccountOpeningBalance::query()->with('account.type')
            ->where('fiscal_year_id', $fiscalYearId)->where('branch_id', $branchId)->orderBy('account_id')->get();
        $status = $lines->isNotEmpty() && $lines->every(fn (AccountOpeningBalance $line): bool => $line->status === 'POSTED') ? 'POSTED' : 'DRAFT';
        $readonly = $readonly || $status === 'POSTED';
        $accounts = DB::table('branch_accounts as assignment')->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->where('assignment.branch_id', $branchId)->where('assignment.is_active', true)->where('account.is_active', true)->where('account.currency', 'BDT')
            ->orderBy('account.code')->get(['account.id', 'account.code', 'account.name', 'type.normal_balance']);

        return view('backend.modules.finance_opening_balance.form', compact(
            'fiscalYears',
            'branches',
            'fiscalYearId',
            'branchId',
            'lines',
            'accounts',
            'status',
            'readonly',
        ));
    }

    /** @return array{fiscal_year_id: int, branch_id: int, lines: array<int, array{account_id: mixed, debit: mixed, credit: mixed}>} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where('is_active', true)],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function scopeStatus(int $fiscalYearId, int $branchId): string
    {
        return AccountOpeningBalance::query()->where('fiscal_year_id', $fiscalYearId)->where('branch_id', $branchId)->where('status', 'POSTED')->exists()
            ? 'POSTED'
            : 'DRAFT';
    }

    private function assertBranchScope(int $branchId): void
    {
        if (current_branch_id() && current_branch_id() !== $branchId) {
            abort(403, 'You cannot access another branch’s opening balance.');
        }
    }
}
