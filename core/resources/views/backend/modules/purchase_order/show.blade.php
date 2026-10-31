@extends('backend.layouts.master')

@php
  $status = $order->status;
  $statusClass = match ($status) {
      \App\Enums\Purchase\PurchaseOrderStatus::Draft => 'bg-dark-warning-gradient',
      \App\Enums\Purchase\PurchaseOrderStatus::Approved => 'bg-dark-info-gradient',
      \App\Enums\Purchase\PurchaseOrderStatus::Partial => 'bg-dark-lilac-gradient',
      \App\Enums\Purchase\PurchaseOrderStatus::Received => 'bg-dark-success-gradient',
      \App\Enums\Purchase\PurchaseOrderStatus::Cancelled => 'bg-dark-danger-gradient',
  };
@endphp

@section('meta') <title>{{ $order->order_no }}</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div><h6 class="fw-semibold mb-1">Purchase Order {{ $order->order_no }}</h6><span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">{{ $status->label() }}</span></div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="{{ route('purchase.orders.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>
      @if ($status === \App\Enums\Purchase\PurchaseOrderStatus::Draft)
        @perm('purchase.orders.update', 'edit')
          <a href="{{ route('purchase.orders.edit', $order) }}" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:pencil-outline"></iconify-icon><span>Edit</span></a>
        @endperm
        @perm('purchase.orders.approve', 'edit')
          <form method="POST" action="{{ route('purchase.orders.approve', $order) }}" class="d-flex js-swal-confirm" data-confirm-title="Approve purchase order?" data-confirm-text="After approval this order becomes read-only and can be used for Purchase Receipt." data-confirm-button="Yes, approve" data-confirm-color="#0f766e">@csrf @method('PATCH')<button class="btn btn-info btn-sm px-14 py-8 radius-8 text-white app-action-button"><iconify-icon icon="mdi:check-decagram-outline"></iconify-icon><span>Approve</span></button></form>
        @endperm
      @endif
      @if (in_array($status, [\App\Enums\Purchase\PurchaseOrderStatus::Draft, \App\Enums\Purchase\PurchaseOrderStatus::Approved], true))
        @perm('purchase.orders.cancel', 'edit')
          <form method="POST" action="{{ route('purchase.orders.cancel', $order) }}" class="d-flex js-swal-confirm" data-confirm-title="Cancel purchase order?" data-confirm-text="A cancelled order cannot be edited or used for receipt." data-confirm-button="Yes, cancel" data-confirm-color="#dc3545">@csrf @method('PATCH')<button class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:cancel"></iconify-icon><span>Cancel Order</span></button></form>
        @endperm
      @endif
    </div>
  </div>

  <div class="card mb-24"><div class="card-header"><h5 class="card-title mb-0">Order Information</h5></div><div class="card-body"><div class="row gy-3">
    <div class="col-md-3"><small class="text-muted d-block">Supplier</small><strong>{{ $order->supplier->name }}</strong>@if($order->supplier->code)<br><code>{{ $order->supplier->code }}</code>@endif</div>
    <div class="col-md-3"><small class="text-muted d-block">Branch</small><strong>{{ $order->branch->name }}</strong></div>
    <div class="col-md-3"><small class="text-muted d-block">Receiving Warehouse</small><strong>{{ $order->warehouse->name }}</strong><br><code>{{ $order->warehouse->code }}</code></div>
    <div class="col-md-3"><small class="text-muted d-block">Order Date</small><strong>{{ $order->order_date->format('d M Y') }}</strong></div>
    <div class="col-md-3"><small class="text-muted d-block">Expected Date</small><span>{{ $order->expected_date?->format('d M Y') ?? '-' }}</span></div>
    <div class="col-md-3"><small class="text-muted d-block">Created By</small><span>{{ $order->createdBy?->name ?? 'System' }}</span></div>
    @if ($order->approved_at)<div class="col-md-4"><small class="text-muted d-block">Approved</small><span>{{ $order->approved_at->format('d M Y, h:i A') }} by {{ $order->approvedBy?->name ?? 'System' }}</span></div>@endif
    @if ($order->note)<div class="col-12"><small class="text-muted d-block">Note</small><span>{{ $order->note }}</span></div>@endif
  </div></div></div>

  <div class="card"><div class="card-header"><h5 class="card-title mb-0">Order Lines</h5></div><div class="card-body">
    <div class="table-responsive"><table class="table bordered-table align-middle mb-0">
      <thead><tr><th>#</th><th>Type</th><th>Item</th><th>Purchase Unit</th><th class="text-end">Quantity</th><th class="text-end">Base Quantity</th><th class="text-end">Received</th><th class="text-end">Rate / Unit</th><th class="text-end">Line Total</th></tr></thead>
      <tbody>@forelse ($lines as $index => $line)
        <tr>
          <td>{{ $index + 1 }}</td><td><span class="badge bg-light text-dark">{{ $line['domain_label'] }}</span></td>
          <td><strong>{{ $line['item_text'] }}</strong></td><td>{{ $line['unit_text'] }}</td>
          <td class="text-end">{{ rtrim(rtrim(number_format((float) $line['quantity'], 6, '.', ','), '0'), '.') }}</td>
          <td class="text-end fw-semibold">{{ rtrim(rtrim(number_format((float) $line['base_quantity'], 6, '.', ','), '0'), '.') }} {{ $line['base_unit_code'] }}</td>
          <td class="text-end">{{ rtrim(rtrim(number_format((float) $line['received_base_quantity'], 6, '.', ','), '0'), '.') }} {{ $line['base_unit_code'] }}</td>
          <td class="text-end">{{ number_format((float) $line['unit_rate'], 2) }}</td><td class="text-end fw-semibold">{{ number_format((float) $line['line_total'], 2) }}</td>
        </tr>
      @empty <tr><td colspan="9" class="text-center text-muted">No lines found.</td></tr> @endforelse</tbody>
    </table></div>
    <div class="row justify-content-end mt-4"><div class="col-xl-4 col-lg-5">
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Subtotal</span><strong>{{ number_format((float) $order->subtotal, 2) }}</strong></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Discount @if($order->discount_type === 'PERCENT')({{ rtrim(rtrim(number_format((float) $order->discount_value, 2, '.', ''), '0'), '.') }}%)@else(Flat)@endif</span><span>{{ number_format((float) $order->discount, 2) }}</span></div>
      <div class="d-flex justify-content-between mb-2"><span class="text-muted">Tax</span><span>{{ number_format((float) $order->tax, 2) }}</span></div><hr>
      <div class="d-flex justify-content-between"><span class="fw-semibold">Order Total</span><strong class="text-primary-600 fs-6">{{ number_format((float) $order->total, 2) }}</strong></div>
    </div></div>
  </div></div>
@endsection
