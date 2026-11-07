<?php

namespace App\Http\Controllers\backend;

use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Finance\CashBankTransfer;
use App\Services\Finance\CashBankTransferService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashBankTransferController extends Controller
{
    public function __construct(private readonly CashBankTransferService $transfers) {}

    public function index(): View
    {
        return view('backend.modules.cash_bank_transfer.index');
    }

    public function create(): View
    {
        $branches = $this->branches();
        $branchId = (int) (current_branch_id() ?: $branches->first()?->id);
        abort_if($branchId < 1, 422, 'An active branch is required before recording a transfer.');

        return view('backend.modules.cash_bank_transfer.form', [
            'branches' => $branches,
            'branchId' => $branchId,
            'accounts' => $this->accounts($branchId),
        ]);
    }

    public function accountsForBranch(int $branch): JsonResponse
    {
        $this->assertBranchScope($branch);

        return response()->json(['accounts' => $this->accounts($branch)]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'from_account_id' => ['required', 'integer', 'different:to_account_id', Rule::exists('accounts', 'id')],
            'to_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'transfer_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->assertBranchScope((int) $data['branch_id']);

        try {
            $transfer = $this->transfers->create($data, (int) $request->user()->id);
        } catch (FinanceConfigurationException|InvalidJournalDraftException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['msg' => $exception->getMessage()], 422);
            }

            return back()->withInput()->with('error', $exception->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'msg' => 'Transfer saved as Draft.',
                'redirect_url' => route('finance.cash-bank-transfers.show', $transfer),
            ]);
        }

        return redirect()->route('finance.cash-bank-transfers.show', $transfer);
    }

    public function show(int $transfer): View
    {
        $transfer = $this->record($transfer)->load(['branch', 'fromAccount', 'toAccount']);
        $journal = JournalEntry::query()
            ->where('source_type', 'cash_bank_transfer')
            ->where('source_id', $transfer->id)
            ->first();

        return view('backend.modules.cash_bank_transfer.show', compact('transfer', 'journal'));
    }

    public function post(int $transfer, Request $request): RedirectResponse
    {
        try {
            $transfer = $this->transfers->post(
                $this->record($transfer),
                (int) $request->user()->id,
            );
        } catch (FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('finance.cash-bank-transfers.show', $transfer)
            ->with('success', 'Transfer posted; finance Journal Draft created.');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $rows = DB::table('finance_cash_bank_transfers as transfer')
            ->join('branches as branch', 'branch.id', '=', 'transfer.branch_id')
            ->join('accounts as source', 'source.id', '=', 'transfer.from_account_id')
            ->join('accounts as destination', 'destination.id', '=', 'transfer.to_account_id')
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('transfer.branch_id', $branchId))
            ->orderByDesc('transfer.id')
            ->get([
                'transfer.*',
                'branch.name as branch_name',
                'source.name as from_name',
                'source.code as from_code',
                'destination.name as to_name',
                'destination.code as to_code',
            ]);

        $data = $rows->map(fn (object $row): array => [
            $row->id,
            '<a class="fw-semibold text-primary-600" href="'.e(route('finance.cash-bank-transfers.show', $row->id)).'">'.e($row->transfer_no).'</a>',
            e($row->transfer_date),
            e($row->branch_name),
            e($row->from_code.' — '.$row->from_name),
            e($row->to_code.' — '.$row->to_name),
            '<div class="text-end fw-semibold">'.number_format((float) $row->amount, 2).'</div>',
            '<span class="badge '.($row->status === 'POSTED' ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient').' text-white">'.e(ucfirst(strtolower($row->status))).'</span>',
        ])->all();

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => count($data),
            'iTotalDisplayRecords' => count($data),
            'aaData' => $data,
        ]);
    }

    private function record(int $id): CashBankTransfer
    {
        $transfer = CashBankTransfer::query()->findOrFail($id);
        $this->assertBranchScope((int) $transfer->branch_id);

        return $transfer;
    }

    private function assertBranchScope(int $branchId): void
    {
        if (current_branch_id() && current_branch_id() !== $branchId) {
            abort(403, 'You cannot access another branch\'s Cash / Bank Transfer.');
        }
    }

    /** @return Collection<int, object> */
    private function branches(): Collection
    {
        return DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /** @return Collection<int, object> */
    private function accounts(int $branchId): Collection
    {
        return DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->where('assignment.branch_id', $branchId)
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('account.is_cash_bank_account', true)
            ->orderBy('account.code')
            ->get(['account.id', 'account.code', 'account.name']);
    }
}
