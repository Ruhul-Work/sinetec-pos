@extends('backend.layouts.master')

@php
  $status = $adjustment->status;
  $statusClass = match ($status) {
      \App\Enums\Inventory\StockAdjustmentStatus::Draft => 'bg-dark-warning-gradient',
      \App\Enums\Inventory\StockAdjustmentStatus::Approved => 'bg-dark-info-gradient',
      \App\Enums\Inventory\StockAdjustmentStatus::Posted => 'bg-dark-success-gradient',
      default => 'bg-dark-danger-gradient',
  };
@endphp

@section('meta') <title>{{ $adjustment->adjustment_no }}</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div><h6 class="fw-semibold mb-1">Stock Adjustment {{ $adjustment->adjustment_no }}</h6><div class="d-flex gap-2"><span class="badge bg-light text-dark px-12 py-7">{{ $typeLabel }}</span><span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">{{ $status->label() }}</span></div></div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="{{ route('inventory.stock-adjustments.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>
      @if ($status === \App\Enums\Inventory\StockAdjustmentStatus::Draft)
        @perm('inventory.stock-adjustments.update', 'edit')
          <a href="{{ route('inventory.stock-adjustments.edit', $adjustment) }}" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:pencil-outline"></iconify-icon><span>Edit</span></a>
          <form method="POST" action="{{ route('inventory.stock-adjustments.approve', $adjustment) }}" class="d-flex js-swal-confirm" data-confirm-title="Approve stock adjustment?" data-confirm-text="After approval this document becomes read-only and ready for posting." data-confirm-button="Yes, approve" data-confirm-color="#0f766e">@csrf @method('PATCH')<button class="btn btn-info btn-sm px-14 py-8 radius-8 text-white app-action-button"><iconify-icon icon="mdi:check-decagram-outline"></iconify-icon><span>Approve</span></button></form>
        @endperm
      @elseif ($status === \App\Enums\Inventory\StockAdjustmentStatus::Approved)
        @perm('inventory.stock-adjustments.post', 'edit')
          <form method="POST" action="{{ route('inventory.stock-adjustments.post', $adjustment) }}" class="d-flex js-swal-confirm" data-confirm-title="Post stock adjustment?" data-confirm-text="Warehouse stock, movement ledger and current balance will be changed permanently." data-confirm-button="Yes, post adjustment" data-confirm-color="#198754">@csrf @method('PATCH')<button class="btn btn-success btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:book-check-outline"></iconify-icon><span>Post to Stock</span></button></form>
        @endperm
      @endif
    </div>
  </div>

  <div class="card mb-24"><div class="card-header"><h5 class="card-title mb-0">Document Information</h5></div><div class="card-body"><div class="row gy-3">
    <div class="col-md-3"><small class="text-muted d-block">Type</small><strong>{{ $typeLabel }}</strong></div>
    <div class="col-md-3"><small class="text-muted d-block">Branch</small><strong>{{ $adjustment->branch->name }}</strong></div>
    <div class="col-md-3"><small class="text-muted d-block">Warehouse</small><strong>{{ $adjustment->warehouse->name }}</strong><br><code>{{ $adjustment->warehouse->code }}</code></div>
    <div class="col-md-3"><small class="text-muted d-block">Adjustment Date</small><strong>{{ $adjustment->adjustment_at->format('d M Y, h:i A') }}</strong></div>
    <div class="col-md-6"><small class="text-muted d-block">Reason</small><span>{{ $adjustment->reason }}</span></div>
    @if ($adjustment->note)<div class="col-md-6"><small class="text-muted d-block">Note</small><span>{{ $adjustment->note }}</span></div>@endif
    <div class="col-md-3"><small class="text-muted d-block">Created By</small><strong>{{ $adjustment->createdBy?->name ?? 'System' }}</strong></div>
    @if ($adjustment->approved_at)<div class="col-md-4"><small class="text-muted d-block">Approved</small><span>{{ $adjustment->approved_at->format('d M Y, h:i A') }} by {{ $adjustment->approvedBy?->name ?? 'System' }}</span></div>@endif
    @if ($adjustment->posted_at)<div class="col-md-4"><small class="text-muted d-block">Posted</small><span>{{ $adjustment->posted_at->format('d M Y, h:i A') }} by {{ $adjustment->postedBy?->name ?? 'System' }}</span></div>@endif
  </div></div></div>

  <div class="card"><div class="card-header"><h5 class="card-title mb-0">Adjustment Lines</h5></div><div class="card-body"><div class="table-responsive">
    <table class="table bordered-table align-middle mb-0"><thead><tr><th>#</th><th>Type</th><th>Item</th><th>Direction</th><th>Entered Unit</th><th class="text-end">Entered Qty</th><th class="text-end">Base Qty</th><th class="text-end">Cost / Base Unit</th><th>Note</th></tr></thead><tbody>
      @forelse ($lines as $index => $line)
        <tr><td>{{ $index + 1 }}</td><td><span class="badge bg-light text-dark">{{ $line['domain_label'] }}</span></td><td><strong>{{ $line['item_text'] }}</strong></td><td><span class="badge {{ $line['direction'] === 'IN' ? 'bg-success-focus text-success-main' : 'bg-danger-focus text-danger-main' }}">{{ $line['direction'] }}</span></td><td>{{ $line['unit_text'] }}</td><td class="text-end">{{ rtrim(rtrim(number_format((float) $line['quantity'], 6, '.', ','), '0'), '.') }}</td><td class="text-end fw-semibold">{{ rtrim(rtrim(number_format((float) $line['base_quantity'], 6, '.', ','), '0'), '.') }} {{ $line['base_unit_code'] }}</td><td class="text-end">{{ $line['unit_cost'] !== null ? number_format((float) $line['unit_cost'], 4) : '—' }}</td><td>{{ $line['note'] ?: '—' }}</td></tr>
      @empty <tr><td colspan="9" class="text-center text-muted">No lines found.</td></tr> @endforelse
    </tbody></table>
  </div></div></div>
@endsection
