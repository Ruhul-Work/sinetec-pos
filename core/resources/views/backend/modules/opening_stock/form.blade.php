@extends('backend.layouts.master')

@php
  $editing = (bool) $adjustment;
  $submittedLines = old('lines', $lines);
  if (empty($submittedLines)) {
      $submittedLines = [['domain' => 'raw_material', 'item_id' => null, 'item_text' => null, 'unit_id' => null, 'unit_text' => null, 'quantity' => null, 'unit_cost' => null, 'note' => null]];
  }
@endphp

@section('meta')
  <title>{{ $editing ? 'Edit' : 'Add' }} Opening Stock</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $editing ? 'Edit Opening Stock' : 'Add Opening Stock' }}</h6>
        <x-backend.page-note title="Before saving" label="View opening stock rules" align="start">
          Quantity may use the item's base unit or one of its mapped alternative units. Base quantity is calculated on the server.
          Cost is always entered per base unit. A Draft can be edited; after approval it becomes read-only, and only posting changes stock.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">{{ $editing ? $adjustment->adjustment_no : 'Create a controlled starting-stock document' }}</p>
    </div>
    <a href="{{ route('inventory.opening-stocks.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to List</span></a>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger"><strong>Please correct the highlighted information.</strong><ul class="mb-0 mt-1">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ $editing ? route('inventory.opening-stocks.update', $adjustment) : route('inventory.opening-stocks.store') }}" id="openingStockForm">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">Document Information</h5></div>
      <div class="card-body">
        <div class="row gy-3">
          <div class="col-lg-4">
            <label class="form-label">Warehouse <span class="text-danger">*</span></label>
            <select name="warehouse_id" class="form-control js-s2" placeholder="Select warehouse" required>
              <option value=""></option>
              @foreach ($warehouses as $warehouse)
                <option value="{{ $warehouse->id }}" @selected((string) old('warehouse_id', $adjustment?->warehouse_id) === (string) $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }}) — {{ $warehouse->branch_name }}</option>
              @endforeach
            </select>
            @error('warehouse_id') <div class="text-danger text-sm mt-1">{{ $message }}</div> @enderror
          </div>
          <div class="col-lg-4">
            <label class="form-label">Opening Date & Time <span class="text-danger">*</span></label>
            <input type="datetime-local" name="adjustment_at" class="form-control" value="{{ old('adjustment_at', $adjustment?->adjustment_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" required>
          </div>
          <div class="col-lg-4">
            <label class="form-label">Reason</label>
            <input type="text" name="reason" class="form-control" maxlength="191" value="{{ old('reason', $adjustment?->reason) }}" placeholder="Example: Initial system stock">
          </div>
          <div class="col-12">
            <label class="form-label">Document Note</label>
            <textarea name="note" class="form-control" rows="2" maxlength="5000" placeholder="Optional note">{{ old('note', $adjustment?->note) }}</textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
        <h5 class="card-title mb-0">Opening Stock Lines</h5>
        <button type="button" class="btn btn-outline-primary btn-sm px-12 py-8 radius-8 app-action-button" id="addOpeningLine"><iconify-icon icon="mdi:plus"></iconify-icon><span>Add Line</span></button>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table bordered-table align-middle mb-0" id="openingLinesTable">
            <thead><tr><th style="min-width:190px">Inventory Type</th><th style="min-width:260px">Item</th><th style="min-width:270px">Unit</th><th style="min-width:110px">Quantity</th><th style="min-width:125px">Cost / Base Unit</th><th style="min-width:140px">Line Note</th><th style="width:55px"></th></tr></thead>
            <tbody>
              @foreach ($submittedLines as $index => $line)
                @include('backend.modules.opening_stock.line', ['index' => $index, 'line' => $line])
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="text-muted text-sm mt-3 mb-0 d-flex align-items-center gap-1"><iconify-icon icon="mdi:information-outline" class="flex-shrink-0"></iconify-icon><span>Alternative-unit quantity is converted to base quantity during save. Cost always means cost per base unit.</span></p>
      </div>
      <div class="card-footer d-flex align-items-center justify-content-end gap-2">
        <a href="{{ route('inventory.opening-stocks.index') }}" class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button"><span>Cancel</span></a>
        <button type="submit" class="btn btn-primary px-18 py-9 radius-8 app-action-button app-primary-action"><iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>Save Draft</span></button>
      </div>
    </div>
  </form>

  <template id="openingLineTemplate">
    @include('backend.modules.opening_stock.line', ['index' => '__INDEX__', 'line' => []])
  </template>
@endsection

@section('script')
<script>
  (function($) {
    const itemUrl = @json(route('inventory.opening-stocks.items.select2'));
    const unitUrl = @json(route('inventory.opening-stocks.units.select2'));
    let nextIndex = {{ count($submittedLines) }};

    function initRow($row) {
      const $domain = $row.find('.js-opening-domain');
      const $item = $row.find('.js-opening-item');
      const $unit = $row.find('.js-opening-unit');

      S2.init($domain, {allowClear: false, minimumResultsForSearch: Infinity});
      S2.init($item, {
        ajax: {
          url: itemUrl,
          dataType: 'json',
          delay: 250,
          data: params => ({q: params.term || '', domain: $domain.val()}),
          processResults: data => ({results: data.results || []})
        }
      });
      S2.init($unit, {
        ajax: {
          url: unitUrl,
          dataType: 'json',
          delay: 200,
          data: params => ({q: params.term || '', domain: $domain.val(), item_id: $item.val()}),
          processResults: data => ({results: data.results || []})
        }
      });
      $unit.prop('disabled', !$item.val());
      applyUnitPrecision($row, Number($unit.data('selectedPrecision') ?? 6));
    }

    function applyUnitPrecision($row, precision) {
      precision = Math.max(0, Math.min(6, Number.isFinite(precision) ? precision : 6));
      const step = precision === 0 ? '1' : `0.${'0'.repeat(precision - 1)}1`;
      $row.find('.js-opening-quantity').attr({step: step, min: step});
    }

    $('#openingLinesTable tbody tr').each(function() { initRow($(this)); });
    S2.auto($('#openingStockForm'));

    $('#addOpeningLine').on('click', function() {
      const html = $('#openingLineTemplate').html().replaceAll('__INDEX__', nextIndex++);
      const $row = $(html).appendTo('#openingLinesTable tbody');
      initRow($row);
    });

    $(document).on('change', '.js-opening-domain', function() {
      const $row = $(this).closest('tr');
      $row.find('.js-opening-item').empty().trigger('change');
      $row.find('.js-opening-unit').empty().prop('disabled', true).trigger('change');
    });

    $(document).on('change', '.js-opening-item', function() {
      const $unit = $(this).closest('tr').find('.js-opening-unit');
      $unit.empty().prop('disabled', !$(this).val()).trigger('change');
    });

    $(document).on('select2:select', '.js-opening-unit', function(event) {
      applyUnitPrecision($(this).closest('tr'), Number(event.params?.data?.precision ?? 6));
    });

    $(document).on('click', '.btn-remove-opening-line', function() {
      const $rows = $('#openingLinesTable tbody tr');
      if ($rows.length === 1) {
        $(this).closest('tr').find('input').val('');
        $(this).closest('tr').find('select.js-opening-item, select.js-opening-unit').empty().trigger('change');
        return;
      }
      $(this).closest('tr').remove();
    });
  })(jQuery);
</script>
@endsection
