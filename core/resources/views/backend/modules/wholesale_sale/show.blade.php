@extends('backend.layouts.master')

@php
  $statusClass = match ($sale->status) {
    \App\Enums\Sales\SaleStatus::Draft => 'bg-dark-warning-gradient',
    \App\Enums\Sales\SaleStatus::ReadyToPost => 'bg-dark-info-gradient',
    \App\Enums\Sales\SaleStatus::Posted => 'bg-dark-success-gradient',
    \App\Enums\Sales\SaleStatus::Void => 'bg-dark-danger-gradient',
  };
  [$paymentLabel, $paymentClass] = match (true) {
    $sale->status !== \App\Enums\Sales\SaleStatus::Posted => ['Pending', 'bg-secondary-focus text-secondary-600'],
    (float) $sale->due_amount <= 0 => ['Paid', 'bg-success-focus text-success-600'],
    (float) $sale->paid_amount > 0 => ['Partially Paid', 'bg-warning-focus text-warning-600'],
    default => ['Unpaid', 'bg-danger-focus text-danger-600'],
  };
@endphp

@section('meta')
  <title>Wholesale Invoice {{ $sale->sale_no }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">Wholesale Invoice {{ $sale->sale_no }}</h6>
        <span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">
          {{ $sale->status->label() }}
        </span>
        <span class="badge {{ $paymentClass }} px-10 py-6 radius-4">{{ $paymentLabel }}</span>
        <x-backend.page-note title="Wholesale workflow" label="View posting information" align="start">
          Ready to Post is a review state only. Confirm & Post permanently reduces Finished Product stock,
          posts collected payments and creates the balanced Sales journal.
        </x-backend.page-note>
      </div>
      <p class="text-muted mb-0">Review customer terms, invoice items and payment allocation</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      @if ($sale->status === \App\Enums\Sales\SaleStatus::Draft)
        @perm('sales.wholesale.edit', 'edit')
          <a href="{{ route('sales.wholesale.edit', $sale) }}"
             class="btn btn-outline-primary px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="lucide:edit"></iconify-icon><span>Edit Draft</span>
          </a>
        @endperm
      @elseif ($sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost)
        @perm('sales.wholesale.reopen', 'edit')
          <form method="POST" action="{{ route('sales.wholesale.reopen', $sale) }}"
                class="d-flex js-swal-confirm"
                data-confirm-title="Return this invoice to Draft?"
                data-confirm-text="Prepared payment rows will be cleared so the invoice can be edited."
                data-confirm-button="Yes, back to edit" data-confirm-color="#64748b">
            @csrf
            @method('PATCH')
            <button class="btn btn-outline-primary px-14 py-8 radius-8 app-action-button">
              <iconify-icon icon="mdi:arrow-u-left-top"></iconify-icon><span>Back to Edit</span>
            </button>
          </form>
        @endperm
        @perm('sales.wholesale.post', 'edit')
          <form method="POST" action="{{ route('sales.wholesale.post', $sale) }}"
                class="d-flex js-swal-confirm"
                data-confirm-title="Confirm and post this Wholesale Sale?"
                data-confirm-text="Finished Product stock, payments and Finance journal will be posted permanently."
                data-confirm-button="Yes, confirm & post" data-confirm-color="#198754">
            @csrf
            @method('PATCH')
            <button class="btn btn-success px-14 py-8 radius-8 app-action-button">
              <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon><span>Confirm & Post</span>
            </button>
          </form>
        @endperm
      @elseif ($sale->status === \App\Enums\Sales\SaleStatus::Posted)
        @if ((float) $sale->due_amount > 0)
          @perm('sales.customer-receivables.collect-modal', 'add')
            <button type="button" class="btn btn-success px-14 py-8 radius-8 app-action-button AjaxModal"
                    data-ajax-modal="{{ route('sales.customer-receivables.collect-modal', $sale) }}"
                    data-size="lg" data-onsuccess="WholesaleSaleDetails.onDueCollected">
              <iconify-icon icon="mdi:cash-plus"></iconify-icon><span>Collect Due</span>
            </button>
          @endperm
        @endif
        @perm('sales.wholesale.invoice', 'view')
          <a href="{{ route('sales.wholesale.invoice', $sale) }}" target="_blank"
             class="btn btn-outline-success px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:printer-outline"></iconify-icon><span>Print Invoice</span>
          </a>
        @endperm
      @endif
      <a href="{{ route('sales.wholesale.index') }}"
         class="btn btn-outline-secondary px-14 py-8 radius-8 app-action-button">
        <iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to list</span>
      </a>
    </div>
  </div>

  @if ($sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost)
    <div class="alert alert-info d-flex align-items-start gap-2 mb-24">
      <iconify-icon icon="mdi:information-outline" class="text-xl mt-1"></iconify-icon>
      <div>
        <strong>Review before posting</strong>
        <div class="text-sm">Stock, account balances and the Sales journal are still unchanged.</div>
      </div>
    </div>
  @endif

  <div class="card mb-24">
    <div class="card-header"><h5 class="card-title mb-0">Invoice Information</h5></div>
    <div class="card-body">
      <div class="row gy-3">
        <div class="col-md-3"><small class="text-muted d-block">Customer</small><strong>{{ $sale->customer->name }}</strong></div>
        <div class="col-md-3">
          <small class="text-muted d-block">Customer Reference</small>
          <strong>{{ $sale->customer_reference ?: '—' }}</strong>
        </div>
        <div class="col-md-3"><small class="text-muted d-block">Branch</small><strong>{{ $sale->branch->name }}</strong></div>
        <div class="col-md-3">
          <small class="text-muted d-block">Issuing Warehouse</small>
          <strong>{{ $sale->warehouse->name }} ({{ $sale->warehouse->code }})</strong>
        </div>
        <div class="col-md-3">
          <small class="text-muted d-block">Invoice Date</small>
          <strong>{{ $sale->sale_at->format('d M Y, h:i A') }}</strong>
        </div>
        <div class="col-md-3">
          <small class="text-muted d-block">Due Date</small>
          <strong>{{ $sale->due_date->format('d M Y') }}</strong>
        </div>
        @if ($sale->status === \App\Enums\Sales\SaleStatus::Posted)
          <div class="col-12">
            <div class="row gy-3">
              <div class="col-md-3">
                <small class="text-muted d-block">Payment Received</small>
                <strong class="text-success">{{ number_format((float) $paymentReceived, 2) }}</strong>
              </div>
              <div class="col-md-3">
                <small class="text-muted d-block">Refunded</small>
                <strong class="text-danger">{{ number_format((float) $refundedAmount, 2) }}</strong>
              </div>
              <div class="col-md-3">
                <small class="text-muted d-block">Net Paid</small>
                <strong class="text-success">{{ number_format((float) $sale->paid_amount, 2) }}</strong>
              </div>
              <div class="col-md-3">
                <small class="text-muted d-block">Due</small>
                <strong class="text-danger">{{ number_format((float) $sale->due_amount, 2) }}</strong>
              </div>
            </div>
          </div>
        @else
          <div class="col-md-3">
            <small class="text-muted d-block">{{ $sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost ? 'Payment Planned' : 'Paid' }}</small>
            <strong class="text-success">{{ number_format((float) $sale->paid_amount, 2) }}</strong>
          </div>
          <div class="col-md-3">
            <small class="text-muted d-block">{{ $sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost ? 'Projected Due' : 'Due' }}</small>
            <strong class="text-danger">{{ number_format((float) $sale->due_amount, 2) }}</strong>
          </div>
        @endif
        <div class="col-md-6">
          <small class="text-muted d-block">Finance Journal</small>
          <strong>{{ $journal ? $journal->voucher_no.' · '.$journal->status->value : 'Not created before posting' }}</strong>
        </div>
        <div class="col-md-6"><small class="text-muted d-block">Note</small><strong>{{ $sale->note ?: '—' }}</strong></div>
      </div>
    </div>
  </div>

  @if ($sale->status !== \App\Enums\Sales\SaleStatus::Draft)
    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">Payment Breakdown</h5></div>
      <div class="card-body">
        @if ($sale->payments->isEmpty())
          <div class="alert alert-warning mb-0">No payment collected. The full amount is Customer Receivable.</div>
        @else
          <div class="table-responsive">
            <table class="table bordered-table mb-0">
              <thead>
                <tr>
                  <th>Payment No.</th>
                  <th>Method</th>
                  <th>Account</th>
                  <th>Reference</th>
                  <th class="text-end">Amount</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($sale->payments as $payment)
                  <tr>
                    <td>{{ $payment->payment_no }}</td>
                    <td>{{ $payment->paymentType->name }}</td>
                    <td>{{ $payment->account->code }} — {{ $payment->account->name }}</td>
                    <td>{{ $payment->reference_no ?: '—' }}</td>
                    <td class="text-end fw-semibold">{{ number_format((float) $payment->amount, 2) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </div>
  @endif

  <div class="card mb-24">
    <div class="card-header"><h5 class="card-title mb-0">Finished Products</h5></div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Finished Product</th>
              <th>Unit</th>
              <th class="text-end">Quantity</th>
              <th class="text-end">Rate</th>
              <th class="text-end">Total</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($sale->items as $item)
              <tr>
                <td>{{ $loop->iteration }}</td>
                <td><strong>{{ $item->finishedProduct->name }}</strong><br><code>{{ $item->finishedProduct->product_code }}</code></td>
                <td>{{ $item->unit->name }} ({{ $item->unit->code }})</td>
                <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->quantity, 6, '.', ''), '0'), '.') }}</td>
                <td class="text-end">{{ number_format((float) $item->unit_price, 2) }}</td>
                <td class="text-end fw-semibold">{{ number_format((float) $item->line_total, 2) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <th colspan="5" class="text-end">Subtotal</th>
              <th class="text-end">{{ number_format((float) $sale->subtotal, 2) }}</th>
            </tr>
            <tr>
              <th colspan="5" class="text-end">Discount</th>
              <th class="text-end">{{ number_format((float) $sale->discount, 2) }}</th>
            </tr>
            <tr>
              <th colspan="5" class="text-end">Tax</th>
              <th class="text-end">{{ number_format((float) $sale->tax, 2) }}</th>
            </tr>
            <tr class="table-light">
              <th colspan="5" class="text-end fs-6">Invoice Total</th>
              <th class="text-end fs-6">{{ number_format((float) $sale->total, 2) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
@endsection

@section('script')
  <script>
    window.WholesaleSaleDetails = {
      onDueCollected: function() {
        window.location.reload();
      },
    };
  </script>
@endsection
