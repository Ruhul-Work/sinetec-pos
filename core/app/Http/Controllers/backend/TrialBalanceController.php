<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TrialBalanceController extends Controller
{
    public function index(): View
    {
        $branches = DB::table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when(current_branch_id(), fn (Builder $query, int $branchId) => $query->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return view('backend.modules.trial_balance.index', compact('branches'));
    }

    public function listAjax(Request $request): JsonResponse
    {
        $rows = $this->query($request)->orderBy('account_code')->get();
        $data = $rows->map(function (object $row): array {
            $netBalance = (float) $row->debit - (float) $row->credit;

            return [
                $row->account_id,
                '<strong>'.e($row->account_name).'</strong><br><code>'.e($row->account_code).'</code>',
                '<span class="badge bg-light text-secondary">'.e($row->account_type).'</span>',
                '<div class="text-end">'.((float) $row->debit > 0 ? '<span class="text-success-main fw-semibold">'.number_format($row->debit, 2).'</span>' : '&mdash;').'</div>',
                '<div class="text-end">'.((float) $row->credit > 0 ? '<span class="text-danger-main fw-semibold">'.number_format($row->credit, 2).'</span>' : '&mdash;').'</div>',
                '<div class="text-end fw-semibold">'.($netBalance > 0 ? '<span class="text-success-main">Dr '.number_format($netBalance, 2).'</span>' : '&mdash;').'</div>',
                '<div class="text-end fw-semibold">'.($netBalance < 0 ? '<span class="text-danger-main">Cr '.number_format(abs($netBalance), 2).'</span>' : '&mdash;').'</div>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $data->count(),
            'iTotalDisplayRecords' => $data->count(),
            'aaData' => $data->all(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $totals = DB::query()
            ->fromSub($this->query($request), 'trial_balance')
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $isBalanced = abs((float) $totals->debit - (float) $totals->credit) < .0001;

        return response()->json([
            'debit' => number_format($totals->debit, 2),
            'credit' => number_format($totals->credit, 2),
            'balanced' => $isBalanced,
            'difference' => number_format(abs((float) $totals->debit - (float) $totals->credit), 2),
        ]);
    }

    private function query(Request $request): Builder
    {
        // A fixed branch scope (regular branch user/topbar selection) always wins.
        // When the topbar scope is "All", Super Admin may narrow this report by
        // using its local branch filter.
        $branchId = current_branch_id() ?: (int) $request->input('branch_id', 0);

        $openingBalances = DB::table('account_opening_balances as o')
            ->join('accounts as a', 'a.id', '=', 'o.account_id')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->join('fiscal_years as fy', 'fy.id', '=', 'o.fiscal_year_id')
            ->where('o.status', 'POSTED')
            ->select([
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                't.name as account_type',
                'o.branch_id',
                'fy.start_date as entry_date',
                'o.debit',
                'o.credit',
            ]);

        $journalLines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('j.status', 'POSTED')
            ->select([
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                't.name as account_type',
                'l.branch_id',
                'j.entry_date',
                'l.debit',
                'l.credit',
            ]);

        return DB::query()
            ->fromSub($openingBalances->unionAll($journalLines), 'tb')
            ->when($branchId > 0, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($request->date_to, fn (Builder $query, string $date) => $query->whereDate('entry_date', '<=', $date))
            ->groupBy('account_id', 'account_code', 'account_name', 'account_type')
            ->selectRaw('account_id, account_code, account_name, account_type, SUM(debit) as debit, SUM(credit) as credit');
    }
}
