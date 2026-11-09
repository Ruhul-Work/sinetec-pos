<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AccountLedgerController extends Controller
{
    public function index(): View
    {
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return view('backend.modules.account_ledger.index', compact('branches'));
    }

    public function accountSelect2(Request $request): JsonResponse
    {
        $requestedBranchId = (int) $request->input('branch_id');
        $scopedBranchId = current_branch_id();
        if ($scopedBranchId && $requestedBranchId && $requestedBranchId !== $scopedBranchId) {
            return response()->json(['results' => []]);
        }

        $branchId = $requestedBranchId ?: $scopedBranchId;
        $search = trim((string) $request->input('q', ''));
        $query = DB::table('accounts as a')
            ->join('account_types as type', 'type.id', '=', 'a.account_type_id')
            ->where('a.is_active', true)
            ->select(['a.id', 'a.code', 'a.name', 'type.normal_balance']);

        if ($branchId) {
            $query->join('branch_accounts as ba', function ($join) use ($branchId): void {
                $join->on('ba.account_id', '=', 'a.id')
                    ->where('ba.branch_id', '=', $branchId)
                    ->where('ba.is_active', true);
            });
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('a.code', 'like', "%{$search}%")
                    ->orWhere('a.name', 'like', "%{$search}%");
            });
        }

        $results = $query->orderBy('a.code')->limit(30)->get()
            ->map(fn (object $account): array => [
                'id' => $account->id,
                'text' => $account->code.' — '.$account->name.' ('.$account->normal_balance.')',
            ]);

        return response()->json(['results' => $results]);
    }

    public function listAjax(Request $request): JsonResponse
    {
        $draw = (int) $request->input('draw');
        $accountId = (int) $request->input('account_id');
        if ($accountId < 1) {
            return $this->emptyResponse($draw);
        }

        $columns = ['entry_date', 'entry_date', 'account_code', 'branch_name', 'voucher_no', 'memo', 'debit', 'credit', 'running_balance', 'row_id'];
        $start = max((int) $request->input('start', 0), 0);
        $length = (int) $request->input('length', 10);
        $length = $length === -1 ? 1000 : min(max($length, 1), 1000);
        $orderIndex = (int) $request->input('order.0.column', 1);
        $direction = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $search = trim((string) $request->input('search.value', ''));

        $query = $this->ledgerQuery()
            ->where('account_id', $accountId)
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('branch_id', $branchId));
        $total = (clone $query)->count();
        $this->applyFilters($query, $request);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('account_code', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%")
                    ->orWhere('branch_name', 'like', "%{$search}%")
                    ->orWhere('voucher_no', 'like', "%{$search}%")
                    ->orWhere('memo', 'like', "%{$search}%")
                    ->orWhere('source_type', 'like', "%{$search}%");
            });
        }

        $filtered = (clone $query)->count();
        $rows = $query->orderBy($columns[$orderIndex] ?? 'entry_date', $direction)
            ->orderBy('sort_order', $direction)
            ->orderBy('row_id', $direction)
            ->skip($start)
            ->take($length)
            ->get();
        $user = $request->user();

        $data = $rows->map(function (object $row) use ($user): array {
            $source = $row->row_type === 'OPENING'
                ? '<span class="badge bg-light text-dark">Opening Balance</span><br><small class="text-muted">'.e($row->fiscal_year_name).'</small>'
                : '<strong>'.e($row->voucher_no).'</strong><br><small class="text-muted">'.e($this->sourceLabel($row->source_type)).'</small>';
            $action = $row->journal_entry_id && $user?->canDo('finance.journals.show')
                ? '<a href="'.e(route('finance.journals.show', $row->journal_entry_id)).'" '
                    .'class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center '
                    .'justify-content-center bg-info-focus text-info-main" title="View journal">'
                    .'<iconify-icon icon="mdi:file-eye-outline"></iconify-icon></a>'
                : '<span class="text-muted">—</span>';

            return [
                e($row->row_type === 'OPENING' ? 'O-'.$row->row_id : 'J-'.$row->row_id),
                e(date('d M Y', strtotime($row->entry_date))),
                '<strong>'.e($row->account_name).'</strong><br><code>'.e($row->account_code).'</code>',
                '<strong>'.e($row->branch_name).'</strong><br><code>'.e($row->branch_code).'</code>',
                $source,
                $row->memo ? e($row->memo) : '<span class="text-muted">—</span>',
                $this->movementHtml((float) $row->debit, 'text-success-main'),
                $this->movementHtml((float) $row->credit, 'text-danger-main'),
                '<div class="text-end">'.$this->balanceHtml((float) $row->running_balance).'</div>',
                '<div class="d-inline-flex justify-content-end w-100">'.$action.'</div>',
            ];
        })->all();

        return response()->json([
            'draw' => $draw,
            'iTotalRecords' => $total,
            'iTotalDisplayRecords' => $filtered,
            'aaData' => $data,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $accountId = (int) $request->input('account_id');
        if ($accountId < 1) {
            return response()->json(['selected' => false]);
        }

        $query = $this->baseQuery($accountId, $request);
        $from = (string) $request->input('date_from');
        $to = (string) $request->input('date_to');
        $opening = $this->validDate($from)
            ? $this->totals((clone $query)->whereRaw('DATE(entry_date) < ?', [$from]))
            : ['debit' => 0, 'credit' => 0];
        if ($this->validDate($from)) {
            $query->whereRaw('DATE(entry_date) >= ?', [$from]);
        }
        if ($this->validDate($to)) {
            $query->whereRaw('DATE(entry_date) <= ?', [$to]);
        }
        $period = $this->totals($query);

        return response()->json([
            'selected' => true,
            'opening' => $this->summaryBalance($opening['debit'] - $opening['credit']),
            'debit' => number_format($period['debit'], 2),
            'credit' => number_format($period['credit'], 2),
            'closing' => $this->summaryBalance($opening['debit'] - $opening['credit'] + $period['debit'] - $period['credit']),
        ]);
    }

    private function ledgerQuery(): Builder
    {
        $openingRows = DB::table('account_opening_balances as opening')
            ->join('accounts as account', 'account.id', '=', 'opening.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('branches as branch', 'branch.id', '=', 'opening.branch_id')
            ->join('fiscal_years as year', 'year.id', '=', 'opening.fiscal_year_id')
            ->where('opening.status', 'POSTED')
            ->selectRaw("'OPENING' as row_type")
            ->addSelect([
                'opening.id as row_id', DB::raw('NULL as journal_entry_id'), 'opening.account_id',
                'account.code as account_code', 'account.name as account_name', 'type.normal_balance',
                'opening.branch_id', 'branch.name as branch_name', 'branch.code as branch_code',
                'year.start_date as entry_date', DB::raw('0 as sort_order'), DB::raw('NULL as voucher_no'),
                'year.name as fiscal_year_name', DB::raw("'account_opening' as source_type"), DB::raw('NULL as memo'),
                'opening.debit', 'opening.credit',
            ]);

        $journalRows = DB::table('journal_entry_lines as line')
            ->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->join('account_types as type', 'type.id', '=', 'account.account_type_id')
            ->join('branches as branch', 'branch.id', '=', 'line.branch_id')
            ->join('fiscal_years as year', 'year.id', '=', 'journal.fiscal_year_id')
            ->where('journal.status', 'POSTED')
            ->selectRaw("'JOURNAL' as row_type")
            ->addSelect([
                'line.id as row_id', 'journal.id as journal_entry_id', 'line.account_id',
                'account.code as account_code', 'account.name as account_name', 'type.normal_balance',
                'line.branch_id', 'branch.name as branch_name', 'branch.code as branch_code',
                'journal.entry_date', DB::raw('1 as sort_order'), 'journal.voucher_no',
                'year.name as fiscal_year_name', 'journal.source_type', 'line.memo', 'line.debit', 'line.credit',
            ]);

        $union = $openingRows->unionAll($journalRows);
        $withBalance = DB::query()->fromSub($union, 'account_ledger_union')
            ->select('account_ledger_union.*')
            ->selectRaw(
                'SUM(debit - credit) OVER ('
                .'PARTITION BY account_id, branch_id '
                .'ORDER BY entry_date ASC, sort_order ASC, row_id ASC '
                .'ROWS UNBOUNDED PRECEDING) as running_balance',
            );

        return DB::query()->fromSub($withBalance, 'account_ledger_rows');
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if (! current_branch_id() && (int) $request->input('branch_id') > 0) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        $dateFrom = (string) $request->input('date_from');
        if ($this->validDate($dateFrom)) {
            $query->whereRaw('DATE(entry_date) >= ?', [$dateFrom]);
        }
        $dateTo = (string) $request->input('date_to');
        if ($this->validDate($dateTo)) {
            $query->whereRaw('DATE(entry_date) <= ?', [$dateTo]);
        }
    }

    private function baseQuery(int $accountId, Request $request): Builder
    {
        $query = $this->ledgerQuery()->where('account_id', $accountId)
            ->when(current_branch_id(), fn (Builder $builder, int $branchId) => $builder->where('branch_id', $branchId));
        if (! current_branch_id() && (int) $request->input('branch_id') > 0) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        return $query;
    }

    /** @return array{debit: float, credit: float} */
    private function totals(Builder $query): array
    {
        $row = $query->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')->first();

        return ['debit' => (float) $row->debit, 'credit' => (float) $row->credit];
    }

    /** @return array{amount: string, side: string, class: string} */
    private function summaryBalance(float $balance): array
    {
        if (abs($balance) < 0.00005) {
            return ['amount' => '0.00', 'side' => '', 'class' => 'text-muted'];
        }

        return [
            'amount' => number_format(abs($balance), 2),
            'side' => $balance > 0 ? 'Dr' : 'Cr',
            'class' => $balance > 0 ? 'text-success-main' : 'text-danger-main',
        ];
    }

    private function balanceHtml(float $balance): string
    {
        if (abs($balance) < 0.00005) {
            return '<span class="text-muted">0.00</span>';
        }

        $isDebit = $balance > 0;
        $class = $isDebit ? 'text-success-main' : 'text-danger-main';
        $side = $isDebit ? 'Dr' : 'Cr';

        return '<span class="'.$class.' fw-semibold">'.$side.' '.number_format(abs($balance), 2).'</span>';
    }

    private function movementHtml(float $amount, string $textClass): string
    {
        $content = $amount > 0
            ? '<span class="'.$textClass.' fw-semibold">'.number_format($amount, 2).'</span>'
            : '—';

        return '<div class="text-end">'.$content.'</div>';
    }

    private function sourceLabel(?string $sourceType): string
    {
        return str($sourceType ?: 'journal')->replace('_', ' ')->title()->toString();
    }

    private function validDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && date('Y-m-d', strtotime($date)) === $date;
    }

    private function emptyResponse(int $draw): JsonResponse
    {
        return response()->json(['draw' => $draw, 'iTotalRecords' => 0, 'iTotalDisplayRecords' => 0, 'aaData' => []]);
    }
}
