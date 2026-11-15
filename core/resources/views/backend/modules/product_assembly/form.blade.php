@extends('backend.layouts.master')

@php
  $editing = $method === 'PUT';
  $compact = fn ($value) => rtrim(rtrim(number_format((float) ($value ?? 0), 6, '.', ''), '0'), '.') ?: '0';
  $initialConsumptions = old('consumptions');
  if ($initialConsumptions === null && $batch->relationLoaded('consumptions')) {
      $initialConsumptions = $batch->consumptions->map(function ($line) {
          $type = $line->raw_material_id !== null ? 'RAW_MATERIAL' : 'PART';
          $component = $type === 'RAW_MATERIAL' ? $line->rawMaterial : $line->part;
          return [
              'component_type' => $type,
              'raw_material_id' => $line->raw_material_id,
              'part_id' => $line->part_id,
              'component_name' => $component?->name,
              'component_code' => $type === 'RAW_MATERIAL' ? $component?->material_code : $component?->part_code,
              'unit_id' => $line->unit_id,
              'unit_name' => $line->unit?->name,
              'unit_code' => $line->unit?->code,
              'base_unit_code' => $component?->baseUnit?->code,
              'quantity' => $line->quantity,
              'base_quantity' => $line->base_quantity,
              'available_quantity' => null,
          ];
      })->values()->all();
  }
  $initialConsumptions ??= [];
  $initialExpenses = old('expenses') ?? ($batch->relationLoaded('expenses')
      ? $batch->expenses->map(fn ($line) => [
          'production_expense_category_id' => $line->production_expense_category_id,
          'amount' => $line->amount,
          'note' => $line->note,
      ])->values()->all()
      : []);
@endphp

@section('meta')<title>{{ $title }}</title>@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $title }}</h6>
        <x-backend.page-note title="How Final Assembly works" label="View assembly rules" align="start">
          Load an active Finished Product Recipe. Draft changes no stock. Completion consumes each Raw Material and Part,
          receives only good Finished Product quantity, and creates no Finance journal.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Record actual component use, optional report-only costs and QC output.</p>
    </div>
    <a href="{{ route('production.product-assemblies.index') }}" class="btn btn-outline-secondary btn-sm app-action-button">
      <iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back to list</span>
    </a>
  </div>

  @if ($errors->any())
    <div class="alert alert-danger">
      <ul class="mb-0">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif
  @if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
  @endif

  <form method="POST" action="{{ $action }}" id="productAssemblyForm">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="card mb-24">
      <div class="card-header"><h5 class="card-title mb-0">Assembly Information</h5></div>
      <div class="card-body"><div class="row g-3">
        <div class="col-lg-5">
          <label class="form-label">Active Finished Product Recipe <span class="text-danger">*</span></label>
          <select name="product_bom_id" id="assemblyBom" class="form-select form-select-sm js-s2" required>
            <option value="">Select active Product Recipe</option>
            @foreach ($boms as $bom)
              <option value="{{ $bom->id }}" @selected((int) old('product_bom_id', $batch->product_bom_id) === $bom->id)>
                {{ $bom->finishedProduct?->name }} ({{ $bom->finishedProduct?->product_code }}) — {{ $bom->version }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="col-lg-3">
          <label class="form-label">Assembly Date <span class="text-danger">*</span></label>
          <input
            type="date"
            name="assembly_date"
            class="form-control form-control-sm"
            value="{{ old('assembly_date', $batch->assembly_date?->format('Y-m-d')) }}"
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
            value="{{ old('planned_quantity', $compact($batch->planned_quantity ?: 1)) }}"
            min="0.000001"
            step="0.000001"
            required
          >
        </div>
        <div class="col-lg-2 d-flex align-items-end">
          <button type="button" id="loadBomPlan" class="btn btn-outline-primary btn-sm w-100 app-action-button justify-content-center">
            <iconify-icon icon="mdi:reload"></iconify-icon><span>Load Recipe</span>
          </button>
        </div>
        <div class="col-lg-6">
          <label class="form-label">Component Warehouse <span class="text-danger">*</span></label>
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
          <label class="form-label">Finished Product Warehouse <span class="text-danger">*</span></label>
          <select name="output_warehouse_id" id="outputWarehouse" class="form-select form-select-sm js-s2" required>
            <option value="">Select output warehouse</option>
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
      </div></div>
    </div>

    <div class="card mb-24">
      <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <div>
          <h5 class="card-title mb-0">Actual Component Consumption</h5>
          <small class="text-muted">Raw Material and Part quantities are scaled from the selected Recipe.</small>
        </div>
        <span id="assemblyProductLabel" class="badge bg-primary-focus text-primary-600 px-12 py-7 radius-6">Select a Recipe</span>
      </div>
      <div class="card-body"><div class="table-responsive">
        <table class="table bordered-table align-middle mb-0"><thead><tr>
          <th>Component</th>
          <th>Assembly Unit</th>
          <th class="text-end">Actual Qty</th>
          <th class="text-end">Base Qty</th>
          <th class="text-end">Available</th>
        </tr>
        </thead>
        <tbody id="assemblyConsumptions"></tbody>
        </table>
      </div>
      <div id="emptyConsumptions" class="text-center text-muted py-32">
        Select an active Recipe and click Load Recipe.
      </div>
      </div>
    </div>

    <div class="card mb-24">
      <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <div>
          <h5 class="card-title mb-0">Recorded Assembly Costs</h5>
          <small class="text-muted">Optional report-only cost. It creates no account transaction.</small>
        </div>
        <button type="button" id="addExpense" class="btn btn-outline-primary btn-sm app-action-button">
          <iconify-icon icon="mdi:plus"></iconify-icon>
          <span>Add Cost</span>
        </button>
      </div>
      <div class="card-body">
        <div id="expenseHeader" class="row g-2 fw-semibold text-sm mb-2">
          <div class="col-lg-5">Category</div>
          <div class="col-lg-2 text-end">Amount</div>
          <div class="col-lg-4">Note</div>
          <div class="col-lg-1 text-center">Action</div>
        </div>
        <div id="assemblyExpenses" class="d-grid gap-2"></div>
        <div id="emptyExpenses" class="text-center text-muted py-20">No cost added.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h5 class="card-title mb-0">Basic QC and Output</h5></div>
      <div class="card-body"><div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">QC Status <span class="text-danger">*</span></label>
          <select name="qc_status" class="form-select form-select-sm js-s2" required>
            @foreach ($qcStatuses as $qc)
              <option
                value="{{ $qc->value }}"
                @selected(old('qc_status', $batch->qc_status?->value ?? 'PENDING') === $qc->value)
              >
                {{ $qc->label() }}
              </option>
            @endforeach
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Good Quantity <span class="text-danger">*</span></label>
          <input
            type="number"
            name="good_quantity"
            class="form-control form-control-sm text-end"
            value="{{ old('good_quantity', $compact($batch->good_quantity)) }}"
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
            value="{{ old('reject_quantity', $compact($batch->reject_quantity)) }}"
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
          >
        </div>
      </div></div>
      <div class="card-footer d-flex justify-content-center gap-3">
        <a href="{{ route('production.product-assemblies.index') }}" class="btn border border-danger-600 text-danger-600 px-40 app-action-button">Cancel</a>
        <button class="btn btn-primary px-48 app-action-button app-primary-action">
          <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
          <span>{{ $editing ? 'Update Draft' : 'Save Draft' }}</span>
        </button>
      </div>
    </div>
  </form>
@endsection

@section('script')
<script>
  const initialConsumptions = @json($initialConsumptions);
  const initialExpenses = @json($initialExpenses);
  const bomPlanUrl = @json(route('production.product-assemblies.bom-plan', ['productBom' => '__BOM__']));
  const expenseCategories = @json(
    $expenseCategories->map(fn ($category) => [
      'id' => $category->id,
      'text' => $category->name.' ('.$category->code.')',
    ])->values()
  );
  let expenseIndex = 0;

  S2.auto($('#productAssemblyForm'));
  const escapeHtml = value => $('<div>').text(value ?? '').html();
  const quantity = value => (Number(value || 0).toFixed(6).replace(/\.?0+$/, '') || '0');

  function renderConsumptions(lines) {
    const body = $('#assemblyConsumptions').empty();
    lines.forEach(function(line, index) {
      const typeLabel = line.component_type === 'PART' ? 'Part' : 'Raw Material';
      const available = line.available_quantity == null ? 'Refresh Recipe to check' : `${quantity(line.available_quantity)} ${escapeHtml(line.base_unit_code)}`;
      body.append(`<tr>
        <td>
          <span class="badge bg-info-focus text-info-main mb-1">${typeLabel}</span><br>
          <strong>${escapeHtml(line.component_name)}</strong>
          <code>${escapeHtml(line.component_code)}</code>
          <input type="hidden" name="consumptions[${index}][component_type]" value="${line.component_type}">
          <input type="hidden" name="consumptions[${index}][raw_material_id]" value="${line.raw_material_id || ''}">
          <input type="hidden" name="consumptions[${index}][part_id]" value="${line.part_id || ''}"></td>
        <td>
          ${escapeHtml(line.unit_name)} (${escapeHtml(line.unit_code)})
          <input type="hidden" name="consumptions[${index}][unit_id]" value="${line.unit_id}">
        </td>
        <td>
          <input
            type="number"
            name="consumptions[${index}][quantity]"
            class="form-control form-control-sm text-end"
            value="${quantity(line.quantity)}"
            min="0.000001"
            step="0.000001"
            required
          >
        </td>
        <td class="text-end fw-semibold">${quantity(line.base_quantity)} ${escapeHtml(line.base_unit_code)}</td>
        <td class="text-end">${available}</td></tr>`);
    });
    $('#emptyConsumptions').toggle(lines.length === 0);
  }

  function loadPlan() {
    const bomId = $('#assemblyBom').val();
    if (!bomId || Number($('#plannedQuantity').val()) <= 0) return;
    $.get(bomPlanUrl.replace('__BOM__', bomId), {
      planned_quantity: $('#plannedQuantity').val(), warehouse_id: $('#inputWarehouse').val() || null,
    }).done(function(response) {
      renderConsumptions(response.consumptions || []);
      $('#assemblyProductLabel').text(`${response.bom.product_name} · ${response.bom.version}`);
    });
  }

  function toggleExpenses() {
    const empty = $('#assemblyExpenses .assembly-expense-row').length === 0;
    $('#emptyExpenses').toggle(empty); $('#expenseHeader').toggle(!empty);
  }

  function addExpense(expense = {}) {
    const index = expenseIndex++;
    const options = ['<option value="">Select category</option>'].concat(
      expenseCategories.map(category => {
        const selected = Number(expense.production_expense_category_id) === Number(category.id)
          ? 'selected'
          : '';

        return `<option value="${category.id}" ${selected}>${escapeHtml(category.text)}</option>`;
      })
    );
    const row = $(`<div class="row g-2 align-items-center assembly-expense-row">
      <div class="col-lg-5">
        <select
          name="expenses[${index}][production_expense_category_id]"
          class="form-select form-select-sm js-s2"
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
        >
      </div>
      <div class="col-lg-4">
        <input
          type="text"
          name="expenses[${index}][note]"
          class="form-control form-control-sm"
          value="${escapeHtml(expense.note || '')}"
          maxlength="1000"
        >
      </div>
      <div class="col-lg-1 text-center">
        <button
          type="button"
          class="btn p-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main remove-expense"
        >
          <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
        </button>
      </div>
    </div>`);
    $('#assemblyExpenses').append(row); S2.auto(row); toggleExpenses();
  }

  $('#loadBomPlan').on('click', loadPlan);
  $('#assemblyBom').on('change', loadPlan);
  $('#inputWarehouse').on('change', function() {
    const branch = $(this).find(':selected').data('branch-id');
    $('#outputWarehouse option').each(function() {
      const optionBranch = $(this).data('branch-id');
      $(this).prop('disabled', optionBranch && Number(optionBranch) !== Number(branch));
    });
  });
  $('#addExpense').on('click', () => addExpense());
  $(document).on('click', '.remove-expense', function() { $(this).closest('.assembly-expense-row').remove(); toggleExpenses(); });
  renderConsumptions(initialConsumptions); initialExpenses.forEach(addExpense); toggleExpenses(); $('#inputWarehouse').trigger('change');
</script>
@endsection
