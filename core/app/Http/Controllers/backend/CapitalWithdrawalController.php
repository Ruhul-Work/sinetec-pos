<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\CapitalWithdrawalStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidCapitalWithdrawalException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Finance\CapitalWithdrawal;
use App\Services\Finance\CapitalWithdrawalService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CapitalWithdrawalController extends Controller
{
    public function __construct(
        private readonly CapitalWithdrawalService $withdrawals,
    ) {}

    public function index(): View
    {
        return view('backend.modules.capital_withdrawal.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $query = DB::table('finance_capital_withdrawals as withdrawal')
            ->join('branches as branch', 'branch.id', '=', 'withdrawal.branch_id')
            ->join('accounts as cash', 'cash.id', '=', 'withdrawal.cash_bank_account_id')
            ->join('accounts as drawings', 'drawings.id', '=', 'withdrawal.drawings_account_id')
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('withdrawal.branch_id', $branchId),
            )
            ->select([
                'withdrawal.id',
                'withdrawal.withdrawal_no',
                'withdrawal.withdrawal_type',
                'withdrawal.withdrawal_date',
                'withdrawal.amount',
                'withdrawal.status',
                'branch.name as branch_name',
                'branch.code as branch_code',
                'cash.name as cash_name',
                'cash.code as cash_code',
                'drawings.name as drawings_name',
                'drawings.code as drawings_code',
            ]);

        $total = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('withdrawal.withdrawal_no', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%")
                    ->orWhere('cash.name', 'like', "%{$search}%")
                    ->orWhere('drawings.name', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('withdrawal.id')
            ->get()
            ->map(fn (object $row): array => $this->formatTableRow($row));

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $rows->count(),
            'aaData' => $rows->all(),
        ]);
    }

    public function create(): View
    {
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when(
                current_branch_id(),
                fn (Builder $query, int $branchId) => $query->where('id', $branchId),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $branchId = (int) (current_branch_id() ?: $branches->first()?->id);

        abort_if($branchId < 1, 422, 'An active branch is required before recording a withdrawal.');

        return view('backend.modules.capital_withdrawal.form', [
            'branches' => $branches,
            'branchId' => $branchId,
            'cashAccounts' => $this->cashAccounts($branchId),
            'drawingsAccounts' => $this->drawingsAccounts($branchId),
        ]);
    }

    public function branchAccounts(int $branch): JsonResponse
    {
        $this->scope($branch);

        return response()->json([
            'cash_accounts' => $this->cashAccounts($branch),
            'drawings_accounts' => $this->drawingsAccounts($branch),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $this->scope((int) $data['branch_id']);

        try {
            $withdrawal = $this->withdrawals->create($data, (int) $request->user()->id);
        } catch (InvalidCapitalWithdrawalException|FinanceConfigurationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'msg' => $e->getMessage(),
                ], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'msg' => 'Capital withdrawal saved as Draft.',
                'redirect_url' => route('finance.capital-withdrawals.show', $withdrawal),
            ]);
        }

        return redirect()
            ->route('finance.capital-withdrawals.show', $withdrawal)
            ->with('success', 'Capital withdrawal saved as Draft.');
    }

    public function show(int $withdrawal): View
    {
        $withdrawal = $this->record($withdrawal)->load([
            'branch',
            'cashBankAccount',
            'drawingsAccount',
            'voidedBy',
        ]);

        $journal = JournalEntry::query()
            ->where('source_type', 'capital_withdrawal')
            ->where('source_id', $withdrawal->id)
            ->first();

        return view('backend.modules.capital_withdrawal.show', compact('withdrawal', 'journal'));
    }

    public function post(int $withdrawal, Request $request): RedirectResponse
    {
        try {
            $withdrawal = $this->withdrawals->post(
                $this->record($withdrawal),
                (int) $request->user()->id,
            );
        } catch (InvalidCapitalWithdrawalException|FinanceConfigurationException|InvalidJournalDraftException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('finance.capital-withdrawals.show', $withdrawal)
            ->with(
                'success',
                'Capital withdrawal posted. A finance journal Draft is ready for accountant approval.',
            );
    }

    public function void(int $withdrawal, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->withdrawals->voidDraft(
                $this->record($withdrawal),
                $data['void_reason'],
                (int) $request->user()->id,
            );
        } catch (InvalidCapitalWithdrawalException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Draft capital withdrawal voided.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where('is_active', true),
            ],
            'withdrawal_type' => [
                'required',
                Rule::in(['OWNER_WITHDRAWAL', 'PARTNER_WITHDRAWAL']),
            ],
            'cash_bank_account_id' => [
                'required',
                'integer',
                'different:drawings_account_id',
                Rule::exists('accounts', 'id'),
            ],
            'drawings_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id'),
            ],
            'withdrawal_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function record(int $id): CapitalWithdrawal
    {
        $withdrawal = CapitalWithdrawal::query()->findOrFail($id);
        $this->scope((int) $withdrawal->branch_id);

        return $withdrawal;
    }

    private function scope(int $id): void
    {
        if (current_branch_id() && current_branch_id() !== $id) {
            abort(403, 'You cannot access another branch’s capital withdrawal.');
        }
    }

    private function cashAccounts(int $branchId)
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

    private function drawingsAccounts(int $branchId)
    {
        return DB::table('branch_accounts as assignment')
            ->join('accounts as account', 'account.id', '=', 'assignment.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->where('assignment.branch_id', $branchId)
            ->where('assignment.is_active', true)
            ->where('account.is_active', true)
            ->where('type.code', 'EQUITY')
            ->orderBy('account.code')
            ->get(['account.id', 'account.code', 'account.name']);
    }

    /** @return array<int, int|string> */
    private function formatTableRow(object $row): array
    {
        $status = CapitalWithdrawalStatus::from($row->status);
        $withdrawalType = $row->withdrawal_type === 'PARTNER_WITHDRAWAL'
            ? 'Partner Withdrawal'
            : 'Owner Withdrawal';
        $showUrl = route('finance.capital-withdrawals.show', $row->id);

        return [
            $row->id,
            '<a href="'.e($showUrl).'" class="fw-semibold text-primary-600">'
                .e($row->withdrawal_no)
                .'</a>',
            '<span class="badge bg-light text-dark">'.e($withdrawalType).'</span>',
            e(date('d M Y', strtotime($row->withdrawal_date))),
            '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
            '<strong>'.e($row->cash_name).'</strong><br><code>'.e($row->cash_code).'</code>',
            '<strong>'.e($row->drawings_name).'</strong><br><code>'.e($row->drawings_code).'</code>',
            '<div class="text-end fw-semibold">'.number_format((float) $row->amount, 2).'</div>',
            '<span class="badge '.$status->badgeClass().' text-white px-12 py-7 radius-4">'
                .e($status->label())
                .'</span>',
            '<div class="d-inline-flex justify-content-end w-100">'
                .'<a href="'.e($showUrl).'" '
                .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                .'justify-content-center bg-info-focus text-info-main" title="View">'
                .'<iconify-icon icon="mdi:eye-outline"></iconify-icon>'
                .'</a></div>',
        ];
    }
}
