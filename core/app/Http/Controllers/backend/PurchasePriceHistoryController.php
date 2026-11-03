<?php

namespace App\Http\Controllers\backend;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PurchasePriceHistoryController extends Controller
{
    public function index(): View
    {
        return view('backend.modules.purchase_price_history.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $raw = DB::table('purchase_receipt_item as item')->join('purchase_receipt as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')->join('raw_materials as i', 'i.id', '=', 'item.raw_material_id')->join('suppliers as s', 's.id', '=', 'receipt.supplier_id')->where('receipt.status', 'POSTED')->whereNotNull('item.raw_material_id')->selectRaw("'RAW_MATERIAL' domain,item.id as row_id,receipt.receipt_date,receipt.receipt_no,s.name supplier_name,i.material_code item_code,i.name item_name,item.quantity,item.unit_rate,item.line_total");
        $part = DB::table('purchase_receipt_item as item')->join('purchase_receipt as receipt', 'receipt.id', '=', 'item.purchase_receipt_id')->join('parts as i', 'i.id', '=', 'item.part_id')->join('suppliers as s', 's.id', '=', 'receipt.supplier_id')->where('receipt.status', 'POSTED')->whereNotNull('item.part_id')->selectRaw("'PART' domain,item.id as row_id,receipt.receipt_date,receipt.receipt_no,s.name supplier_name,i.part_code item_code,i.name item_name,item.quantity,item.unit_rate,item.line_total");
        $query = DB::query()->fromSub($raw->unionAll($part), 'history')->when(current_branch_id(), fn (Builder $q, int $id) => $q->whereExists(fn ($x) => $x->selectRaw(1)->from('purchase_receipt as r')->whereColumn('r.receipt_no', 'history.receipt_no')->where('r.branch_id', $id)));
        if (in_array($request->input('domain'), ['RAW_MATERIAL', 'PART'], true)) {
            $query->where('domain', $request->input('domain'));
        }
        $rows = $query->orderByDesc('receipt_date')->orderByDesc('row_id')->get()->map(fn (object $r) => [$r->row_id, e(date('d M Y', strtotime($r->receipt_date))), '<span class="badge bg-light text-dark">'.e($r->domain === 'PART' ? 'Part' : 'Raw Material').'</span>', '<strong>'.e($r->item_name).'</strong><br><code>'.e($r->item_code).'</code>', e($r->supplier_name), e($r->receipt_no), number_format((float) $r->quantity, 4), '<span class="fw-semibold">'.number_format((float) $r->unit_rate, 2).'</span>', number_format((float) $r->line_total, 2)]);

        return response()->json(['draw' => (int) $request->input('draw'), 'iTotalRecords' => $rows->count(), 'iTotalDisplayRecords' => $rows->count(), 'aaData' => $rows->all()]);
    }
}
