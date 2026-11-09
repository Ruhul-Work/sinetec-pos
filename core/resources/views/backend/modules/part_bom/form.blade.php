@extends('backend.layouts.master')

@php
  $editing = $method === 'PUT';
  $existingLines = $partBom->relationLoaded('items')
      ? $partBom->items->map(fn ($item) => [
          'raw_material_id' => $item->raw_material_id,
          'raw_material_text' => $item->rawMaterial?->name.' ('.$item->rawMaterial?->material_code.')',
          'unit_id' => $item->unit_id,
          'required_quantity' => $item->required_quantity,
          'wastage_percent' => $item->wastage_percent,
      ])->values()->all()
      : old('lines', []);
@endphp

@section('meta')
  <title>{{ $title }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">{{ $title }}</h6>
      <p class="text-muted m-0">Draft a raw-material recipe. Activating it makes it available for future Part Production.</p>
    </div>
    <a href="{{ route('production.part-boms.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to list</span></a>
  </div>

  @if($errors->any())
    <div class="alert alert-danger"><strong>Please correct the highlighted information.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" action="{{ $action }}" id="partBomForm">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">BOM Information</h5></div>
      <div class="card-body">
        <div class="row g-3 part-bom-meta-grid">
          <div class="col-lg-6">
            <label class="form-label">Output Part <span class="text-danger">*</span></label>
            <select name="part_id" id="bomPart" class="form-control form-control-sm js-s2-ajax" data-url="{{ route('production.part-boms.parts.select2') }}" data-init-id="{{ old('part_id', $partBom->part_id) }}" data-init-text="{{ $partBom->part ? $partBom->part->name.' ('.$partBom->part->part_code.')' : '' }}" placeholder="Select Make or Both Part" required></select>
            <small class="text-muted">Only active Parts with Make or Both procurement mode are available.</small>
          </div>
          <div class="col-lg-2">
            <label class="form-label">Version <span class="text-danger">*</span></label>
            <input type="text" name="version" class="form-control form-control-sm text-uppercase" value="{{ old('version', $partBom->version ?: 'V1') }}" maxlength="30" required placeholder="V1">
          </div>
          <div class="col-lg-4">
            <div class="d-flex align-items-center gap-1 mb-6">
              <label class="form-label mb-0">Output Quantity <span class="text-danger">*</span></label>
              <x-backend.field-note label="Output quantity information">
                This recipe quantity is the expected good output. Material lines are calculated for this output quantity; future Production Batches can scale it.
              </x-backend.field-note>
            </div>
            <input type="number" name="output_quantity" class="form-control form-control-sm text-end" value="{{ old('output_quantity', rtrim(rtrim(number_format((float) ($partBom->output_quantity ?: 1), 6, '.', ''), '0'), '.')) }}" min="0.000001" step="0.000001" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Effective From</label>
            <input type="date" name="effective_from" class="form-control form-control-sm" value="{{ old('effective_from', $partBom->effective_from?->format('Y-m-d')) }}">
          </div>
          <div class="col-md-3">
            <label class="form-label">Effective To</label>
            <input type="date" name="effective_to" class="form-control form-control-sm" value="{{ old('effective_to', $partBom->effective_to?->format('Y-m-d')) }}">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div>
          <h5 class="card-title mb-0">Raw Material Components</h5>
          <p class="text-muted text-sm mb-0">Choose the production unit, required quantity and expected wastage for each input.</p>
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm px-14 radius-8 app-action-button" id="addBomLine"><iconify-icon icon="mdi:plus"></iconify-icon><span>Add Material</span></button>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table bordered-table align-top mb-0 part-bom-lines-table">
            <thead><tr><th style="min-width:280px">Raw Material</th><th style="min-width:190px">Production Unit</th><th class="text-end" style="min-width:150px">Required Qty</th><th class="text-end" style="min-width:145px">Wastage %</th><th style="width:70px"></th></tr></thead>
            <tbody id="bomLines"></tbody>
          </table>
        </div>
        <div id="emptyBomLines" class="text-center text-muted py-32"><iconify-icon icon="mdi:playlist-plus" class="text-3xl d-block mb-2"></iconify-icon>Add at least one Raw Material component.</div>
      </div>
      <div class="card-footer d-flex align-items-center justify-content-center gap-3">
        <a href="{{ route('production.part-boms.index') }}" class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8 app-action-button">Cancel</a>
        <button type="submit" class="btn btn-primary px-48 py-12 radius-8 app-action-button app-primary-action"><iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>{{ $editing ? 'Update Draft' : 'Save Draft' }}</span></button>
      </div>
    </div>
  </form>
@endsection

@section('script')
<script>
  const initialBomLines = @json($existingLines);
  const rawMaterialSelectUrl = @json(route('inventory.raw-materials.select2'));
  const rawMaterialUnitsUrl = @json(route('production.part-boms.raw-material-units', ['rawMaterial' => '__RAW_MATERIAL__']));
  let bomLineIndex = 0;

  S2.auto($('#partBomForm'));

  function escapeHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function toggleEmptyState() {
    $('#emptyBomLines').toggle($('#bomLines tr').length === 0);
  }

  function buildBomLine(line) {
    const index = bomLineIndex++;
    const row = $(
      `<tr data-line-index="${index}">
        <td><div class="bom-line-control"><select name="lines[${index}][raw_material_id]" class="form-control form-control-sm js-s2-ajax bom-material-select" data-url="${rawMaterialSelectUrl}" data-init-id="${line.raw_material_id || ''}" data-init-text="${escapeHtml(line.raw_material_text || '')}" placeholder="Select raw material" required></select><small class="text-muted bom-base-unit d-block mt-1"></small></div></td>
        <td><div class="bom-line-control bom-line-control--single"><select name="lines[${index}][unit_id]" class="form-select form-select-sm bom-unit-select" required disabled><option value="">Select material first</option></select></div></td>
        <td><div class="bom-line-control bom-line-control--single"><input type="number" name="lines[${index}][required_quantity]" class="form-control form-control-sm text-end" value="${line.required_quantity || ''}" min="0.000001" step="0.000001" required></div></td>
        <td><div class="bom-line-control bom-line-control--single"><input type="number" name="lines[${index}][wastage_percent]" class="form-control form-control-sm text-end" value="${line.wastage_percent || 0}" min="0" max="100" step="0.0001" required></div></td>
        <td class="text-center bom-remove-cell"><button type="button" class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main remove-bom-line" title="Remove material"><iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon></button></td>
      </tr>`
    );

    $('#bomLines').append(row);
    S2.auto(row);

    if (line.raw_material_id) {
      loadMaterialUnits(row, line.raw_material_id, line.unit_id);
    }

    toggleEmptyState();
  }

  function loadMaterialUnits(row, materialId, selectedUnitId) {
    const select = row.find('.bom-unit-select');
    const baseLabel = row.find('.bom-base-unit');
    select.prop('disabled', true).html('<option>Loading units...</option>');

    $.get(rawMaterialUnitsUrl.replace('__RAW_MATERIAL__', materialId))
      .done(function(response) {
        const options = ['<option value="">Select unit</option>'];
        response.units.forEach(function(unit) {
          options.push(`<option value="${unit.id}" data-factor="${unit.factor}">${escapeHtml(unit.text)}</option>`);
        });
        select.html(options.join('')).prop('disabled', false);
        select.val(String(selectedUnitId || response.units[0]?.id || '')).trigger('change');
        baseLabel.text(`Base unit: ${response.material.base_unit}`);
      })
      .fail(function() {
        select.html('<option value="">Unable to load units</option>').prop('disabled', true);
        baseLabel.text('');
      });
  }

  $('#addBomLine').on('click', function() {
    buildBomLine({});
  });

  $(document).on('change', '.bom-material-select', function() {
    const row = $(this).closest('tr');
    const materialId = $(this).val();
    if (materialId) {
      loadMaterialUnits(row, materialId, null);
    }
  });

  $(document).on('click', '.remove-bom-line', function() {
    $(this).closest('tr').remove();
    toggleEmptyState();
  });

  initialBomLines.forEach(buildBomLine);
  if (initialBomLines.length === 0) {
    buildBomLine({});
  }
</script>
@endsection
