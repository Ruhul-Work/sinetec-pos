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
    public function __construct(private readonly CapitalWithdrawalService $withdrawals) {}

    public function index(): View
    {
        return view('backend.modules.capital_withdrawal.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $q = DB::table('finance_capital_withdrawals as w')->join('branches as b', 'b.id', '=', 'w.branch_id')->join('accounts as c', 'c.id', '=', 'w.cash_bank_account_id')->join('accounts as d', 'd.id', '=', 'w.drawings_account_id')->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('w.branch_id', $id))->select(['w.id', 'w.withdrawal_no', 'w.withdrawal_type', 'w.withdrawal_date', 'w.amount', 'w.status', 'b.name as branch_name', 'b.code as branch_code', 'c.name as cash_name', 'c.code as cash_code', 'd.name as drawings_name', 'd.code as drawings_code']);
        $total = (clone $q)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $q->where(fn (Builder $q) => $q->where('w.withdrawal_no', 'like', "%{$search}%")->orWhere('b.name', 'like', "%{$search}%")->orWhere('c.name', 'like', "%{$search}%")->orWhere('d.name', 'like', "%{$search}%"));
        }
        $rows = $q->orderByDesc('w.id')->get();
        $data = $rows->map(function (object $r): array {
            $status = CapitalWithdrawalStatus::from($r->status);
            $type = $r->withdrawal_type === 'PARTNER_WITHDRAWAL' ? 'Partner Withdrawal' : 'Owner Withdrawal';
            $url = route('finance.capital-withdrawals.show', $r->id);

            return [$r->id, '<a href="'.e($url).'" class="fw-semibold text-primary-600">'.e($r->withdrawal_no).'</a>', '<span class="badge bg-light text-dark">'.e($type).'</span>', e(date('d M Y', strtotime($r->withdrawal_date))), '<strong>'.e($r->branch_name).'</strong><br><code>'.e($r->branch_code).'</code>', '<strong>'.e($r->cash_name).'</strong><br><code>'.e($r->cash_code).'</code>', '<strong>'.e($r->drawings_name).'</strong><br><code>'.e($r->drawings_code).'</code>', '<div class="text-end fw-semibold">'.number_format((float) $r->amount, 2).'</div>', '<span class="badge '.$status->badgeClass().' text-white px-12 py-7 radius-4">'.e($status->label()).'</span>', '<div class="d-inline-flex justify-content-end w-100"><a href="'.e($url).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a></div>'];
        });

        return response()->json(['draw' => (int) $request->input('draw'), 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $data->count(), 'aaData' => $data->all()]);
    }

    public function create(): View
    {
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name', 'code']);
        $branchId = (int) (current_branch_id() ?: $branches->first()?->id);
        abort_if($branchId < 1, 422, 'An active branch is required before recording a withdrawal.');

        return view('backend.modules.capital_withdrawal.form', ['branches' => $branches, 'branchId' => $branchId, 'cashAccounts' => $this->cashAccounts($branchId), 'drawingsAccounts' => $this->drawingsAccounts($branchId)]);
    }

    public function branchAccounts(int $branch): JsonResponse
    {
        $this->scope($branch);

        return response()->json(['cash_accounts' => $this->cashAccounts($branch), 'drawings_accounts' => $this->drawingsAccounts($branch)]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $this->scope((int) $data['branch_id']);
        try {
            $w = $this->withdrawals->create($data, (int) $request->user()->id);
        } catch (InvalidCapitalWithdrawalException|FinanceConfigurationException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage(), 'msg' => $e->getMessage()], 422) : back()->withInput()->with('error', $e->getMessage());
        }

return $request->expectsJson() ? response()->json(['success' => true, 'msg' => 'Capital withdrawal saved as Draft.', 'redirect_url' => route('finance.capital-withdrawals.show', $w)]) : redirect()->route('finance.capital-withdrawals.show', $w)->with('success', 'Capital withdrawal saved as Draft.');
    }

    public function show(int $withdrawal): View
    {
        $withdrawal = $this->record($withdrawal)->load(['branch', 'cashBankAccount', 'drawingsAccount', 'voidedBy']);
        $journal = JournalEntry::query()->where('source_type', 'capital_withdrawal')->where('source_id', $withdrawal->id)->first();

        return view('backend.modules.capital_withdrawal.show', compact('withdrawal', 'journal'));
    }

    public function post(int $withdrawal, Request $request): RedirectResponse
    {
        try {
            $w = $this->withdrawals->post($this->record($withdrawal), (int) $request->user()->id);
        } catch (InvalidCapitalWithdrawalException|FinanceConfigurationException|InvalidJournalDraftException $e) {
            return back()->with('error', $e->getMessage());
        }

return redirect()->route('finance.capital-withdrawals.show', $w)->with('success', 'Capital withdrawal posted. A finance journal Draft is ready for accountant approval.');
    }

    public function void(int $withdrawal, Request $request): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->withdrawals->voidDraft($this->record($withdrawal), $data['void_reason'], (int) $request->user()->id);
        } catch (InvalidCapitalWithdrawalException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

return back()->with('success', 'Draft capital withdrawal voided.');
    }

    private function validated(Request $r): array
    {
        return $r->validate(['branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)], 'withdrawal_type' => ['required', Rule::in(['OWNER_WITHDRAWAL', 'PARTNER_WITHDRAWAL'])], 'cash_bank_account_id' => ['required', 'integer', 'different:drawings_account_id', Rule::exists('accounts', 'id')], 'drawings_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')], 'withdrawal_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'reference_no' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:5000']]);
    }

    private function record(int $id): CapitalWithdrawal
    {
        $w = CapitalWithdrawal::query()->findOrFail($id);
        $this->scope((int) $w->branch_id);

        return $w;
    }

    private function scope(int $id): void
    {
        if (current_branch_id() && current_branch_id() !== $id) {
            abort(403, 'You cannot access another branch’s capital withdrawal.');
        }
    }

    private function cashAccounts(int $branch)
    {
        return DB::table('branch_accounts as a')->join('accounts as x', 'x.id', '=', 'a.account_id')->where('a.branch_id', $branch)->where('a.is_active', true)->where('x.is_active', true)->where('x.is_cash_bank_account', true)->orderBy('x.code')->get(['x.id', 'x.code', 'x.name']);
    }

    private function drawingsAccounts(int $branch)
    {
        return DB::table('branch_accounts as a')->join('accounts as x', 'x.id', '=', 'a.account_id')->join('account_types as t','t.id','=','x.account_type_id')->where('a.branch_id',$branch)->where('a.is_active',true)->where('x.is_active',true)->where('t.code','EQUITY')->orderBy('x.code')->get(['x.id', 'x.code', 'x.name']);
    }
}
