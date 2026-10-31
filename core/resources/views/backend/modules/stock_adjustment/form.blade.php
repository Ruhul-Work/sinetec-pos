@extends('backend.layouts.master')

@php
  $editing = (bool) $adjustment;
  $submittedLines = old('lines', $lines);
  if (empty($submittedLines)) {
      $submittedLines = [['domain' => 'raw_material', 'item_id' => null, 'unit_id' => null, 'direction' => 'IN', 'quantity' => null, 'unit_cost' => null, 'note' => null]];
  }
  $selectedType = old('adjustment_type', $adjustment?->adjustment_type ?? 'GAIN');
@endphp

@section('meta') <title>{{ $editing ? 'Edit' : 'Add' }} Stock Adjustment</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $editing ? 'Edit Stock Adjustment' : 'Add Stock Adjustment' }}</h6>
        <x-backend.page-note title="Before saving" label="View adjustment rules" align="start">
          Gain adds stock. Loss, Damage and Wastage remove stock. Correction lets you select IN or OUT per line.
          Quantity may use a mapped alternative unit; conversion is server-controlled. Only posting changes stock.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">{{ $editing ? $adjustment->adjustment_no : 'Create a controlled warehouse stock adjustment' }}</p>
    </div>
    <a href="{{ route('inventory.stock-adjustments.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to List</span></a>
  </div>

  @if ($errors->any())<div class="alert alert-danger"><strong>Please correct the highlighted information.</strong><ul class="mb-0 mt-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

  <form method="POST" action="{{ $editing ? route('inventory.stock-adjustments.update', $adjustment) : route('inventory.stock-adjustments.store') }}" id="stockAdjustmentForm">
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">Document Information</h5></div>
      <div class="card-body"><div class="row gy-3">
        <div class="col-lg-3">
          <label class="form-label">Adjustment Type <span class="text-danger">*</span></label>
          <select name="adjustment_type" id="adjustmentType" class="form-control js-s2" required>
            @foreach ($types as $value => $label)<option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>@endforeach
          </select>
        </div>
        <div class="col-lg-3">
          <label class="form-label">Warehouse <span class="text-danger">*</span></label>
          <select name="warehouse_id" class="form-control js-s2" placeholder="Select warehouse" required><option value=""></option>
            @foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $adjustment?->warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}</option>@endforeach
          </select>
        </div>
        <div class="col-lg-3">
          <label class="form-label">Adjustment Date & Time <span class="text-danger">*</span></label>
          <input type="datetime-local" name="adjustment_at" class="form-control" value="{{ old('adjustment_at', $adjustment?->adjustment_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" required>
        </div>
        <div class="col-lg-3">
          <label class="form-label">Reason <span class="text-danger">*</span></label>
          <input type="text" name="reason" class="form-control" maxlength="191" value="{{ old('reason', $adjustment?->reason) }}" placeholder="Why is this adjustment needed?" required>
        </div>
        <div class="col-12"><label class="form-label">Document Note</label><textarea name="note" class="form-control" rows="2" maxlength="5000" placeholder="Optional note">{{ old('note', $adjustment?->note) }}</textarea></div>
      </div></div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <h5 class="card-title mb-0">Adjustment Lines</h5>
        <button type="button" class="btn btn-outline-primary btn-sm px-12 py-8 radius-8 app-action-button" id="addAdjustmentLine"><iconify-icon icon="mdi:plus"></iconify-icon><span>Add Line</span></button>
      </div>
      <div class="card-body">
        <div class="table-responsive"><table class="table bordered-table align-middle mb-0" id="adjustmentLinesTable">
          <thead><tr><th style="min-width:180px">Inventory Type</th><th style="min-width:250px">Item</th><th style="min-width:240px">Unit</th><th style="min-width:105px">Direction</th><th style="min-width:115px">Quantity</th><th style="min-width:130px">Cost / Base Unit</th><th style="min-width:150px">Line Note</th><th style="width:55px"></th></tr></thead>
          <tbody>@foreach ($submittedLines as $index => $line) @include('backend.modules.stock_adjustment.line', ['index' => $index, 'line' => $line]) @endforeach</tbody>
        </table></div>
        <p class="text-muted text-sm mt-3 mb-0 d-flex align-items-center gap-1"><iconify-icon icon="mdi:information-outline"></iconify-icon><span>For an OUT adjustment, posting is blocked when the warehouse does not have enough stock.</span></p>
      </div>
      <div class="card-footer d-flex align-items-center justify-content-end gap-2">
        <a href="{{ route('inventory.stock-adjustments.index') }}" class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button"><span>Cancel</span></a>
        <button type="submit" class="btn btn-primary px-18 py-9 radius-8 app-action-button app-primary-action"><iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>Save Draft</span></button>
      </div>
    </div>
  </form>
  <template id="adjustmentLineTemplate">@include('backend.modules.stock_adjustment.line', ['index' => '__INDEX__', 'line' => []])</template>
@endsection

@section('script')
<script>
  (function($) {
    const itemUrl = @json(route('inventory.stock-adjustments.items.select2'));
    const unitUrl = @json(route('inventory.stock-adjustments.units.select2'));
    let nextIndex = {{ count($submittedLines) }};

    function applyDirection() {
      const type = $('#adjustmentType').val();
      $('.js-adjustment-direction').each(function() {
        const $field = $(this);
        if (type === 'CORRECTION') {
          $field.prop('disabled', false).prop('required', true);
        } else {
          $field.val(type === 'GAIN' ? 'IN' : 'OUT').trigger('change.select2');
          $field.prop('required', false).prop('disabled', true);
        }
      });
    }
    function applyUnitPrecision($row, precision) {
      precision = Math.max(0, Math.min(6, Number.isFinite(precision) ? precision : 6));
      const step = precision === 0 ? '1' : `0.${'0'.repeat(precision - 1)}1`;
      $row.find('.js-adjustment-quantity').attr({step, min: step});
    }
    function initRow($row) {
      const $domain = $row.find('.js-adjustment-domain');
      const $item = $row.find('.js-adjustment-item');
      const $unit = $row.find('.js-adjustment-unit');
      const $direction = $row.find('.js-adjustment-direction');
      S2.init($domain, {allowClear: false, minimumResultsForSearch: Infinity});
      S2.init($direction, {allowClear: false, minimumResultsForSearch: Infinity});
      S2.init($item, {ajax: {url: itemUrl, dataType: 'json', delay: 250, data: params => ({q: params.term || '', domain: $domain.val()}), processResults: data => ({results: data.results || []})}});
      S2.init($unit, {ajax: {url: unitUrl, dataType: 'json', delay: 200, data: params => ({q: params.term || '', domain: $domain.val(), item_id: $item.val()}), processResults: data => ({results: data.results || []})}});
      $unit.prop('disabled', !$item.val());
      applyUnitPrecision($row, Number($unit.data('selectedPrecision') ?? 6));
      applyDirection();
    }

    $('#adjustmentLinesTable tbody tr').each(function() { initRow($(this)); });
    S2.auto($('#stockAdjustmentForm'));
    applyDirection();
    $('#adjustmentType').on('change', applyDirection);
    $('#addAdjustmentLine').on('click', function() {
      const $row = $($('#adjustmentLineTemplate').html().replaceAll('__INDEX__', nextIndex++)).appendTo('#adjustmentLinesTable tbody');
      initRow($row);
    });
    $(document).on('change', '.js-adjustment-domain', function() {
      const $row = $(this).closest('tr');
      $row.find('.js-adjustment-item').empty().trigger('change');
      $row.find('.js-adjustment-unit').empty().prop('disabled', true).trigger('change');
    });
    $(document).on('change', '.js-adjustment-item', function() {
      const $unit = $(this).closest('tr').find('.js-adjustment-unit');
      $unit.empty().prop('disabled', !$(this).val()).trigger('change');
    });
    $(document).on('select2:select', '.js-adjustment-unit', function(event) { applyUnitPrecision($(this).closest('tr'), Number(event.params?.data?.precision ?? 6)); });
    $(document).on('click', '.btn-remove-adjustment-line', function() {
      const $rows = $('#adjustmentLinesTable tbody tr');
      if ($rows.length === 1) {
        const $row = $(this).closest('tr');
        $row.find('input').val('');
        $row.find('.js-adjustment-item, .js-adjustment-unit').empty().trigger('change');
        return;
      }
      $(this).closest('tr').remove();
    });
  })(jQuery);
</script>
@endsection
