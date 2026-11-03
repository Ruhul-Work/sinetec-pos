<?php

namespace App\Http\Controllers\backend;

use App\Enums\Purchase\PurchasePaymentStatus;
use App\Exceptions\Finance\FinanceConfigurationException;
use App\Exceptions\Finance\InvalidJournalDraftException;
use App\Exceptions\Purchase\InvalidPurchasePaymentException;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchasePayment;
use App\Models\Purchase\PurchaseReceipt;
use App\Services\Purchase\PurchasePaymentService;
use App\Services\Purchase\SupplierDueService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchasePaymentController extends Controller
{
    public function __construct(private readonly PurchasePaymentService $payments, private readonly SupplierDueService $due) {}

    public function index(): View
    {
        return view('backend.modules.purchase_payment.index');
    }

    public function listAjax(Request $request): JsonResponse
    {
        $query = DB::table('purchase_payment as pp')->join('suppliers as s', 's.id', '=', 'pp.supplier_id')->join('purchase_receipt as pr', 'pr.id', '=', 'pp.purchase_receipt_id')
            ->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('pp.branch_id', $id))
            ->select(['pp.id', 'pp.payment_no', 'pp.payment_date', 'pp.amount', 'pp.status', 's.name as supplier_name', 'pr.receipt_no']);
        $rows = $query->orderByDesc('pp.id')->get()->map(function (object $row): array {
            $status = PurchasePaymentStatus::from($row->status);

            return [$row->id, '<a class="fw-semibold text-primary-600" href="'.e(route('purchase.payments.show', $row->id)).'">'.e($row->payment_no).'</a>', e(date('d M Y', strtotime($row->payment_date))), e($row->supplier_name), e($row->receipt_no), '<span class="fw-semibold">'.number_format((float) $row->amount, 2).'</span>', '<span class="badge bg-'.($status === PurchasePaymentStatus::Posted ? 'dark-success-gradient' : 'dark-warning-gradient').' text-white">'.e($status->label()).'</span>'];
        });

        return response()->json(['draw' => (int) $request->input('draw'), 'iTotalRecords' => $rows->count(), 'iTotalDisplayRecords' => $rows->count(), 'aaData' => $rows->all()]);
    }

    public function create(): View
    {
        $receipts = PurchaseReceipt::query()->with('supplier:id,name')->where('status', 'POSTED')->when(current_branch_id(), fn ($q, int $id) => $q->where('branch_id', $id))->orderByDesc('receipt_date')->get();
        $accounts = DB::table('accounts as a')->join('account_types as t', 't.id', '=', 'a.account_type_id')->join('branch_accounts as ba', 'ba.account_id', '=', 'a.id')->where('a.is_active', true)->where('ba.is_active', true)->where('t.code', 'ASSET')->when(current_branch_id(), fn (Builder $q, int $id) => $q->where('ba.branch_id', $id))->distinct()->orderBy('a.code')->get(['a.id', 'a.code', 'a.name']);
        $types = DB::table('payment_types')->where('is_active', true)->orderBy('name')->get();

        return view('backend.modules.purchase_payment.modal', compact('receipts', 'accounts', 'types'));
    }

    public function due(int $receipt): JsonResponse
    {
        $receipt = PurchaseReceipt::query()->whereKey($receipt)->when(current_branch_id(), fn ($q, int $id) => $q->where('branch_id', $id))->firstOrFail();

        return response()->json($this->due->receiptDue($receipt));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['purchase_receipt_id' => ['required', 'integer', Rule::exists('purchase_receipt', 'id')], 'payment_type_id' => ['required', 'integer', Rule::exists('payment_types', 'id')], 'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'reference_no' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:5000']]);
        try {
            $payment = $this->payments->create($data, (int) $request->user()->id);
        } catch (InvalidPurchasePaymentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return response()->json(['success' => true, 'msg' => 'Supplier payment saved as Draft.', 'id' => $payment->id]);
    }

    public function show(int $payment): View
    {
        $payment = PurchasePayment::query()->with(['receipt', 'supplier', 'account', 'paymentType', 'createdBy', 'postedBy'])->when(current_branch_id(), fn ($q, int $id) => $q->where('branch_id', $id))->findOrFail($payment);

        return view('backend.modules.purchase_payment.show', ['payment' => $payment, 'due' => $this->due->receiptDue($payment->receipt, $payment->id)]);
    }

    public function post(int $payment, Request $request): RedirectResponse
    {
        try {
            $this->payments->post($this->record($payment), (int) $request->user()->id);
        } catch (InvalidPurchasePaymentException|InvalidJournalDraftException|FinanceConfigurationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment posted as Paid. A finance journal Draft was created for accountant approval.');
    }

    private function record(int $id): PurchasePayment
    {
        return PurchasePayment::query()->when(current_branch_id(), fn ($q, int $branch) => $q->where('branch_id', $branch))->findOrFail($id);
    }
}
