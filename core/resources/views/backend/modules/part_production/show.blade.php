@extends('backend.layouts.master')

@php
  use App\Enums\Production\PartProductionStatus;
  use App\Enums\Production\ProductionQcStatus;

  $statusClass = match ($batch->status) {
      PartProductionStatus::Completed => 'bg-dark-success-gradient',
      PartProductionStatus::InProgress => 'bg-dark-info-gradient',
      PartProductionStatus::Void => 'bg-dark-danger-gradient',
      PartProductionStatus::Draft => 'bg-dark-warning-gradient',
  };
  $qcClass = match ($batch->qc_status) {
      ProductionQcStatus::Passed => 'bg-success-focus text-success-main',
      ProductionQcStatus::Partial => 'bg-warning-focus text-warning-main',
      ProductionQcStatus::Failed => 'bg-danger-focus text-danger-main',
      ProductionQcStatus::Pending => 'bg-secondary-focus text-secondary-main',
  };
  $isEstimatedMaterialCost = $materialCostPreview['is_estimate'];
  $materialCostLines = $materialCostPreview['lines'];
  $materialCost = (float) $materialCostPreview['material_cost'];
  $expenseCost = (float) $materialCostPreview['expense_cost'];
  $totalCost = (float) $materialCostPreview['total_cost'];
  $goodOutputUnitCost = $materialCostPreview['good_output_unit_cost'];
  $outputUnitCode = $batch->bom?->part?->baseUnit?->code ?? 'UNIT';
  $productionCostBooked = $batch->expenses->sum(function ($expense) {
      return $expense->settlementItems
          ->filter(fn ($item) => $item->expense?->status?->value !== 'VOID')
          ->sum(fn ($item) => (float) $item->amount);
  });
  $productionCostRemaining = max($expenseCost - $productionCostBooked, 0);
@endphp

@section('meta')
  <title>{{ $batch->batch_no }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">Part Production {{ $batch->batch_no }}</h6>
        <span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">
          {{ $batch->status->label() }}
        </span>
        <span class="badge {{ $qcClass }} px-10 py-6 radius-6">
          QC: {{ $batch->qc_status->label() }}
        </span>
      </div>
      <p class="text-muted m-0">
        {{ $batch->bom?->part?->name }} · {{ $batch->bom?->version }} · {{ $batch->production_date->format('d M Y') }}
      </p>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a href="{{ route('production.part-productions.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
        <iconify-icon icon="mdi:arrow-left"></iconify-icon>
        <span>Back</span>
      </a>

      @if ($batch->status === PartProductionStatus::Draft)
        @perm('production.part-productions.edit', 'edit')
          <a href="{{ route('production.part-productions.edit', $batch) }}" class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:pencil-outline"></iconify-icon>
            <span>Edit Draft</span>
          </a>
        @endperm

        @perm('production.part-productions.complete', 'edit')
          <form
            method="POST"
            action="{{ route('production.part-productions.complete', $batch) }}"
            class="d-flex js-swal-confirm"
            data-confirm-title="Complete this production batch?"
            data-confirm-text="Raw Material stock will be deducted, good Part stock will be received
              and a Draft journal will be created. This action cannot be edited afterward."
            data-confirm-button="Yes, complete production"
            data-confirm-color="#0f766e"
          >
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button app-primary-action">
              <iconify-icon icon="mdi:factory"></iconify-icon>
              <span>Complete Production</span>
            </button>
          </form>
        @endperm
      @endif

      @if ($batch->journal)
        @perm('finance.journals.show', 'show')
          <a href="{{ route('finance.journals.show', $batch->journal) }}" class="btn btn-outline-info btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:notebook-outline"></iconify-icon>
            <span>View Draft Journal</span>
          </a>
        @endperm
      @endif
    </div>
  </div>

  @if (session('success'))
    <div class="alert alert-success d-flex align-items-center gap-2">
      <iconify-icon icon="mdi:check-circle-outline" class="text-xl"></iconify-icon>
      {{ session('success') }}
    </div>
  @endif

  @if (session('error'))
    <div class="alert alert-danger d-flex align-items-center gap-2">
      <iconify-icon icon="mdi:alert-circle-outline" class="text-xl"></iconify-icon>
      {{ session('error') }}
    </div>
  @endif

  <div class="card mb-24">
    <div class="card-header">
      <h5 class="card-title mb-0">Batch Information</h5>
    </div>
    <div class="card-body">
      <div class="row g-3 text-sm">
        <div class="col-md-3">
          <span class="text-muted d-block">Output Part</span>
          <strong>{{ $batch->bom?->part?->name }}</strong><br>
          <code>{{ $batch->bom?->part?->part_code }}</code>
        </div>
        <div class="col-md-2">
          <span class="text-muted d-block">BOM Version</span>
          <strong>{{ $batch->bom?->version }}</strong>
        </div>
        <div class="col-md-3">
          <span class="text-muted d-block">Branch</span>
          <strong>{{ $batch->branch?->name }}</strong>
        </div>
        <div class="col-md-2">
          <span class="text-muted d-block">Production Date</span>
          <strong>{{ $batch->production_date->format('d M Y') }}</strong>
        </div>
        <div class="col-md-2">
          <span class="text-muted d-block">Created By</span>
          <strong>{{ $batch->creator?->name ?? 'System' }}</strong>
        </div>
        <div class="col-md-4">
          <span class="text-muted d-block">Raw Material Warehouse</span>
          <strong>{{ $batch->inputWarehouse?->name }}</strong>
          <code>{{ $batch->inputWarehouse?->code }}</code>
        </div>
        <div class="col-md-4">
          <span class="text-muted d-block">Part Output Warehouse</span>
          <strong>{{ $batch->outputWarehouse?->name }}</strong>
          <code>{{ $batch->outputWarehouse?->code }}</code>
        </div>
        @if ($batch->completed_at)
          <div class="col-md-4">
            <span class="text-muted d-block">Completed</span>
            <strong>{{ $batch->completed_at->format('d M Y, h:i A') }}</strong><br>
            <span>{{ $batch->completedBy?->name ?? 'System' }}</span>
          </div>
        @endif
      </div>
    </div>
  </div>

  <div class="row g-3 mb-24">
    <div class="col-md-4">
      <div class="card h-100 border-0 bg-primary-focus">
        <div class="card-body py-16">
          <span class="text-muted d-block text-sm">Planned Output</span>
          <strong class="text-xl">
            {{ rtrim(rtrim(number_format((float) $batch->planned_quantity, 6, '.', ''), '0'), '.') }}
            {{ $batch->bom?->part?->baseUnit?->code }}
          </strong>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 border-0 bg-success-focus">
        <div class="card-body py-16">
          <span class="text-muted d-block text-sm">Good Output</span>
          <strong class="text-xl text-success-main">
            {{ rtrim(rtrim(number_format((float) $batch->good_quantity, 6, '.', ''), '0'), '.') }}
            {{ $batch->bom?->part?->baseUnit?->code }}
          </strong>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 border-0 bg-danger-focus">
        <div class="card-body py-16">
          <span class="text-muted d-block text-sm">Rejected Output</span>
          <strong class="text-xl text-danger-main">
            {{ rtrim(rtrim(number_format((float) $batch->reject_quantity, 6, '.', ''), '0'), '.') }}
            {{ $batch->bom?->part?->baseUnit?->code }}
          </strong>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-24">
    <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <h5 class="card-title mb-0">Raw Material Consumption</h5>
      @if ($isEstimatedMaterialCost)
        <span class="badge bg-info-focus text-info-main px-12 py-7 radius-4">
          Estimated from latest warehouse receipt cost
        </span>
      @endif
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table align-middle mb-0">
          <thead>
            <tr>
              <th>#</th>
              <th>Raw Material</th>
              <th>Entered Unit</th>
              <th class="text-end">Actual Qty</th>
              <th class="text-end">Base Qty</th>
              <th class="text-end">Cost / Base Unit</th>
              <th class="text-end">Total Cost</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($batch->consumptions as $index => $line)
              @php
                $lineCost = $materialCostLines[$line->id];
              @endphp
              <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                  <strong>{{ $line->rawMaterial?->name }}</strong><br>
                  <code>{{ $line->rawMaterial?->material_code }}</code>
                </td>
                <td>{{ $line->unit?->name }} ({{ $line->unit?->code }})</td>
                <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->quantity, 6, '.', ''), '0'), '.') }}</td>
                <td class="text-end fw-semibold">
                  {{ rtrim(rtrim(number_format((float) $line->base_quantity, 6, '.', ''), '0'), '.') }}
                  {{ $line->rawMaterial?->baseUnit?->code }}
                </td>
                <td class="text-end">{{ number_format((float) $lineCost['unit_cost'], 4) }}</td>
                <td class="text-end fw-semibold">{{ number_format((float) $lineCost['total_cost'], 2) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr>
              <th colspan="6" class="text-end">
                {{ $isEstimatedMaterialCost ? 'Estimated Material Cost' : 'Material Cost' }}
              </th>
              <th class="text-end">{{ number_format($materialCost, 2) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card h-100" id="production-cost-settlements">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
          <div>
            <h5 class="card-title mb-0">Direct Production Costs</h5>
            <small class="text-muted">Recorded at completion; payments settle the Production Expense Payable.</small>
          </div>
          @if ($batch->status === PartProductionStatus::Completed && $productionCostRemaining > 0)
            @perm('finance.expenses.create', 'add')
              <button
                type="button"
                class="btn btn-outline-primary btn-sm px-12 py-7 radius-8 AjaxModal app-action-button"
                data-ajax-modal="{{ route('finance.expenses.create', ['production_batch' => $batch->id]) }}"
                data-size="xl"
                data-onsuccess="PartProductionExpenseBooking.onSaved"
              >
                <iconify-icon icon="mdi:cash-multiple"></iconify-icon>
                <span>Settle Production Costs</span>
              </button>
            @endperm
          @endif
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table bordered-table align-middle mb-0">
              <thead>
                <tr>
                  <th>Category</th>
                  <th>Note</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Booked</th>
                  <th class="text-end">Remaining</th>
                  <th class="text-center">Status</th>
                </tr>
              </thead>
              <tbody>
                @forelse ($batch->expenses as $expense)
                  @php
                    $bookedAmount = $expense->settlementItems
                        ->filter(fn ($item) => $item->expense?->status?->value !== 'VOID')
                        ->sum(fn ($item) => (float) $item->amount);
                    $paidAmount = $expense->settlementItems
                        ->filter(fn ($item) => $item->expense?->journal?->status?->value === 'POSTED')
                        ->sum(fn ($item) => (float) $item->amount);
                    $remainingAmount = max((float) $expense->amount - $bookedAmount, 0);
                    $paymentStatus = match (true) {
                        $paidAmount >= (float) $expense->amount => 'Paid',
                        $paidAmount > 0 => 'Partially Paid',
                        $bookedAmount >= (float) $expense->amount => 'Payment Pending',
                        $bookedAmount > 0 => 'Partially Booked',
                        default => 'Unpaid',
                    };
                    $paymentStatusClass = match ($paymentStatus) {
                        'Paid' => 'bg-success-focus text-success-main',
                        'Partially Paid', 'Payment Pending', 'Partially Booked' =>
                            'bg-warning-focus text-warning-main',
                        default => 'bg-secondary-focus text-secondary-main',
                    };
                  @endphp
                  <tr>
                    <td>{{ $expense->category?->name }}</td>
                    <td>{{ $expense->note ?: '—' }}</td>
                    <td class="text-end">{{ number_format((float) $expense->amount, 2) }}</td>
                    <td class="text-end">{{ number_format($bookedAmount, 2) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($remainingAmount, 2) }}</td>
                    <td class="text-center">
                      <span class="badge {{ $paymentStatusClass }} px-10 py-6 radius-4">{{ $paymentStatus }}</span>
                    </td>
                  </tr>
                @empty
                  <tr>
                    <td colspan="6" class="text-center text-muted">No direct production cost was allocated.</td>
                  </tr>
                @endforelse
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="2" class="text-end">Direct Production Cost</th>
                  <th class="text-end">{{ number_format($expenseCost, 2) }}</th>
                  <th class="text-end">{{ number_format($productionCostBooked, 2) }}</th>
                  <th class="text-end">{{ number_format($productionCostRemaining, 2) }}</th>
                  <th></th>
                </tr>
              </tfoot>
            </table>
          </div>

          @php
            $activeSettlements = $batch->settlements
                ->filter(fn ($settlement) => $settlement->status?->value !== 'VOID');
          @endphp
          @if ($activeSettlements->isNotEmpty())
            <div class="border-top mt-16 pt-16">
              <small class="text-muted d-block mb-8">Settlement Documents</small>
              <div class="d-flex flex-wrap gap-2">
                @foreach ($activeSettlements as $settlement)
                  <a
                    href="{{ route('finance.expenses.show', $settlement) }}"
                    class="badge bg-info-focus text-info-main px-10 py-7 radius-4"
                  >
                    {{ $settlement->expense_no }} · {{ number_format((float) $settlement->amount, 2) }}
                    ({{ $settlement->status->label() }})
                  </a>
                @endforeach
              </div>
            </div>
          @endif
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header">
          <h5 class="card-title mb-0">QC and Cost Summary</h5>
        </div>
        <div class="card-body">
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted">QC Status</span>
            <strong>{{ $batch->qc_status->label() }}</strong>
          </div>
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted">
              {{ $isEstimatedMaterialCost ? 'Estimated Material Cost' : 'Material Cost' }}
            </span>
            <strong>{{ number_format($materialCost, 2) }}</strong>
          </div>
          <div class="d-flex justify-content-between gap-3 py-2 border-bottom">
            <span class="text-muted">Direct Expense</span>
            <strong>{{ number_format($expenseCost, 2) }}</strong>
          </div>
          <div class="d-flex justify-content-between gap-3 py-3">
            <span class="fw-semibold">Total Batch Cost</span>
            <strong class="text-primary-600 text-lg">{{ number_format($totalCost, 2) }}</strong>
          </div>

          <div class="production-output-unit-cost mt-2 p-16 radius-8">
            <div class="d-flex align-items-center justify-content-between gap-3">
              <div>
                <span class="text-muted text-sm d-block">
                  {{ $isEstimatedMaterialCost ? 'Estimated Good Output Unit Cost' : 'Good Output Unit Cost' }}
                </span>
                <small class="text-muted">Total batch cost ÷ good output quantity</small>
              </div>
              @if ($goodOutputUnitCost !== null)
                <strong class="text-primary-600 text-xl text-end">
                  {{ number_format((float) $goodOutputUnitCost, 4) }} / {{ $outputUnitCode }}
                </strong>
              @else
                <span class="badge bg-warning-focus text-warning-main px-12 py-7 radius-4">Pending QC output</span>
              @endif
            </div>
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

@section('script')
  <script>
    window.PartProductionExpenseBooking = {
      onSaved: function (response) {
        if (response && response.redirect_url) {
          window.location.href = response.redirect_url;
        }
      },
    };
  </script>
@endsection
