@extends('backend.layouts.master')

@php
  use App\Enums\Production\ProductAssemblyStatus;
  $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
  $completed = $batch->status === ProductAssemblyStatus::Completed;
@endphp

@section('meta')<title>{{ $batch->batch_no }}</title>@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">Final Product Assembly {{ $batch->batch_no }}</h6>
        <span
          class="badge text-sm {{ $completed ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient' }}
            px-12 py-7 radius-4 text-white"
        >
          {{ $batch->status->label() }}
        </span>
        <span class="badge bg-info-focus text-info-main px-10 py-6 radius-6">QC: {{ $batch->qc_status->label() }}</span>
      </div>
      <p class="text-muted m-0">{{ $batch->bom?->finishedProduct?->name }} · {{ $batch->bom?->version }} · {{ $batch->assembly_date->format('d M Y') }}</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a
        href="{{ route('production.product-assemblies.index') }}"
        class="btn btn-outline-secondary btn-sm app-action-button"
      >
        <iconify-icon icon="mdi:arrow-left"></iconify-icon>
        <span>Back</span>
      </a>
      @if (! $completed)
        @perm('production.product-assemblies.edit', 'edit')
          <a
            href="{{ route('production.product-assemblies.edit', $batch) }}"
            class="btn btn-outline-primary btn-sm app-action-button"
          >
            <iconify-icon icon="mdi:pencil-outline"></iconify-icon>
            <span>Edit Draft</span>
          </a>
        @endperm
        @perm('production.product-assemblies.complete', 'edit')
          <form method="POST" action="{{ route('production.product-assemblies.complete', $batch) }}" class="d-flex js-swal-confirm"
            data-confirm-title="Complete this Final Product Assembly?"
            data-confirm-text="Raw Material and Part stock will be deducted; good Finished Product stock will be received. No Finance journal will be created."
            data-confirm-button="Yes, complete assembly" data-confirm-color="#0f766e">
            @csrf
            @method('PATCH')
            <button class="btn btn-primary btn-sm app-action-button app-primary-action">
              <iconify-icon icon="mdi:package-variant-closed-check"></iconify-icon>
              <span>Complete Assembly</span>
            </button>
          </form>
        @endperm
      @endif
    </div>
  </div>

  @if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif
  @if (session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
  @endif

  <div class="card mb-24">
    <div class="card-header"><h5 class="card-title mb-0">Assembly Information</h5></div>
    <div class="card-body"><div class="row g-3 text-sm">
      <div class="col-md-3">
        <span class="text-muted d-block">Output Product</span>
        <strong>{{ $batch->bom?->finishedProduct?->name }}</strong><br>
        <code>{{ $batch->bom?->finishedProduct?->product_code }}</code>
      </div>
      <div class="col-md-2"><span class="text-muted d-block">Recipe Version</span><strong>{{ $batch->bom?->version }}</strong></div>
      <div class="col-md-2"><span class="text-muted d-block">Branch</span><strong>{{ $batch->branch?->name }}</strong></div>
      <div class="col-md-2"><span class="text-muted d-block">Assembly Date</span><strong>{{ $batch->assembly_date->format('d M Y') }}</strong></div>
      <div class="col-md-3"><span class="text-muted d-block">Created By</span><strong>{{ $batch->creator?->name ?? 'System' }}</strong></div>
      <div class="col-md-4">
        <span class="text-muted d-block">Component Warehouse</span>
        <strong>{{ $batch->inputWarehouse?->name }}</strong>
        <code>{{ $batch->inputWarehouse?->code }}</code>
      </div>
      <div class="col-md-4">
        <span class="text-muted d-block">Finished Product Warehouse</span>
        <strong>{{ $batch->outputWarehouse?->name }}</strong>
        <code>{{ $batch->outputWarehouse?->code }}</code>
      </div>
      @if ($batch->completed_at)
        <div class="col-md-4">
          <span class="text-muted d-block">Completed</span>
          <strong>{{ $batch->completed_at->format('d M Y, h:i A') }}</strong><br>
          {{ $batch->completedBy?->name ?? 'System' }}
        </div>
      @endif
    </div></div>
  </div>

  <div class="row g-3 mb-24">
    @foreach ([
      ['Planned Output', $batch->planned_quantity, 'bg-primary-focus'],
      ['Good Output', $batch->good_quantity, 'bg-success-focus'],
      ['Rejected Output', $batch->reject_quantity, 'bg-danger-focus'],
    ] as [$label, $value, $class])
      <div class="col-md-4">
        <div class="card h-100 border-0 {{ $class }}">
          <div class="card-body py-16">
            <span class="text-muted d-block text-sm">{{ $label }}</span>
            <strong class="text-xl">
              {{ $quantity($value) }} {{ $batch->bom?->finishedProduct?->baseUnit?->code }}
            </strong>
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <div class="card mb-24">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
      <h5 class="card-title mb-0">Component Consumption</h5>
      @if ($costPreview['is_estimate'])
        <span class="badge bg-info-focus text-info-main">Estimated from latest warehouse IN cost</span>
      @endif
    </div>
    <div class="card-body"><div class="table-responsive"><table class="table bordered-table align-middle mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>Type</th>
          <th>Component</th>
          <th>Unit</th>
          <th class="text-end">Actual Qty</th>
          <th class="text-end">Base Qty</th>
          <th class="text-end">Unit Cost</th>
          <th class="text-end">Total Cost</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($batch->consumptions as $index => $line)
          @php $component = $line->component(); $lineCost = $costPreview['lines'][$line->id]; @endphp
          <tr>
            <td>{{ $index + 1 }}</td>
            <td>
              <span class="badge bg-info-focus text-info-main">
                {{ $line->componentType() === 'PART' ? 'Part' : 'Raw Material' }}
              </span>
            </td>
            <td>
              <strong>{{ $component?->name }}</strong><br>
              <code>
                {{ $line->componentType() === 'PART' ? $component?->part_code : $component?->material_code }}
              </code>
            </td>
            <td>{{ $line->unit?->name }} ({{ $line->unit?->code }})</td><td class="text-end">{{ $quantity($line->quantity) }}</td>
            <td class="text-end fw-semibold">{{ $quantity($line->base_quantity) }} {{ $component?->baseUnit?->code }}</td>
            <td class="text-end">{{ number_format((float) $lineCost['unit_cost'], 4) }}</td>
            <td class="text-end fw-semibold">{{ number_format((float) $lineCost['total_cost'], 2) }}</td>
          </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <th colspan="7" class="text-end">
            {{ $costPreview['is_estimate'] ? 'Estimated Component Cost' : 'Component Cost' }}
          </th>
          <th class="text-end">{{ number_format((float) $costPreview['component_cost'], 2) }}</th>
        </tr>
      </tfoot>
    </table></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="card-title mb-0">Recorded Assembly Costs</h5>
          <small class="text-muted">Report-only; no Finance transaction.</small>
        </div>
        <div class="card-body">
          <table class="table bordered-table mb-0">
            <thead>
              <tr>
                <th>Category</th>
                <th>Note</th>
                <th class="text-end">Amount</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($batch->expenses as $expense)
                <tr>
                  <td>{{ $expense->category?->name }}</td>
                  <td>{{ $expense->note ?: '—' }}</td>
                  <td class="text-end">{{ number_format((float) $expense->amount, 2) }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="3" class="text-center text-muted">No assembly cost recorded.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="card-title mb-0">QC and Cost Summary</h5>
        </div>
        <div class="card-body">
          <div class="d-flex justify-content-between py-2 border-bottom">
            <span>Component Cost</span>
            <strong>{{ number_format((float) $costPreview['component_cost'], 2) }}</strong>
          </div>
          <div class="d-flex justify-content-between py-2 border-bottom">
            <span>Recorded Other Cost</span>
            <strong>{{ number_format((float) $costPreview['expense_cost'], 2) }}</strong>
          </div>
          <div class="d-flex justify-content-between py-3">
            <span class="fw-semibold">Estimated Batch Cost</span>
            <strong class="text-primary-600 text-lg">
              {{ number_format((float) $costPreview['total_cost'], 2) }}
            </strong>
          </div>
          <div class="production-output-unit-cost p-16 radius-8 d-flex justify-content-between">
            <span>Good Output Unit Cost</span>
            <strong>
              {{ $costPreview['good_output_unit_cost'] === null
                  ? 'Pending QC'
                  : number_format((float) $costPreview['good_output_unit_cost'], 4) }}
            </strong>
          </div>
          @if ($batch->qc_note)
            <div class="alert alert-light border mt-3 mb-0">
              <small class="text-muted d-block">QC Note</small>
              {{ $batch->qc_note }}
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>
@endsection
