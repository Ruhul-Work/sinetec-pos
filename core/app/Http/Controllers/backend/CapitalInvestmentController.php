<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\CapitalInvestmentStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidCapitalInvestmentException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Finance\CapitalInvestment;
use App\Services\Finance\CapitalInvestmentService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CapitalInvestmentController extends Controller
{
    public function __construct(private readonly CapitalInvestmentService $investments) {}

    public function index(): View
    {
        return view('backend.modules.capital_investment.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $query = DB::table('finance_capital_investments as investment')
            ->join('branches as branch', 'branch.id', '=', 'investment.branch_id')
            ->join('accounts as cash', 'cash.id', '=', 'investment.cash_bank_account_id')
            ->join('accounts as capital', 'capital.id', '=', 'investment.capital_account_id')
            ->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('investment.branch_id', $id))
            ->select(['investment.id', 'investment.investment_no', 'investment.investment_type', 'investment.investment_date', 'investment.amount', 'investment.status', 'branch.name as branch_name', 'branch.code as branch_code', 'cash.name as cash_name', 'cash.code as cash_code', 'capital.name as capital_name', 'capital.code as capital_code']);
        $total = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('investment.investment_no', 'like', "%{$search}%")->orWhere('branch.name', 'like', "%{$search}%")->orWhere('cash.name', 'like', "%{$search}%")->orWhere('capital.name', 'like', "%{$search}%"));
        }
        $rows = $query->orderByDesc('investment.id')->get();
        $data = $rows->map(function (object $row): array {
            $status = CapitalInvestmentStatus::from($row->status);
            $type = $row->investment_type === 'PARTNER_CAPITAL' ? 'Partner Capital' : 'Owner Capital';
            $view = route('finance.capital-investments.show', $row->id);

            return [
                $row->id,
                '<a href="'.e($view).'" class="fw-semibold text-primary-600">'.e($row->investment_no).'</a>',
                '<span class="badge bg-light text-dark">'.e($type).'</span>',
                e(date('d M Y', strtotime($row->investment_date))),
                '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
                '<strong>'.e($row->cash_name).'</strong><br><code>'.e($row->cash_code).'</code>',
                '<strong>'.e($row->capital_name).'</strong><br><code>'.e($row->capital_code).'</code>',
                '<div class="text-end fw-semibold">'.number_format((float) $row->amount, 2).'</div>',
                '<span class="badge '.$status->badgeClass().' text-white px-12 py-7 radius-4">'.e($status->label()).'</span>',
                '<div class="d-inline-flex justify-content-end w-100"><a href="'.e($view).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a></div>',
            ];
        });

        return response()->json(['draw' => (int) $request->input('draw'), 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $data->count(), 'aaData' => $data->all()]);
    }

    public function create(): View
    {
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)
            ->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name', 'code']);
        $branchId = (int) (current_branch_id() ?: $branches->first()?->id);
        abort_if($branchId < 1, 422, 'An active branch is required before recording an investment.');

        return view('backend.modules.capital_investment.form', [
            'branches' => $branches,
            'branchId' => $branchId,
            'cashAccounts' => $this->cashAccounts($branchId),
            'capitalAccounts' => $this->capitalAccounts($branchId),
        ]);
    }

    public function branchAccounts(int $branch): JsonResponse
    {
        $this->assertBranchScope($branch);

        return response()->json(['cash_accounts' => $this->cashAccounts($branch), 'capital_accounts' => $this->capitalAccounts($branch)]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBranchScope((int) $data['branch_id']);
        try {
            $investment = $this->investments->create($data, (int) $request->user()->id);
        } catch (InvalidCapitalInvestmentException|FinanceConfigurationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'msg' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'msg' => 'Capital investment saved as Draft.',
                'redirect_url' => route('finance.capital-investments.show', $investment),
            ]);
        }

        return redirect()->route('finance.capital-investments.show', $investment)->with('success', 'Capital investment saved as Draft. Review and post it to create the finance journal Draft.');
    }

    public function show(int $investment): View
    {
        $investment = $this->record($investment)->load(['branch', 'cashBankAccount', 'capitalAccount', 'createdBy', 'postedBy', 'voidedBy']);
        $journal = JournalEntry::query()->where('source_type', 'capital_investment')->where('source_id', $investment->id)->first();

        return view('backend.modules.capital_investment.show', compact('investment', 'journal'));
    }

    public function post(int $investment, Request $request): RedirectResponse
    {
        try {
            $investment = $this->investments->post($this->record($investment), (int) $request->user()->id);
        } catch (InvalidCapitalInvestmentException|FinanceConfigurationException|InvalidJournalDraftException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('finance.capital-investments.show', $investment)->with('success', 'Capital investment posted. A finance journal Draft is ready for accountant approval.');
    }

    public function void(int $investment, Request $request): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->investments->voidDraft($this->record($investment), $data['void_reason'], (int) $request->user()->id);
        } catch (InvalidCapitalInvestmentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft capital investment voided. It did not affect Cash/Bank or account balances.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'investment_type' => ['required', Rule::in(['OWNER_CAPITAL', 'PARTNER_CAPITAL'])],
            'cash_bank_account_id' => ['required', 'integer', 'different:capital_account_id', Rule::exists('accounts', 'id')],
            'capital_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'investment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function record(int $id): CapitalInvestment
    {
        $investment = CapitalInvestment::query()->findOrFail($id);
        $this->assertBranchScope((int) $investment->branch_id);

        return $investment;
    }

    private function assertBranchScope(int $branchId): void
    {
        if (current_branch_id() && current_branch_id() !== $branchId) {
            abort(403, 'You cannot access another branch’s capital investment.');
        }
    }

    /** @return Collection<int, object> */
    private function cashAccounts(int $branchId)
    {
        return DB::table('branch_accounts as assignment')->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->where('assignment.branch_id', $branchId)->where('assignment.is_active', true)->where('account.is_active', true)->where('account.is_cash_bank_account', true)
            ->orderBy('account.code')->get(['account.id', 'account.code', 'account.name']);
    }

    /** @return Collection<int, object> */
    private function capitalAccounts(int $branchId)
    {
        return DB::table('branch_accounts as assignment')->join('accounts as account', 'account.id', '=', 'assignment.account_id')->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->where('assignment.branch_id', $branchId)->where('assignment.is_active', true)->where('account.is_active', true)->where('type.code', 'EQUITY')
            ->orderBy('account.code')->get(['account.id', 'account.code', 'account.name']);
    }
}
