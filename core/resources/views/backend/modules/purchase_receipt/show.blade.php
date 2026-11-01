@extends('backend.layouts.master')
@php $posted=$receipt->status===\App\Enums\Purchase\PurchaseReceiptStatus::Posted; @endphp
@section('meta')<title>{{ $receipt->receipt_no }}</title>@endsection
@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24"><div><h6 class="fw-semibold mb-1">Purchase Receipt {{ $receipt->receipt_no }}</h6><span class="badge text-sm fw-semibold {{ $posted?'bg-dark-success-gradient':'bg-dark-warning-gradient' }} px-12 py-7 radius-4 text-white">{{ $receipt->status->label() }}</span></div><div class="d-flex gap-2"><a href="{{ route('purchase.receipts.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>@if(!$posted)@perm('purchase.receipts.update','edit')<a href="{{ route('purchase.receipts.edit',$receipt) }}" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:pencil-outline"></iconify-icon><span>Edit</span></a>@endperm @perm('purchase.receipts.post','edit')<form method="POST" action="{{ route('purchase.receipts.post',$receipt) }}" class="d-flex js-swal-confirm" data-confirm-title="Post this purchase receipt?" data-confirm-text="Warehouse stock and linked purchase-order progress will be updated permanently." data-confirm-button="Yes, post receipt" data-confirm-color="#0f766e">@csrf @method('PATCH')<button class="btn btn-info btn-sm px-14 py-8 radius-8 text-white app-action-button"><iconify-icon icon="mdi:package-variant-plus"></iconify-icon><span>Post Receipt</span></button></form>@endperm @else @perm('purchase.returns.store','add')<a href="{{ route('purchase.returns.create',['purchase_receipt_id'=>$receipt->id]) }}" class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:package-variant-remove"></iconify-icon><span>Create Return</span></a>@endperm @endif</div></div>
<div class="card mb-24">
  <div class="card-header"><h5 class="card-title mb-0">Receipt Information</h5></div>
  <div class="card-body">
    <div class="row gy-3">
      <div class="col-md-3"><small class="text-muted d-block">Supplier</small><strong>{{ $receipt->supplier->name }}</strong></div>
      <div class="col-md-3"><small class="text-muted d-block">Warehouse</small><strong>{{ $receipt->warehouse->name }}</strong><br><code>{{ $receipt->warehouse->code }}</code></div>
      <div class="col-md-3"><small class="text-muted d-block">Receipt Date</small><strong>{{ $receipt->receipt_date->format('d M Y') }}</strong></div>
      <div class="col-md-3"><small class="text-muted d-block">Source</small>@if($receipt->order)<a href="{{ route('purchase.orders.show',$receipt->order) }}" class="fw-semibold">{{ $receipt->order->order_no }}</a>@else<span>Direct Receipt</span>@endif</div>
      <div class="col-md-3">
        <small class="text-muted d-block mb-1">Supplier Invoice</small>
        @if($receipt->supplier_invoice_path)
          <a href="{{ asset($receipt->supplier_invoice_path) }}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-1 px-10 py-4 radius-6 bg-info-focus text-info-main fw-semibold text-sm text-decoration-none"><iconify-icon icon="mdi:file-document-outline"></iconify-icon><span>View attachment</span></a>
        @elseif($receipt->supplier_invoice_no)
          <span>{{ $receipt->supplier_invoice_no }}</span>
        @else
          <span>-</span>
        @endif
      </div>
      @if($receipt->note)<div class="col-md-5"><small class="text-muted d-block">Note</small><span>{{ $receipt->note }}</span></div>@endif
      @if($receipt->posted_at)<div class="col-md-4"><small class="text-muted d-block">Posted</small><span>{{ $receipt->posted_at->format('d M Y, h:i A') }} by {{ $receipt->postedBy?->name??'System' }}</span></div>@endif
    </div>
  </div>
</div>
<div class="card"><div class="card-header"><h5 class="card-title mb-0">Received Lines</h5></div><div class="card-body"><div class="table-responsive"><table class="table bordered-table align-middle mb-0"><thead><tr><th>#</th><th>Type</th><th>Item</th><th>Unit</th><th class="text-end">Quantity</th><th class="text-end">Base Quantity</th><th class="text-end">Rate / Unit</th><th class="text-end">Line Total</th></tr></thead><tbody>@foreach($lines as $i=>$line)<tr><td>{{ $i+1 }}</td><td><span class="badge bg-light text-dark">{{ $line['domain_label'] }}</span></td><td><strong>{{ $line['item_text'] }}</strong></td><td>{{ $line['unit_text'] }}</td><td class="text-end">{{ $line['quantity_display'] }}</td><td class="text-end fw-semibold">{{ $line['base_quantity_display'] }} {{ $line['base_unit_code'] }}</td><td class="text-end">{{ number_format((float)$line['unit_rate'],2) }}</td><td class="text-end fw-semibold">{{ number_format((float)$line['line_total'],2) }}</td></tr>@endforeach</tbody></table></div><div class="row justify-content-end mt-4"><div class="col-xl-4 col-lg-5"><div class="d-flex justify-content-between mb-2"><span>Subtotal</span><strong>{{ number_format((float)$receipt->subtotal,2) }}</strong></div><div class="d-flex justify-content-between mb-2"><span>Discount {{ $receipt->discount_type==='PERCENT'?'('.rtrim(rtrim(number_format((float)$receipt->discount_value,2,'.',''),'0'),'.').'%)':'(Flat)' }}</span><span>{{ number_format((float)$receipt->discount,2) }}</span></div><div class="d-flex justify-content-between mb-2"><span>Tax</span><span>{{ number_format((float)$receipt->tax,2) }}</span></div><hr><div class="d-flex justify-content-between"><strong>Receipt Total</strong><strong class="text-primary-600 fs-6">{{ number_format((float)$receipt->total,2) }}</strong></div></div></div></div></div>
@endsection
