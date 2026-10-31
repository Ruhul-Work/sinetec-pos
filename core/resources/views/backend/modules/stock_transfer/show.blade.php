@extends('backend.layouts.master')

@php
  $status = $transfer->status;
  $statusClass = match ($status) {
      \App\Enums\Inventory\StockTransferStatus::Draft => 'bg-dark-warning-gradient',
      \App\Enums\Inventory\StockTransferStatus::InTransit => 'bg-dark-info-gradient',
      \App\Enums\Inventory\StockTransferStatus::Posted => 'bg-dark-success-gradient',
      default => 'bg-dark-danger-gradient',
  };
@endphp

@section('meta') <title>{{ $transfer->transfer_no }}</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div><h6 class="fw-semibold mb-1">Stock Transfer {{ $transfer->transfer_no }}</h6><span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">{{ $status->label() }}</span></div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="{{ route('inventory.stock-transfers.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>
      @if ($status === \App\Enums\Inventory\StockTransferStatus::Draft)
        @perm('inventory.stock-transfers.update', 'edit')
          <a href="{{ route('inventory.stock-transfers.edit', $transfer) }}" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:pencil-outline"></iconify-icon><span>Edit</span></a>
          <form method="POST" action="{{ route('inventory.stock-transfers.post', $transfer) }}" class="d-flex js-swal-confirm" data-confirm-title="Post stock transfer?" data-confirm-text="Source and destination warehouse balances will be updated permanently." data-confirm-button="Yes, post transfer" data-confirm-color="#198754">@csrf @method('PATCH')<button class="btn btn-success btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:swap-horizontal-bold"></iconify-icon><span>Post Transfer</span></button></form>
        @endperm
      @endif
    </div>
  </div>

  <div class="card mb-24"><div class="card-header"><h5 class="card-title mb-0">Transfer Information</h5></div><div class="card-body"><div class="row gy-3">
    <div class="col-md-4"><small class="text-muted d-block">Source</small><strong>{{ $transfer->sourceWarehouse->name }}</strong><br><code>{{ $transfer->sourceWarehouse->code }}</code> · {{ $transfer->sourceWarehouse->branch->name }}</div>
    <div class="col-md-4"><small class="text-muted d-block">Destination</small><strong>{{ $transfer->destinationWarehouse->name }}</strong><br><code>{{ $transfer->destinationWarehouse->code }}</code> · {{ $transfer->destinationWarehouse->branch->name }}</div>
    <div class="col-md-4"><small class="text-muted d-block">Transfer Date</small><strong>{{ $transfer->transfer_at->format('d M Y, h:i A') }}</strong></div>
    <div class="col-md-4"><small class="text-muted d-block">Created By</small><strong>{{ $transfer->createdBy?->name ?? 'System' }}</strong></div>
    @if ($transfer->posted_at)<div class="col-md-4"><small class="text-muted d-block">Posted</small><span>{{ $transfer->posted_at->format('d M Y, h:i A') }} by {{ $transfer->postedBy?->name ?? 'System' }}</span></div>@endif
    @if ($transfer->note)<div class="col-md-8"><small class="text-muted d-block">Note</small><span>{{ $transfer->note }}</span></div>@endif
  </div></div></div>

  <div class="card"><div class="card-header"><h5 class="card-title mb-0">Transfer Lines</h5></div><div class="card-body"><div class="table-responsive">
    <table class="table bordered-table align-middle mb-0"><thead><tr><th>#</th><th>Type</th><th>Item</th><th>Entered Unit</th><th class="text-end">Entered Qty</th><th class="text-end">Base Qty</th></tr></thead><tbody>
      @forelse ($lines as $index => $line)<tr><td>{{ $index + 1 }}</td><td><span class="badge bg-light text-dark">{{ $line['domain_label'] }}</span></td><td><strong>{{ $line['item_text'] }}</strong></td><td>{{ $line['unit_text'] }}</td><td class="text-end">{{ $line['quantity_input'] }}</td><td class="text-end fw-semibold">{{ rtrim(rtrim(number_format((float) $line['base_quantity'], 6, '.', ','), '0'), '.') }} {{ $line['base_unit_code'] }}</td></tr>
      @empty<tr><td colspan="6" class="text-center text-muted">No lines found.</td></tr>@endforelse
    </tbody></table>
  </div></div></div>
@endsection
