<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupplierDueSummaryController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.supplier_due_summary.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $branchId = current_branch_id();
        $search = trim((string) $request->input('search.value', ''));

        $receipts = DB::table('purchase_receipt')
            ->where('status', 'POSTED')
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->selectRaw('supplier_id, COUNT(*) as receipt_count, COALESCE(SUM(total), 0) as receipt_total')
            ->groupBy('supplier_id');
        $returns = DB::table('purchase_return')
            ->where('status', 'POSTED')
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->selectRaw('supplier_id, COALESCE(SUM(total), 0) as return_total')
            ->groupBy('supplier_id');
        $payments = DB::table('purchase_payment')
            ->where('status', 'POSTED')
            ->when($branchId, fn (Builder $query, int $id) => $query->where('branch_id', $id))
            ->selectRaw('supplier_id, COALESCE(SUM(amount), 0) as payment_total')
            ->groupBy('supplier_id');

        $query = DB::query()
            ->fromSub($receipts, 'receipt_totals')
            ->join('suppliers as supplier', 'supplier.id', '=', 'receipt_totals.supplier_id')
            ->leftJoinSub($returns, 'return_totals', 'return_totals.supplier_id', '=', 'supplier.id')
            ->leftJoinSub($payments, 'payment_totals', 'payment_totals.supplier_id', '=', 'supplier.id')
            ->select([
                'supplier.id', 'supplier.name', 'supplier.code', 'receipt_totals.receipt_count', 'receipt_totals.receipt_total',
                DB::raw('COALESCE(return_totals.return_total, 0) as return_total'),
                DB::raw('COALESCE(payment_totals.payment_total, 0) as payment_total'),
            ]);
        if ($search !== '') {
            $query->where(fn (Builder $inner) => $inner
                ->where('supplier.name', 'like', "%{$search}%")
                ->orWhere('supplier.code', 'like', "%{$search}%"));
        }

        $rows = $query->orderBy('supplier.name')->get()->map(function (object $row): array {
            $net = round((float) $row->receipt_total - (float) $row->return_total - (float) $row->payment_total, 4);
            $due = max(0, $net);
            $credit = max(0, -$net);

            return [
                $row->id,
                '<strong>'.e($row->name).'</strong>'.($row->code ? '<br><code>'.e($row->code).'</code>' : ''),
                (int) $row->receipt_count,
                '<span class="fw-semibold">'.number_format((float) $row->receipt_total, 2).'</span>',
                number_format((float) $row->return_total, 2),
                number_format((float) $row->payment_total, 2),
                '<span class="fw-bold text-'.($due > 0 ? 'danger' : 'success').'">'.number_format($due, 2).'</span>',
                $credit > 0 ? '<span class="fw-semibold text-info-main">'.number_format($credit, 2).'</span>' : '<span class="text-muted">—</span>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'iTotalRecords' => $rows->count(),
            'iTotalDisplayRecords' => $rows->count(),
            'aaData' => $rows->all(),
        ]);
    }
}
