@extends('backend.layouts.master')

@php
  $editing = (bool) $transfer;
  $submittedLines = old('lines', $lines);
  if (empty($submittedLines)) {
      $submittedLines = [['domain' => 'raw_material', 'item_id' => null, 'item_text' => null, 'unit_id' => null, 'unit_text' => null, 'quantity' => null]];
  }
@endphp

@section('meta') <title>{{ $editing ? 'Edit' : 'Add' }} Stock Transfer</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $editing ? 'Edit Stock Transfer' : 'Add Stock Transfer' }}</h6>
        <x-backend.page-note title="Before saving" label="View stock transfer rules" align="start">
          Source and destination must be different. Quantity can use the item's base unit or a mapped alternative unit.
          Drafts are editable and do not change stock; posting is permanent and requires enough source balance.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">{{ $editing ? $transfer->transfer_no : 'Create a controlled warehouse-to-warehouse movement' }}</p>
    </div>
    <a href="{{ route('inventory.stock-transfers.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to List</span></a>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger"><strong>Please correct the highlighted information.</strong><ul class="mb-0 mt-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ $editing ? route('inventory.stock-transfers.update', $transfer) : route('inventory.stock-transfers.store') }}" id="stockTransferForm">
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">Transfer Information</h5></div>
      <div class="card-body"><div class="row gy-3">
        <div class="col-lg-4">
          <label class="form-label">Source Warehouse <span class="text-danger">*</span></label>
          <select name="source_warehouse_id" id="sourceWarehouse" class="form-control js-s2" placeholder="Select source" required><option value=""></option>
            @foreach ($sourceWarehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) old('source_warehouse_id', $transfer?->source_warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}</option>@endforeach
          </select>
        </div>
        <div class="col-lg-4">
          <label class="form-label">Destination Warehouse <span class="text-danger">*</span></label>
          <select name="destination_warehouse_id" id="destinationWarehouse" class="form-control js-s2" placeholder="Select destination" required><option value=""></option>
            @foreach ($destinationWarehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) old('destination_warehouse_id', $transfer?->destination_warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}</option>@endforeach
          </select>
        </div>
        <div class="col-lg-4">
          <label class="form-label">Transfer Date & Time <span class="text-danger">*</span></label>
          <input type="datetime-local" name="transfer_at" class="form-control" value="{{ old('transfer_at', $transfer?->transfer_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" required>
        </div>
        <div class="col-12"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="2" maxlength="5000" placeholder="Optional transfer note">{{ old('note', $transfer?->note) }}</textarea></div>
      </div></div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <h5 class="card-title mb-0">Transfer Lines</h5>
        <button type="button" class="btn btn-outline-primary btn-sm px-12 py-8 radius-8 app-action-button" id="addTransferLine"><iconify-icon icon="mdi:plus"></iconify-icon><span>Add Line</span></button>
      </div>
      <div class="card-body"><div class="table-responsive">
        <table class="table bordered-table align-middle mb-0" id="transferLinesTable">
          <thead><tr><th style="min-width:200px">Inventory Type</th><th style="min-width:300px">Item</th><th style="min-width:280px">Unit</th><th style="min-width:130px">Quantity</th><th style="width:55px"></th></tr></thead>
          <tbody>@foreach ($submittedLines as $index => $line) @include('backend.modules.stock_transfer.line', ['index' => $index, 'line' => $line]) @endforeach</tbody>
        </table>
      </div></div>
      <div class="card-footer d-flex align-items-center justify-content-end gap-2">
        <a href="{{ route('inventory.stock-transfers.index') }}" class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button"><span>Cancel</span></a>
        <button type="submit" class="btn btn-primary px-18 py-9 radius-8 app-action-button app-primary-action"><iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>Save Draft</span></button>
      </div>
    </div>
  </form>
  <template id="transferLineTemplate">@include('backend.modules.stock_transfer.line', ['index' => '__INDEX__', 'line' => []])</template>
@endsection

@section('script')
<script>
  (function($) {
    const itemUrl = @json(route('inventory.stock-transfers.items.select2'));
    const unitUrl = @json(route('inventory.stock-transfers.units.select2'));
    let nextIndex = {{ count($submittedLines) }};
    function applyUnitPrecision($row, precision) {
      precision = Math.max(0, Math.min(6, Number.isFinite(precision) ? precision : 6));
      const step = precision === 0 ? '1' : `0.${'0'.repeat(precision - 1)}1`;
      $row.find('.js-transfer-quantity').attr({step: step, min: step});
    }
    function initRow($row) {
      const $domain = $row.find('.js-transfer-domain');
      const $item = $row.find('.js-transfer-item');
      const $unit = $row.find('.js-transfer-unit');
      S2.init($domain, {allowClear: false, minimumResultsForSearch: Infinity});
      S2.init($item, {ajax: {url: itemUrl, dataType: 'json', delay: 250, data: params => ({q: params.term || '', domain: $domain.val()}), processResults: data => ({results: data.results || []})}});
      S2.init($unit, {ajax: {url: unitUrl, dataType: 'json', delay: 200, data: params => ({q: params.term || '', domain: $domain.val(), item_id: $item.val()}), processResults: data => ({results: data.results || []})}});
      $unit.prop('disabled', !$item.val());
      applyUnitPrecision($row, Number($unit.data('selectedPrecision') ?? 6));
    }
    $('#transferLinesTable tbody tr').each(function() { initRow($(this)); });
    S2.auto($('#stockTransferForm'));
    $('#addTransferLine').on('click', function() { const $row = $($('#transferLineTemplate').html().replaceAll('__INDEX__', nextIndex++)).appendTo('#transferLinesTable tbody'); initRow($row); });
    $(document).on('change', '.js-transfer-domain', function() { const $row = $(this).closest('tr'); $row.find('.js-transfer-item').empty().trigger('change'); $row.find('.js-transfer-unit').empty().prop('disabled', true).trigger('change'); });
    $(document).on('change', '.js-transfer-item', function() { const $unit = $(this).closest('tr').find('.js-transfer-unit'); $unit.empty().prop('disabled', !$(this).val()).trigger('change'); });
    $(document).on('select2:select', '.js-transfer-unit', function(event) { applyUnitPrecision($(this).closest('tr'), Number(event.params?.data?.precision ?? 6)); });
    $(document).on('click', '.btn-remove-transfer-line', function() { const $rows = $('#transferLinesTable tbody tr'); if ($rows.length === 1) { $(this).closest('tr').find('input').val(''); $(this).closest('tr').find('select.js-transfer-item, select.js-transfer-unit').empty().trigger('change'); return; } $(this).closest('tr').remove(); });
  })(jQuery);
</script>
@endsection
