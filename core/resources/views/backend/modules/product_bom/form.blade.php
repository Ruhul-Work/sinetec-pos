@extends('backend.layouts.master')

@php
  $editing = $method === 'PUT';
@endphp

@section('meta')
  <title>{{ $title }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">{{ $title }}</h6>
      <p class="text-muted m-0">
        Draft a mixed component recipe for future Finished Product Assembly.
      </p>
    </div>
    <a
      href="{{ route('production.product-boms.index') }}"
      class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"
    >
      <iconify-icon icon="mdi:arrow-left"></iconify-icon>
      <span>Back to list</span>
    </a>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger">
      <strong>Please correct the highlighted information.</strong>
      <ul class="mb-0 mt-1">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <form method="POST" action="{{ $action }}" id="productBomForm">
    @csrf
    @if ($editing)
      @method('PUT')
    @endif

    <div class="card mb-24">
      <div class="card-header">
        <div class="d-flex align-items-center gap-2">
          <h5 class="card-title mb-0">BOM/Recipe Information</h5>
          <x-backend.field-note label="Finished Product BOM/Recipe information">
            The output quantity is the expected good Finished Product quantity. Assembly can later scale all
            component quantities from this recipe.
          </x-backend.field-note>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3 part-bom-meta-grid">
          <div class="col-lg-6">
            <label class="form-label">
              Output Finished Product <span class="text-danger">*</span>
            </label>
            <select
              name="finished_product_id"
              id="bomFinishedProduct"
              class="form-control form-control-sm js-s2-ajax"
              data-url="{{ route('production.product-boms.finished-products.select2') }}"
              data-init-id="{{ old('finished_product_id', $productBom->finished_product_id) }}"
              data-init-text="{{ $productBom->finishedProduct
                ? $productBom->finishedProduct->name.' ('.$productBom->finishedProduct->product_code.')'
                : '' }}"
              placeholder="Select Finished Product"
              required
            ></select>
          </div>

          <div class="col-lg-2">
            <label class="form-label">Version <span class="text-danger">*</span></label>
            <input
              type="text"
              name="version"
              class="form-control form-control-sm text-uppercase"
              value="{{ old('version', $productBom->version ?: 'V1') }}"
              maxlength="30"
              placeholder="V1"
              required
            >
          </div>

          <div class="col-lg-4">
            <label class="form-label">Output Quantity <span class="text-danger">*</span></label>
            <input
              type="number"
              name="output_quantity"
              class="form-control form-control-sm text-end"
              value="{{ old('output_quantity', rtrim(rtrim(
                number_format((float) ($productBom->output_quantity ?: 1), 6, '.', ''),
                '0'
              ), '.')) }}"
              min="0.000001"
              step="0.000001"
              required
            >
          </div>

          <div class="col-md-3">
            <label class="form-label">Effective From</label>
            <input
              type="date"
              name="effective_from"
              class="form-control form-control-sm"
              value="{{ old('effective_from', $productBom->effective_from?->format('Y-m-d')) }}"
            >
          </div>

          <div class="col-md-3">
            <label class="form-label">Effective To</label>
            <input
              type="date"
              name="effective_to"
              class="form-control form-control-sm"
              value="{{ old('effective_to', $productBom->effective_to?->format('Y-m-d')) }}"
            >
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-2">
          <h5 class="card-title mb-0">Recipe Components</h5>
          <x-backend.field-note label="Recipe component information">
            Add each Raw Material or Part once. Select its production unit, required quantity and expected wastage.
          </x-backend.field-note>
        </div>
        <button
          type="button"
          class="btn btn-outline-primary btn-sm px-14 radius-8 app-action-button"
          id="addProductBomLine"
        >
          <iconify-icon icon="mdi:plus"></iconify-icon>
          <span>Add Component</span>
        </button>
      </div>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table bordered-table align-top mb-0 part-bom-lines-table product-bom-lines-table">
            <thead>
              <tr>
                <th style="min-width: 150px">Component Type</th>
                <th style="min-width: 270px">Component</th>
                <th style="min-width: 190px">Production Unit</th>
                <th class="text-end" style="min-width: 140px">Required Qty</th>
                <th class="text-end" style="min-width: 130px">Wastage %</th>
                <th style="width: 70px"></th>
              </tr>
            </thead>
            <tbody id="productBomLines"></tbody>
          </table>
        </div>

        <div id="emptyProductBomLines" class="text-center text-muted py-32">
          <iconify-icon icon="mdi:playlist-plus" class="text-3xl d-block mb-2"></iconify-icon>
          Add at least one Raw Material or Part component.
        </div>
      </div>

      <div class="card-footer d-flex align-items-center justify-content-center gap-3">
        <a
          href="{{ route('production.product-boms.index') }}"
          class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8 app-action-button"
        >
          Cancel
        </a>
        <button
          type="submit"
          class="btn btn-primary px-48 py-12 radius-8 app-action-button app-primary-action"
        >
          <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
          <span>{{ $editing ? 'Update Draft' : 'Save Draft' }}</span>
        </button>
      </div>
    </div>
  </form>
@endsection

@section('script')
@php
  $componentUnitsUrl = route('production.product-boms.component-units', [
    'componentType' => '__TYPE__',
    'component' => '__COMPONENT__',
  ]);
@endphp
<script>
  const initialProductBomLines = @json($existingLines);
  const componentSelectUrl = @json(route('production.product-boms.components.select2'));
  const componentUnitsUrl = @json($componentUnitsUrl);
  let productBomLineIndex = 0;

  S2.auto($('#productBomForm'));

  function escapeProductBomHtml(value) {
    return $('<div>').text(value || '').html();
  }

  function componentTypeSlug(type) {
    return type === 'PART' ? 'part' : 'raw-material';
  }

  function toggleProductBomEmptyState() {
    $('#emptyProductBomLines').toggle($('#productBomLines tr').length === 0);
  }

  function initializeComponentSelect(row, line) {
    const select = row.find('.product-bom-component-select');

    if (select.hasClass('select2-hidden-accessible')) {
      select.select2('destroy');
    }

    select.empty();

    if (line.component_id && line.component_text) {
      select.append(new Option(line.component_text, line.component_id, true, true));
    }

    S2.init(select, {
      ajax: {
        url: componentSelectUrl,
        dataType: 'json',
        delay: 250,
        data: function(params) {
          return {
            q: params?.term || '',
            type: row.find('.product-bom-component-type').val(),
          };
        },
        processResults: function(response) {
          return { results: response.results || [] };
        },
      },
    });
  }

  function buildProductBomLine(line) {
    const index = productBomLineIndex++;
    const type = line.component_type || 'RAW_MATERIAL';
    const rawSelected = type === 'RAW_MATERIAL' ? 'selected' : '';
    const partSelected = type === 'PART' ? 'selected' : '';
    const row = $(
      `<tr data-line-index="${index}">
        <td>
          <div class="bom-line-control bom-line-control--single">
            <select name="lines[${index}][component_type]"
              class="form-select form-select-sm product-bom-component-type" required>
              <option value="RAW_MATERIAL" ${rawSelected}>Raw Material</option>
              <option value="PART" ${partSelected}>Part</option>
            </select>
          </div>
        </td>
        <td>
          <div class="bom-line-control">
            <select name="lines[${index}][component_id]"
              class="form-control form-control-sm product-bom-component-select"
              placeholder="Select component" required></select>
            <small class="text-muted product-bom-base-unit d-block mt-1"></small>
          </div>
        </td>
        <td>
          <div class="bom-line-control bom-line-control--single">
            <select name="lines[${index}][unit_id]"
              class="form-select form-select-sm product-bom-unit-select" required disabled>
              <option value="">Select component first</option>
            </select>
          </div>
        </td>
        <td>
          <div class="bom-line-control bom-line-control--single">
            <input type="number" name="lines[${index}][required_quantity]"
              class="form-control form-control-sm text-end"
              value="${escapeProductBomHtml(line.required_quantity || '')}"
              min="0.000001" step="0.000001" required>
          </div>
        </td>
        <td>
          <div class="bom-line-control bom-line-control--single">
            <input type="number" name="lines[${index}][wastage_percent]"
              class="form-control form-control-sm text-end"
              value="${escapeProductBomHtml(line.wastage_percent || 0)}"
              min="0" max="100" step="0.0001" required>
          </div>
        </td>
        <td class="text-center bom-remove-cell">
          <button type="button"
            class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center
              justify-content-center bg-danger-focus text-danger-main remove-product-bom-line"
            title="Remove component">
            <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
          </button>
        </td>
      </tr>`,
    );

    $('#productBomLines').append(row);
    initializeComponentSelect(row, line);

    if (line.component_id) {
      loadProductBomComponentUnits(row, type, line.component_id, line.unit_id);
    }

    toggleProductBomEmptyState();
  }

  function loadProductBomComponentUnits(row, type, componentId, selectedUnitId) {
    const select = row.find('.product-bom-unit-select');
    const baseLabel = row.find('.product-bom-base-unit');
    const requestUrl = componentUnitsUrl
      .replace('__TYPE__', componentTypeSlug(type))
      .replace('__COMPONENT__', componentId);

    select.prop('disabled', true).html('<option>Loading units...</option>');

    $.get(requestUrl)
      .done(function(response) {
        const options = ['<option value="">Select unit</option>'];

        response.units.forEach(function(unit) {
          options.push(
            `<option value="${unit.id}" data-factor="${unit.factor}">`
              + `${escapeProductBomHtml(unit.text)}</option>`,
          );
        });

        select.html(options.join('')).prop('disabled', false);
        select.val(String(selectedUnitId || response.units[0]?.id || '')).trigger('change');
        baseLabel.text(`Base unit: ${response.component.base_unit}`);
      })
      .fail(function() {
        select.html('<option value="">Unable to load units</option>').prop('disabled', true);
        baseLabel.text('');
      });
  }

  $('#addProductBomLine').on('click', function() {
    buildProductBomLine({});
  });

  $(document).on('change', '.product-bom-component-type', function() {
    const row = $(this).closest('tr');

    row.find('.product-bom-unit-select')
      .prop('disabled', true)
      .html('<option value="">Select component first</option>');
    row.find('.product-bom-base-unit').text('');
    initializeComponentSelect(row, {});
  });

  $(document).on('change', '.product-bom-component-select', function() {
    const row = $(this).closest('tr');
    const componentId = $(this).val();
    const type = row.find('.product-bom-component-type').val();

    if (componentId) {
      loadProductBomComponentUnits(row, type, componentId, null);
    }
  });

  $(document).on('click', '.remove-product-bom-line', function() {
    $(this).closest('tr').remove();
    toggleProductBomEmptyState();
  });

  initialProductBomLines.forEach(buildProductBomLine);

  if (initialProductBomLines.length === 0) {
    buildProductBomLine({});
  }
</script>
@endsection
