@extends('backend.layouts.master')

@php
  $statusClass = match ($sale->status) {
    \App\Enums\Sales\SaleStatus::Draft => 'bg-dark-warning-gradient',
    \App\Enums\Sales\SaleStatus::ReadyToPost => 'bg-dark-info-gradient',
    \App\Enums\Sales\SaleStatus::Posted => 'bg-dark-success-gradient',
    \App\Enums\Sales\SaleStatus::Void => 'bg-dark-danger-gradient',
  };
  [$paymentStatusLabel, $paymentStatusClass] = match (true) {
    $sale->status !== \App\Enums\Sales\SaleStatus::Posted => ['Pending', 'bg-secondary-focus text-secondary-600'],
    (float) $sale->due_amount <= 0 => ['Paid', 'bg-success-focus text-success-600'],
    (float) $sale->paid_amount > 0 => ['Partially Paid', 'bg-warning-focus text-warning-600'],
    default => ['Unpaid', 'bg-danger-focus text-danger-600'],
  };
@endphp

@section('meta')
  <title>POS Sale {{ $sale->sale_no }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">POS Sale {{ $sale->sale_no }}</h6>
        <span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">
          {{ $sale->status->label() }}
        </span>
        <span class="badge {{ $paymentStatusClass }} px-10 py-6 radius-4">
          {{ $paymentStatusLabel }}
        </span>
        <x-backend.page-note title="POS Sale workflow" label="View POS Sale information" align="start">
          Draft and Ready to Post Sales have no stock or Finance effect. Confirm & Post validates current
          stock, posts payments and creates the balanced Sales journal in one transaction.
        </x-backend.page-note>
      </div>
      <p class="text-muted mb-0">Finished Product counter-sale preparation</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      @if ($sale->status === \App\Enums\Sales\SaleStatus::Draft)
        @perm('sales.pos.edit', 'edit')
          <a href="{{ route('sales.pos.edit', $sale) }}" class="btn btn-outline-primary px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="lucide:edit"></iconify-icon>
            <span>Edit Draft</span>
          </a>
        @endperm
      @elseif ($sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost)
        @perm('sales.pos.reopen', 'edit')
          <form method="POST" action="{{ route('sales.pos.reopen', $sale) }}"
                class="d-flex js-swal-confirm"
                data-confirm-title="Return this Sale to Draft?"
                data-confirm-text="Prepared payment rows will be cleared so the order can be edited."
                data-confirm-button="Yes, back to edit"
                data-confirm-color="#64748b">
            @csrf
            @method('PATCH')
            <button class="btn btn-outline-primary px-14 py-8 radius-8 app-action-button">
              <iconify-icon icon="mdi:arrow-u-left-top"></iconify-icon>
              <span>Back to Edit</span>
            </button>
          </form>
        @endperm
        @perm('sales.pos.post', 'edit')
          <form method="POST" action="{{ route('sales.pos.post', $sale) }}"
                class="d-flex js-swal-confirm"
                data-confirm-title="Confirm and post this Sale?"
                data-confirm-text="Finished Product stock, payments and Finance journal will be posted permanently."
                data-confirm-button="Yes, confirm & post"
                data-confirm-color="#198754">
            @csrf
            @method('PATCH')
            <button class="btn btn-success px-14 py-8 radius-8 app-action-button">
              <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon>
              <span>Confirm & Post Sale</span>
            </button>
          </form>
        @endperm
      @elseif ($sale->status === \App\Enums\Sales\SaleStatus::Posted)
        @if ((float) $sale->due_amount > 0 && $sale->customer_id)
          @perm('sales.customer-receivables.collect-modal', 'add')
            <button type="button"
                    class="btn btn-success px-14 py-8 radius-8 app-action-button AjaxModal"
                    data-ajax-modal="{{ route('sales.customer-receivables.collect-modal', $sale) }}"
                    data-size="lg"
                    data-onsuccess="PosSaleDetails.onDueCollected">
              <iconify-icon icon="mdi:cash-plus"></iconify-icon>
              <span>Collect Due</span>
            </button>
          @endperm
        @endif
        @perm('sales.pos.receipt', 'view')
          <a href="{{ route('sales.pos.receipt', $sale) }}" target="_blank"
             class="btn btn-outline-success px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:printer-outline"></iconify-icon>
            <span>Print Receipt</span>
          </a>
        @endperm
      @endif
      <a href="{{ route('sales.pos.index') }}" class="btn btn-outline-secondary px-14 py-8 radius-8 app-action-button">
        <iconify-icon icon="mdi:arrow-left"></iconify-icon>
        <span>Back to list</span>
      </a>
    </div>
  </div>

  @if ($sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost)
    <div class="alert alert-info d-flex align-items-start gap-2 mb-24">
      <iconify-icon icon="mdi:information-outline" class="text-xl mt-1"></iconify-icon>
      <div>
        <strong>Review before posting</strong>
        <div class="text-sm">
          Payment information is prepared only. Stock, account balances and the Sales journal are unchanged.
        </div>
      </div>
    </div>
  @endif

  <div class="card mb-24">
    <div class="card-header">
      <h5 class="card-title mb-0">Sale Information</h5>
    </div>
    <div class="card-body">
      <div class="row gy-3">
        <div class="col-md-3"><small class="text-muted d-block">Channel</small><strong>{{ $sale->channel->label() }}</strong></div>
        <div class="col-md-3">
          <small class="text-muted d-block">Customer</small>
          <strong>{{ $sale->customer?->name ?? 'Walk-in Customer' }}</strong>
        </div>
        <div class="col-md-3"><small class="text-muted d-block">Branch</small><strong>{{ $sale->branch->name }}</strong></div>
        <div class="col-md-3">
          <small class="text-muted d-block">Issuing Warehouse</small>
          <strong>{{ $sale->warehouse->name }} ({{ $sale->warehouse->code }})</strong>
        </div>
        <div class="col-md-3">
          <small class="text-muted d-block">Sale Date & Time</small>
          <strong>{{ $sale->sale_at->format('d M Y, h:i A') }}</strong>
        </div>
        <div class="col-md-3">
          <small class="text-muted d-block">Paid Amount</small>
          <strong class="text-success">{{ number_format((float) $sale->paid_amount, 2) }}</strong>
        </div>
        <div class="col-md-3">
          <small class="text-muted d-block">Due Amount</small>
          <strong class="{{ (float) $sale->due_amount > 0 ? 'text-danger' : 'text-muted' }}">
            {{ number_format((float) $sale->due_amount, 2) }}
          </strong>
        </div>
        <div class="col-md-6">
          <small class="text-muted d-block">Finance Journal</small>
          @if ($journal)
            <strong>{{ $journal->voucher_no }} · {{ $journal->status->value }}</strong>
          @else
            <strong class="text-muted">
              {{ $sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost ? 'Created after confirmation' : 'Not created for Draft' }}
            </strong>
          @endif
        </div>
        <div class="col-md-12">
          <small class="text-muted d-block">Note</small>
          <strong>{{ $sale->note ?: '—' }}</strong>
        </div>
      </div>
    </div>
  </div>

  @if (in_array($sale->status, [
    \App\Enums\Sales\SaleStatus::ReadyToPost,
    \App\Enums\Sales\SaleStatus::Posted,
  ], true))
    <div class="card mb-24">
      <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <h5 class="card-title mb-0">Payment Breakdown</h5>
        <span class="badge bg-success-focus text-success-600 px-12 py-7 radius-4">
          {{ $sale->status === \App\Enums\Sales\SaleStatus::ReadyToPost ? 'Prepared' : 'Posted' }}
          · {{ $sale->payments->count() }} {{ \Illuminate\Support\Str::plural('payment', $sale->payments->count()) }}
        </span>
      </div>
      <div class="card-body">
        @if ($sale->payments->isEmpty())
          <div class="alert alert-warning mb-0">
            No payment was collected at checkout. The complete amount remains as Customer Receivable.
          </div>
        @else
          <div class="table-responsive">
            <table class="table bordered-table mb-0">
              <thead>
                <tr>
                  <th>Payment No.</th>
                  <th>Source</th>
                  <th>Method</th>
                  <th>Receiving Account</th>
                  <th>Reference</th>
                  <th class="text-end">Tendered</th>
                  <th class="text-end">Applied</th>
                  <th class="text-end">Change</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($sale->payments as $payment)
                  <tr>
                    <td class="fw-semibold">{{ $payment->payment_no }}</td>
                    <td>
                      @if ($payment->customerReceipt)
                        <span class="text-primary fw-medium">Due Collection</span><br>
                        <a href="{{ route('sales.customer-receivables.receipt', $payment->customerReceipt) }}"
                           target="_blank" class="small text-decoration-none" title="Print collection receipt">
                          {{ $payment->customerReceipt->receipt_no }}
                          <iconify-icon icon="mdi:printer-outline"></iconify-icon>
                        </a>
                      @else
                        <span class="text-muted">Sale Checkout</span>
                      @endif
                    </td>
                    <td>{{ $payment->paymentType->name }}</td>
                    <td>{{ $payment->account->code }} — {{ $payment->account->name }}</td>
                    <td>{{ $payment->reference_no ?: '—' }}</td>
                    <td class="text-end">{{ number_format((float) $payment->tendered_amount, 2) }}</td>
                    <td class="text-end fw-semibold">{{ number_format((float) $payment->amount, 2) }}</td>
                    <td class="text-end {{ (float) $payment->change_amount > 0 ? 'text-primary fw-semibold' : 'text-muted' }}">
                      {{ number_format((float) $payment->change_amount, 2) }}
                    </td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot>
                <tr class="table-light">
                  <th colspan="6" class="text-end">Applied Payment Total</th>
                  <th class="text-end">{{ number_format((float) $sale->paid_amount, 2) }}</th>
                  <th class="text-end">
                    {{ number_format((float) $sale->payments->sum('change_amount'), 2) }}
                  </th>
                </tr>
              </tfoot>
            </table>
          </div>
        @endif
      </div>
    </div>
  @endif

  <div class="card mb-24">
    <div class="card-header">
      <h5 class="card-title mb-0">Finished Products</h5>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table mb-0">
          <thead>
            <tr>
              <th style="width: 60px">#</th>
              <th>Finished Product</th>
              <th>Unit</th>
              <th class="text-end">Quantity</th>
              <th class="text-end">Price / Unit</th>
              <th class="text-end">Line Total</th>
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
              <th colspan="5" class="text-end">
                Discount
                ({{ $sale->discount_type === 'PERCENT' ? number_format((float) $sale->discount_value, 2).'%' : 'Flat' }})
              </th>
              <th class="text-end">{{ number_format((float) $sale->discount, 2) }}</th>
            </tr>
            <tr>
              <th colspan="5" class="text-end">Tax</th>
              <th class="text-end">{{ number_format((float) $sale->tax, 2) }}</th>
            </tr>
            <tr class="table-light">
              <th colspan="5" class="text-end fs-6">Sale Total</th>
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
    window.PosSaleDetails = {
      onDueCollected: function() {
        window.location.reload();
      }
    };
  </script>
@endsection
