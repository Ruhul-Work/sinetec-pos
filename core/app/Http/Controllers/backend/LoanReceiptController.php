<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\LoanReceiptStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Finance\InvalidLoanReceiptException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Finance\LoanReceipt;
use App\Services\Finance\LoanReceiptService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoanReceiptController extends Controller
{
    public function __construct(private readonly LoanReceiptService $loans) {}

    public function index(): View
    {
        return view('backend.modules.loan_receipt.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $query = DB::table('finance_loan_receipts as loan')->join('branches as branch', 'branch.id', '=', 'loan.branch_id')->join('accounts as cash', 'cash.id', '=', 'loan.cash_bank_account_id')->join('accounts as liability', 'liability.id', '=', 'loan.loan_account_id')
            ->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('loan.branch_id', $id))
            ->select(['loan.id', 'loan.loan_no', 'loan.loan_type', 'loan.receipt_date', 'loan.amount', 'loan.status', 'branch.name as branch_name', 'branch.code as branch_code', 'cash.name as cash_name', 'cash.code as cash_code', 'liability.name as loan_account_name', 'liability.code as loan_account_code']);
        $total = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('loan.loan_no', 'like', "%{$search}%")->orWhere('branch.name', 'like', "%{$search}%")->orWhere('cash.name', 'like', "%{$search}%")->orWhere('liability.name', 'like', "%{$search}%"));
        }
        $rows = $query->orderByDesc('loan.id')->get();
        $data = $rows->map(function (object $row): array {
            $status = LoanReceiptStatus::from($row->status);
            $type = $row->loan_type === 'PARTNER_LOAN' ? 'Partner Loan' : 'Director Loan';
            $url = route('finance.loan-receipts.show', $row->id);

            return [$row->id, '<a href="'.e($url).'" class="fw-semibold text-primary-600">'.e($row->loan_no).'</a>', '<span class="badge bg-light text-dark">'.e($type).'</span>', e(date('d M Y', strtotime($row->receipt_date))), '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>', '<strong>'.e($row->cash_name).'</strong><br><code>'.e($row->cash_code).'</code>', '<strong>'.e($row->loan_account_name).'</strong><br><code>'.e($row->loan_account_code).'</code>', '<div class="text-end fw-semibold">'.number_format((float) $row->amount, 2).'</div>', '<span class="badge '.$status->badgeClass().' text-white px-12 py-7 radius-4">'.e($status->label()).'</span>', '<div class="d-inline-flex justify-content-end w-100"><a href="'.e($url).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a></div>'];
        });

        return response()->json(['draw' => (int) $request->input('draw'), 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $data->count(), 'aaData' => $data->all()]);
    }

    public function create(): View
    {
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name', 'code']);
        $branchId = (int) (current_branch_id() ?: $branches->first()?->id);
        abort_if($branchId < 1, 422, 'An active branch is required before recording a loan.');

        return view('backend.modules.loan_receipt.form', ['branches' => $branches, 'branchId' => $branchId, 'cashAccounts' => $this->cashAccounts($branchId), 'loanAccounts' => $this->loanAccounts($branchId)]);
    }

    public function branchAccounts(int $branch): JsonResponse
    {
        $this->assertBranchScope($branch);

        return response()->json(['cash_accounts' => $this->cashAccounts($branch), 'loan_accounts' => $this->loanAccounts($branch)]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBranchScope((int) $data['branch_id']);
        try {
            $loan = $this->loans->create($data, (int) $request->user()->id);
        } catch (InvalidLoanReceiptException|FinanceConfigurationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'msg' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'msg' => 'Loan receipt saved as Draft.', 'redirect_url' => route('finance.loan-receipts.show', $loan)]);
        }

        return redirect()->route('finance.loan-receipts.show', $loan)->with('success', 'Loan receipt saved as Draft.');
    }

    public function show(int $loan): View
    {
        $loan = $this->record($loan)->load(['branch', 'cashBankAccount', 'loanAccount', 'createdBy', 'postedBy', 'voidedBy']);
        $journal = JournalEntry::query()->where('source_type', 'loan_receipt')->where('source_id', $loan->id)->first();

        return view('backend.modules.loan_receipt.show', compact('loan', 'journal'));
    }

    public function post(int $loan, Request $request): RedirectResponse
    {
        try {
            $loan = $this->loans->post($this->record($loan), (int) $request->user()->id);
        } catch (InvalidLoanReceiptException|FinanceConfigurationException|InvalidJournalDraftException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('finance.loan-receipts.show', $loan)->with('success', 'Loan receipt posted. A finance journal Draft is ready for accountant approval.');
    }

    public function void(int $loan, Request $request): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->loans->voidDraft($this->record($loan), $data['void_reason'], (int) $request->user()->id);
        } catch (InvalidLoanReceiptException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft loan receipt voided. It did not affect Cash/Bank or account balances.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(['branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)], 'loan_type' => ['required', Rule::in(['DIRECTOR_LOAN', 'PARTNER_LOAN'])], 'cash_bank_account_id' => ['required', 'integer', 'different:loan_account_id', Rule::exists('accounts', 'id')], 'loan_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')], 'receipt_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'reference_no' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:5000']]);
    }

    private function record(int $id): LoanReceipt
    {
        $loan = LoanReceipt::query()->findOrFail($id);
        $this->assertBranchScope((int) $loan->branch_id);

        return $loan;
    }

    private function assertBranchScope(int $branchId): void
    {
        if (current_branch_id() && current_branch_id() !== $branchId) {
            abort(403, 'You cannot access another branch’s loan receipt.');
        }
    }

    private function cashAccounts(int $branchId)
    {
        return DB::table('branch_accounts as assignment')->join('accounts as account', 'account.id', '=', 'assignment.account_id')->where('assignment.branch_id', $branchId)->where('assignment.is_active', true)->where('account.is_active', true)->where('account.is_cash_bank_account', true)->orderBy('account.code')->get(['account.id', 'account.code', 'account.name']);
    }

    private function loanAccounts(int $branchId)
    {
        return DB::table('branch_accounts as assignment')->join('accounts as account', 'account.id', '=', 'assignment.account_id')->join('account_types as type', 'type.id', '=', 'account.account_type_id')->where('assignment.branch_id', $branchId)->where('assignment.is_active', true)->where('account.is_active', true)->where('type.code', 'LIABILITY')->orderBy('account.code')->get(['account.id', 'account.code', 'account.name']);
    }
}
