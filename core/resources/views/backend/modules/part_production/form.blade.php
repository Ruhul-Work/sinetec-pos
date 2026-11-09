@extends('backend.layouts.master')

@php
  $editing = $method === 'PUT';

  $initialConsumptions = old('consumptions');
  if ($initialConsumptions === null && $batch->relationLoaded('consumptions')) {
      $initialConsumptions = $batch->consumptions->map(fn ($line) => [
          'raw_material_id' => $line->raw_material_id,
          'raw_material_name' => $line->rawMaterial?->name,
          'raw_material_code' => $line->rawMaterial?->material_code,
          'unit_id' => $line->unit_id,
          'unit_name' => $line->unit?->name,
          'unit_code' => $line->unit?->code,
          'base_unit_code' => $line->rawMaterial?->baseUnit?->code,
          'quantity' => $line->quantity,
          'base_quantity' => $line->base_quantity,
          'available_quantity' => null,
          'wastage_percent' => null,
      ])->values()->all();
  }
  $initialConsumptions ??= [];

  $initialExpenses = old('expenses');
  if ($initialExpenses === null && $batch->relationLoaded('expenses')) {
      $initialExpenses = $batch->expenses->map(fn ($line) => [
          'production_expense_category_id' => $line->production_expense_category_id,
          'amount' => $line->amount,
          'note' => $line->note,
      ])->values()->all();
  }
  $initialExpenses ??= [];

  $compactQuantity = function ($value): string {
      $formatted = number_format((float) ($value ?? 0), 6, '.', '');

      return rtrim(rtrim($formatted, '0'), '.') ?: '0';
  };
@endphp

@section('meta')
  <title>{{ $title }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $title }}</h6>
        <x-backend.page-note title="How Part Production works" label="View production rules" align="start">
          Select an active Part BOM and planned output. The system scales its raw-material recipe.
          Saving a Draft changes no stock. Complete Production permanently posts Raw Material OUT,
          good Part IN and a Draft finance journal.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Prepare actual consumption and basic QC before completing the batch.</p>
    </div>
    <a href="{{ route('production.part-productions.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
      <iconify-icon icon="mdi:arrow-left"></iconify-icon>
      <span>Back to list</span>
    </a>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger">
      <strong>Please correct the highlighted production information.</strong>
      <ul class="mb-0 mt-1">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if (session('error'))
    <div class="alert alert-danger d-flex align-items-center gap-2">
      <iconify-icon icon="mdi:alert-circle-outline" class="text-xl"></iconify-icon>
      {{ session('error') }}
    </div>
  @endif

  <form method="POST" action="{{ $action }}" id="partProductionForm">
    @csrf
    @if ($editing)
      @method('PUT')
    @endif

    <div class="card mb-24">
      <div class="card-header">
        <h5 class="card-title mb-0">Batch Information</h5>
      </div>
      <div class="card-body">
        <div class="row g-3 part-production-header-grid">
          <div class="col-lg-5">
            <label class="form-label">Active Part BOM <span class="text-danger">*</span></label>
            <select name="part_bom_id" id="productionBom" class="form-select form-select-sm js-s2" required>
              <option value="">Select active Part BOM</option>
              @foreach ($boms as $bom)
                <option value="{{ $bom->id }}" @selected((int) old('part_bom_id', $batch->part_bom_id) === $bom->id)>
                  {{ $bom->part?->name }} ({{ $bom->part?->part_code }}) — {{ $bom->version }}
                </option>
              @endforeach
            </select>
          </div>

          <div class="col-lg-3">
            <label class="form-label">Production Date <span class="text-danger">*</span></label>
            <input
              type="date"
              name="production_date"
              class="form-control form-control-sm"
              value="{{ old('production_date', $batch->production_date?->format('Y-m-d')) }}"
              required
            >
          </div>

          <div class="col-lg-2">
            <label class="form-label">Planned Output <span class="text-danger">*</span></label>
            <input
              type="number"
              name="planned_quantity"
              id="plannedQuantity"
              class="form-control form-control-sm text-end"
              value="{{ old('planned_quantity', $compactQuantity($batch->planned_quantity ?: 1)) }}"
              min="0.000001"
              step="0.000001"
              required
            >
          </div>

          <div class="col-lg-2 d-flex align-items-end">
            <button
              type="button"
              id="loadBomPlan"
              class="btn btn-outline-primary btn-sm w-100 px-14 py-8 radius-8
                app-action-button d-inline-flex align-items-center justify-content-center gap-2"
            >
              <iconify-icon icon="mdi:reload"></iconify-icon>
              <span>Load BOM</span>
            </button>
          </div>

          <div class="col-lg-6">
            <label class="form-label">Raw Material Warehouse <span class="text-danger">*</span></label>
            <select name="input_warehouse_id" id="inputWarehouse" class="form-select form-select-sm js-s2" required>
              <option value="">Select source warehouse</option>
              @foreach ($warehouses as $warehouse)
                <option
                  value="{{ $warehouse->id }}"
                  data-branch-id="{{ $warehouse->branch_id }}"
                  @selected((int) old('input_warehouse_id', $batch->input_warehouse_id) === $warehouse->id)
                >
                  {{ $warehouse->branch_name }} — {{ $warehouse->name }} ({{ $warehouse->code }})
                </option>
              @endforeach
            </select>
          </div>

          <div class="col-lg-6">
            <label class="form-label">Part Output Warehouse <span class="text-danger">*</span></label>
            <select name="output_warehouse_id" id="outputWarehouse" class="form-select form-select-sm js-s2" required>
              <option value="">Select destination warehouse</option>
              @foreach ($warehouses as $warehouse)
                <option
                  value="{{ $warehouse->id }}"
                  data-branch-id="{{ $warehouse->branch_id }}"
                  @selected((int) old('output_warehouse_id', $batch->output_warehouse_id) === $warehouse->id)
                >
                  {{ $warehouse->branch_name }} — {{ $warehouse->name }} ({{ $warehouse->code }})
                </option>
              @endforeach
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-24">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div>
          <h5 class="card-title mb-0">Actual Raw Material Consumption</h5>
          <p class="text-muted text-sm mb-0">BOM quantities include its expected wastage. Adjust only when actual consumption differs.</p>
        </div>
        <span id="productionPartLabel" class="badge bg-primary-focus text-primary-600 px-12 py-7 radius-6">Select a BOM</span>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table bordered-table align-middle mb-0 part-production-consumption-table">
            <thead>
              <tr>
                <th>Raw Material</th>
                <th>Production Unit</th>
                <th class="text-end">Actual Qty</th>
                <th class="text-end">Base Qty</th>
                <th class="text-end">Available Stock</th>
              </tr>
            </thead>
            <tbody id="productionConsumptions"></tbody>
          </table>
        </div>
        <div id="emptyProductionConsumptions" class="text-center text-muted py-32">
          <iconify-icon
            icon="mdi:flask-empty-outline"
            class="text-3xl part-production-empty-icon mb-2"
          ></iconify-icon>
          Select an active BOM and click Load BOM.
        </div>
      </div>
    </div>

    <div class="card mb-24">
      <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div>
          <h5 class="card-title mb-0">Direct Production Expenses</h5>
          <p class="text-muted text-sm mb-0">Optional batch costs such as labour, production power or transport.</p>
        </div>
        <button type="button" id="addProductionExpense" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button">
          <iconify-icon icon="mdi:plus"></iconify-icon>
          <span>Add Expense</span>
        </button>
      </div>
      <div class="card-body">
        <div id="productionExpenseHeader" class="row g-2 production-expense-header">
          <div class="col-lg-5">Expense Category</div>
          <div class="col-lg-2 text-lg-end">Amount</div>
          <div class="col-lg-4">Note</div>
          <div class="col-lg-1 text-center">Action</div>
        </div>
        <div id="productionExpenses" class="d-grid gap-2"></div>
        <div id="emptyProductionExpenses" class="text-center text-muted py-20">No direct expense added.</div>
        <small class="text-muted d-block mt-2">A category must have an assigned expense account before this batch can be completed.</small>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h5 class="card-title mb-0">Basic QC and Output</h5>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">QC Status <span class="text-danger">*</span></label>
            <select name="qc_status" class="form-select form-select-sm js-s2" required>
              @foreach ($qcStatuses as $qcStatus)
                <option value="{{ $qcStatus->value }}" @selected(old('qc_status', $batch->qc_status?->value ?? 'PENDING') === $qcStatus->value)>
                  {{ $qcStatus->label() }}
                </option>
              @endforeach
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Good Quantity <span class="text-danger">*</span></label>
            <input
              type="number"
              name="good_quantity"
              id="goodQuantity"
              class="form-control form-control-sm text-end"
              value="{{ old('good_quantity', $compactQuantity($batch->good_quantity ?? 0)) }}"
              min="0"
              step="0.000001"
              required
            >
          </div>
          <div class="col-md-3">
            <label class="form-label">Reject Quantity <span class="text-danger">*</span></label>
            <input
              type="number"
              name="reject_quantity"
              class="form-control form-control-sm text-end"
              value="{{ old('reject_quantity', $compactQuantity($batch->reject_quantity ?? 0)) }}"
              min="0"
              step="0.000001"
              required
            >
          </div>
          <div class="col-md-3">
            <label class="form-label">QC Note</label>
            <input
              type="text"
              name="qc_note"
              class="form-control form-control-sm"
              value="{{ old('qc_note', $batch->qc_note) }}"
              maxlength="5000"
              placeholder="Reject reason or QC observation"
            >
          </div>
        </div>
      </div>
      <div class="card-footer d-flex align-items-center justify-content-center gap-3">
        <a href="{{ route('production.part-productions.index') }}" class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8 app-action-button">
          <span>Cancel</span>
        </a>
        <button type="submit" class="btn btn-primary px-48 py-12 radius-8 app-action-button app-primary-action">
          <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
          <span>{{ $editing ? 'Update Draft' : 'Save Draft' }}</span>
        </button>
      </div>
    </div>
  </form>
@endsection

@section('script')
@php
  $expenseCategoryOptions = $expenseCategories
      ->map(function ($category) {
          return [
              'id' => $category->id,
              'text' => $category->name.' ('.$category->code.')',
              'account_configured' => (bool) $category->expense_account_id,
          ];
      })
      ->values();
@endphp
<script>
  const initialConsumptions = @json($initialConsumptions);
  const initialExpenses = @json($initialExpenses);
  const bomPlanUrl = @json(route('production.part-productions.bom-plan', ['partBom' => '__BOM__']));
  const expenseCategories = @json($expenseCategoryOptions);
  let expenseIndex = 0;
  let formIsInitializing = true;

  S2.auto($('#partProductionForm'));

  function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
  }

  function formatQuantity(value, scale = 6) {
    const number = Number(value || 0);
    return number.toFixed(scale).replace(/\.?0+$/, '') || '0';
  }

  function toggleConsumptionEmptyState() {
    $('#emptyProductionConsumptions').toggle($('#productionConsumptions tr').length === 0);
  }

  function renderConsumptions(lines) {
    const body = $('#productionConsumptions').empty();

    lines.forEach(function(line, index) {
      const available = line.available_quantity === null || line.available_quantity === undefined
        ? 'Refresh BOM to check'
        : `${formatQuantity(line.available_quantity)} ${escapeHtml(line.base_unit_code || '')}`;
      const shortage = line.available_quantity !== null
        && line.available_quantity !== undefined
        && Number(line.available_quantity) < Number(line.base_quantity || 0);

      body.append(`
        <tr>
          <td>
            <strong>${escapeHtml(line.raw_material_name || `Raw Material #${line.raw_material_id}`)}</strong><br>
            <code>${escapeHtml(line.raw_material_code || '')}</code>
            <input type="hidden" name="consumptions[${index}][raw_material_id]" value="${line.raw_material_id}">
          </td>
          <td>
            ${escapeHtml(line.unit_name || '')} <span class="text-muted">(${escapeHtml(line.unit_code || '')})</span>
            <input type="hidden" name="consumptions[${index}][unit_id]" value="${line.unit_id}">
          </td>
          <td>
            <input
              type="number"
              name="consumptions[${index}][quantity]"
              class="form-control form-control-sm text-end production-consumption-quantity"
              value="${formatQuantity(line.quantity)}"
              min="0.000001"
              step="0.000001"
              required
            >
          </td>
          <td class="text-end fw-semibold">${formatQuantity(line.base_quantity)} ${escapeHtml(line.base_unit_code || '')}</td>
          <td class="text-end ${shortage ? 'text-danger fw-semibold' : 'text-success-main'}">${available}</td>
        </tr>
      `);
    });

    toggleConsumptionEmptyState();
  }

  function loadBomPlan() {
    const bomId = $('#productionBom').val();
    const plannedQuantity = $('#plannedQuantity').val();
    const warehouseId = $('#inputWarehouse').val();

    if (!bomId || !plannedQuantity || Number(plannedQuantity) <= 0) {
      window.Swal
        ? Swal.fire({ icon: 'info', title: 'Select BOM and quantity', text: 'Choose an active BOM and enter a planned output quantity first.' })
        : alert('Choose an active BOM and enter a planned output quantity first.');
      return;
    }

    const button = $('#loadBomPlan').prop('disabled', true);

    $.get(bomPlanUrl.replace('__BOM__', bomId), {
      planned_quantity: plannedQuantity,
      warehouse_id: warehouseId || null,
    }).done(function(response) {
      renderConsumptions(response.consumptions || []);
      $('#productionPartLabel').text(`${response.bom.part_name} · ${response.bom.version}`);
    }).fail(function(xhr) {
      const message = xhr.responseJSON?.message || 'The BOM plan could not be loaded.';
      window.Swal
        ? Swal.fire({ icon: 'error', title: 'Unable to load BOM', text: message })
        : alert(message);
    }).always(function() {
      button.prop('disabled', false);
    });
  }

  function toggleExpenseEmptyState() {
    const isEmpty = $('#productionExpenses .production-expense-row').length === 0;

    $('#emptyProductionExpenses').toggle(isEmpty);
    $('#productionExpenseHeader').toggle(!isEmpty);
  }

  function addExpenseRow(expense = {}) {
    const index = expenseIndex++;
    const options = ['<option value="">Select expense category</option>'];

    expenseCategories.forEach(function(category) {
      const selected = Number(expense.production_expense_category_id) === Number(category.id)
        ? ' selected'
        : '';
      const suffix = category.account_configured ? '' : ' — account not configured';
      options.push(`<option value="${category.id}"${selected}>${escapeHtml(category.text + suffix)}</option>`);
    });

    const row = $(`
      <div class="row g-2 align-items-center production-expense-row">
        <div class="col-lg-5">
          <select
            name="expenses[${index}][production_expense_category_id]"
            class="form-select form-select-sm js-s2"
            aria-label="Expense Category"
          >${options.join('')}</select>
        </div>
        <div class="col-lg-2">
          <input
            type="number"
            name="expenses[${index}][amount]"
            class="form-control form-control-sm text-end"
            value="${escapeHtml(expense.amount || '')}"
            min="0.0001"
            step="0.0001"
            placeholder="0.00"
            aria-label="Expense Amount"
          >
        </div>
        <div class="col-lg-4">
          <input
            type="text"
            name="expenses[${index}][note]"
            class="form-control form-control-sm"
            value="${escapeHtml(expense.note || '')}"
            maxlength="1000"
            placeholder="Optional allocation note"
            aria-label="Expense Note"
          >
        </div>
        <div class="col-lg-1 text-center">
          <button
            type="button"
            class="btn w-32-px h-32-px rounded-circle d-inline-flex align-items-center
              justify-content-center bg-danger-focus text-danger-main remove-production-expense"
            title="Remove expense"
          >
            <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
          </button>
        </div>
      </div>
    `);

    $('#productionExpenses').append(row);
    S2.auto(row);
    toggleExpenseEmptyState();
  }

  $('#loadBomPlan').on('click', loadBomPlan);
  $('#productionBom').on('change', function() {
    if ($(this).val()) {
      loadBomPlan();
    }
  });
  $('#inputWarehouse').on('change', function() {
    const branchId = $(this).find(':selected').data('branch-id');
    const output = $('#outputWarehouse');

    output.find('option').each(function() {
      const optionBranchId = $(this).data('branch-id');
      $(this).prop('disabled', optionBranchId && Number(optionBranchId) !== Number(branchId));
    });

    if (output.find(':selected').prop('disabled')) {
      output.val('').trigger('change');
    }

    if (!formIsInitializing && $('#productionConsumptions tr').length > 0) {
      loadBomPlan();
    }
  });
  $('#addProductionExpense').on('click', function() {
    addExpenseRow();
  });
  $(document).on('click', '.remove-production-expense', function() {
    $(this).closest('.production-expense-row').remove();
    toggleExpenseEmptyState();
  });

  if (initialConsumptions.length > 0) {
    renderConsumptions(initialConsumptions);
    const selectedBomText = $('#productionBom option:selected').text().trim();
    $('#productionPartLabel').text(selectedBomText || 'Selected BOM');
  } else {
    toggleConsumptionEmptyState();
  }

  initialExpenses.forEach(addExpenseRow);
  toggleExpenseEmptyState();
  $('#inputWarehouse').trigger('change');
  formIsInitializing = false;
</script>
@endsection
