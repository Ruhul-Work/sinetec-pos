<?php

namespace App\Http\Controllers\backend;

use App\Enums\Finance\FiscalYearClosingStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidFiscalYearClosingException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Http\Controllers\Controller;
use App\Models\backend\JournalEntry;
use App\Models\Finance\FiscalYearClosing;
use App\Services\Finance\FiscalYearClosingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FiscalYearClosingController extends Controller
{
    public function __construct(private readonly FiscalYearClosingService $closings) {}

    public function index(): View
    {
        return view('backend.modules.fiscal_year_closing.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $query = DB::table('finance_fiscal_year_closings as closing')
            ->join('fiscal_years as year', 'year.id', '=', 'closing.fiscal_year_id')
            ->join('branches as branch', 'branch.id', '=', 'closing.branch_id')
            ->join('accounts as drawings', 'drawings.id', '=', 'closing.drawings_account_id')
            ->join('accounts as capital', 'capital.id', '=', 'closing.capital_account_id')
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('closing.branch_id', $branchId))
            ->select([
                'closing.id', 'closing.closing_no', 'closing.closing_date', 'closing.amount', 'closing.status',
                'year.name as fiscal_year_name', 'branch.name as branch_name', 'branch.code as branch_code',
                'drawings.code as drawings_code', 'drawings.name as drawings_name',
                'capital.code as capital_code', 'capital.name as capital_name',
            ]);
        $total = (clone $query)->count();
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('closing.closing_no', 'like', "%{$search}%")
                    ->orWhere('year.name', 'like', "%{$search}%")
                    ->orWhere('branch.name', 'like', "%{$search}%")
                    ->orWhere('drawings.name', 'like', "%{$search}%")
                    ->orWhere('capital.name', 'like', "%{$search}%");
            });
        }

        $rows = $query->orderByDesc('closing.id')->get();
        $data = $rows->map(function (object $row): array {
            $status = FiscalYearClosingStatus::from($row->status);
            $url = route('finance.fiscal-year-closings.show', $row->id);

            return [
                $row->id,
                '<a href="'.e($url).'" class="fw-semibold text-primary-600">'.e($row->closing_no).'</a>',
                e($row->fiscal_year_name),
                e(date('d M Y', strtotime($row->closing_date))),
                '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
                '<strong>'.e($row->drawings_name).'</strong><br><code>'.e($row->drawings_code).'</code>',
                '<strong>'.e($row->capital_name).'</strong><br><code>'.e($row->capital_code).'</code>',
                '<div class="text-end fw-semibold">'.number_format((float) $row->amount, 2).'</div>',
                '<span class="badge '.$status->badgeClass().' text-white px-12 py-7 radius-4">'.e($status->label()).'</span>',
                '<div class="d-inline-flex justify-content-end w-100"><a href="'.e($url).'" class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main" title="View closing"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a></div>',
            ];
        });

        return response()->json(['draw' => (int) $request->input('draw'), 'iTotalRecords' => $total, 'iTotalDisplayRecords' => $data->count(), 'aaData' => $data->all()]);
    }

    public function create(): View
    {
        $years = DB::table('fiscal_years')->where('is_active', true)->orderByDesc('end_date')->get(['id', 'name', 'start_date', 'end_date']);
        $branches = DB::table('branches')->whereNull('deleted_at')->where('is_active', true)->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('id', $branchId))->orderBy('name')->get(['id', 'name', 'code']);
        $branchId = (int) (current_branch_id() ?: $branches->first()?->id);
        abort_if($years->isEmpty(), 422, 'An active fiscal year is required before preparing year closing.');
        abort_if($branchId < 1, 422, 'An active branch is required before preparing year closing.');

        $activeYear = $years->first();
        $closingEndDate = CarbonImmutable::parse($activeYear->end_date)->startOfDay();
        $canPrepareClosing = now()->startOfDay()->greaterThanOrEqualTo($closingEndDate);

        return view('backend.modules.fiscal_year_closing.form', [
            'years' => $years,
            'branches' => $branches,
            'branchId' => $branchId,
            'equityAccounts' => $this->equityAccounts($branchId),
            'canPrepareClosing' => $canPrepareClosing,
            'closingAvailableOn' => $closingEndDate->format('d M Y'),
        ]);
    }

    public function branchAccounts(int $branch): JsonResponse
    {
        $this->scope($branch);

        return response()->json(['equity_accounts' => $this->equityAccounts($branch)]);
    }

    public function balance(int $fiscalYear, int $branch, int $drawingsAccount): JsonResponse
    {
        $this->scope($branch);
        $amount = $this->closings->drawingsBalance($fiscalYear, $branch, $drawingsAccount);

        return response()->json(['amount' => max(0, $amount), 'formatted' => number_format(max(0, $amount), 2)]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request);
        $this->scope((int) $data['branch_id']);
        try {
            $closing = $this->closings->create($data, (int) $request->user()->id);
        } catch (InvalidFiscalYearClosingException|FinanceConfigurationException $exception) {
            return $this->error($request, $exception->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['success' => true, 'msg' => 'Fiscal year closing saved as Draft.', 'redirect_url' => route('finance.fiscal-year-closings.show', $closing)])
            : redirect()->route('finance.fiscal-year-closings.show', $closing)->with('success', 'Fiscal year closing saved as Draft.');
    }

    public function show(int $closing): View
    {
        $closing = $this->record($closing)->load(['fiscalYear', 'branch', 'drawingsAccount', 'capitalAccount', 'voidedBy']);
        $journal = JournalEntry::query()->where('source_type', 'fiscal_year_closing')->where('source_id', $closing->id)->first();

        return view('backend.modules.fiscal_year_closing.show', compact('closing', 'journal'));
    }

    public function post(int $closing, Request $request): RedirectResponse
    {
        try {
            $closing = $this->closings->post($this->record($closing), (int) $request->user()->id);
        } catch (InvalidFiscalYearClosingException|FinanceConfigurationException|InvalidJournalDraftException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('finance.fiscal-year-closings.show', $closing)->with('success', 'Fiscal year closing prepared. Approve and post its Journal Draft before activating the next fiscal year.');
    }

    public function void(int $closing, Request $request): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->closings->voidDraft($this->record($closing), $data['void_reason'], (int) $request->user()->id);
        } catch (InvalidFiscalYearClosingException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Draft fiscal year closing voided.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')->where('is_active', true)],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'drawings_account_id' => ['required', 'integer', 'different:capital_account_id', Rule::exists('accounts', 'id')],
            'capital_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'closing_date' => ['required', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function record(int $id): FiscalYearClosing
    {
        $closing = FiscalYearClosing::query()->findOrFail($id);
        $this->scope((int) $closing->branch_id);

        return $closing;
    }

    private function scope(int $branchId): void
    {
        if (current_branch_id() && current_branch_id() !== $branchId) {
            abort(403, 'You cannot access another branch’s fiscal year closing.');
        }
    }

    private function equityAccounts(int $branchId)
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

    private function error(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message, 'msg' => $message], 422)
            : back()->withInput()->with('error', $message);
    }
}
