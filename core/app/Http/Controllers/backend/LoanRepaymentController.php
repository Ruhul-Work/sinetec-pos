<?php
namespace App\Http\Controllers\backend;

use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Http\Controllers\Controller;
use App\Models\Finance\LoanReceipt;
use App\Models\Finance\LoanRepayment;
use App\Services\Finance\LoanRepaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoanRepaymentController extends Controller
{
    public function __construct(
        private readonly LoanRepaymentService $repayments,
    ) {}

    public function index(): View
    {
        return view('backend.modules.loan_repayment.index');
    }

    public function create(): View
    {
        $receipts = LoanReceipt::query()
            ->where('status', 'POSTED')
            ->when(current_branch_id(), fn($query, int $branchId) => $query->where('branch_id', $branchId))
            ->orderByDesc('receipt_date')
            ->get();

        $accounts = DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('account.is_cash_bank_account', true)
            ->when(current_branch_id(), fn($query, int $branchId) => $query->where('assignment.branch_id', $branchId))
            ->distinct()
            ->orderBy('account.code')
            ->get(['account.id', 'account.code', 'account.name']);

        return view('backend.modules.loan_repayment.form', compact('receipts', 'accounts'));
    }

    public function due(int $receipt): JsonResponse
    {
        return response()->json([
            'due' => $this->repayments->due($this->receipt($receipt)),
        ]);
    }

    public function store(Request $request): JsonResponse | RedirectResponse
    {
        $data = $request->validate([
            'loan_receipt_id'      => ['required', 'integer', Rule::exists('finance_loan_receipts', 'id')],
            'cash_bank_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'repayment_date'       => ['required', 'date'],
            'amount'               => ['required', 'numeric', 'gt:0'],
            'reference_no'         => ['nullable', 'string', 'max:100'],
            'note'                 => ['nullable', 'string', 'max:5000'],
        ]);

        // Protect against a crafted receipt ID from a different branch.
        $this->receipt((int) $data['loan_receipt_id']);

        try {
            $repayment = $this->repayments->create($data, (int) $request->user()->id);
        } catch (FinanceConfigurationException | InvalidJournalDraftException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['msg' => $exception->getMessage()], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success'      => true,
                'redirect_url' => route('finance.loan-repayments.show', $repayment),
            ]);
        }

        return redirect()->route('finance.loan-repayments.show', $repayment);
    }

    public function show(int $repayment): View
    {
        $repayment = $this->repayment($repayment)->load(['loanReceipt', 'cashBankAccount']);
        $journal   = DB::table('journal_entries')
            ->where('source_type', 'loan_repayment')
            ->where('source_id', $repayment->id)
            ->first();

        return view('backend.modules.loan_repayment.show', compact('repayment', 'journal'));
    }

    public function post(int $repayment, Request $request): RedirectResponse
    {
        try {
            $repayment = $this->repayments->post(
                $this->repayment($repayment),
                (int) $request->user()->id,
            );
        } catch (FinanceConfigurationException | InvalidJournalDraftException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('finance.loan-repayments.show', $repayment)
            ->with('success', 'Loan repayment posted; Journal Draft created.');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $rows = LoanRepayment::query()
            ->with(['loanReceipt', 'cashBankAccount'])
            ->when(current_branch_id(), fn($query, int $branchId) => $query->where('branch_id', $branchId))
            ->latest()
            ->get();

        $data = $rows->map(fn(LoanRepayment $repayment): array=> [
            $repayment->id,
            '<a class="fw-semibold text-primary-600" '
            . 'href="' . e(route('finance.loan-repayments.show', $repayment)) . '">'
            . e($repayment->repayment_no)
            . '</a>',
            e($repayment->loanReceipt->loan_no),
            e($repayment->repayment_date->format('d M Y')),
            e($repayment->cashBankAccount->code . ' — ' . $repayment->cashBankAccount->name),
            '<div class="text-end">' . number_format((float) $repayment->amount, 2) . '</div>',
            '<span class="badge '
            . ($repayment->status === 'POSTED'
                    ? 'bg-dark-success-gradient'
                    : 'bg-dark-warning-gradient')
            . ' text-white">' . e($repayment->status) . '</span>',
        ])->all();

        return response()->json([
            'draw'                 => (int) $request->input('draw'),
            'iTotalRecords'        => count($data),
            'iTotalDisplayRecords' => count($data),
            'aaData'               => $data,
        ]);
    }

    private function receipt(int $id): LoanReceipt
    {
        return LoanReceipt::query()
            ->when(current_branch_id(), fn($query, int $branchId) => $query->where('branch_id', $branchId))
            ->findOrFail($id);
    }

    private function repayment(int $id): LoanRepayment
    {
        return LoanRepayment::query()
            ->when(current_branch_id(), fn($query, int $branchId) => $query->where('branch_id', $branchId))
            ->findOrFail($id);
    }
}
