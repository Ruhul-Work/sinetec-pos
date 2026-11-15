@extends('backend.layouts.master')

@section('meta')
  <title>Finished Product BOM {{ $productBom->version }}</title>
@endsection

@section('content')
  @php
    $statusClass = match ($productBom->status) {
        'ACTIVE' => 'bg-dark-success-gradient',
        'INACTIVE' => 'bg-dark-secondary-gradient',
        default => 'bg-dark-warning-gradient',
    };
    $canExtendValidity = $productBom->status !== 'DRAFT'
        && $productBom->effective_to !== null;
    $outputQuantity = rtrim(rtrim(
        number_format((float) $productBom->output_quantity, 6, '.', ''),
        '0'
    ), '.');
  @endphp

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">
          {{ $productBom->finishedProduct?->name }} — {{ $productBom->version }}
        </h6>
        <span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">
          {{ ucfirst(strtolower($productBom->status)) }}
        </span>
        @if ($productBom->isExpired())
          <span class="badge text-sm fw-semibold bg-dark-danger-gradient text-white px-12 py-7 radius-4">
            Expired
          </span>
        @elseif ($productBom->isScheduled())
          <span class="badge text-sm fw-semibold bg-dark-info-gradient text-white px-12 py-7 radius-4">
            Scheduled
          </span>
        @endif
      </div>
      <p class="text-muted m-0">
        {{ $productBom->finishedProduct?->product_code }} · Output:
        {{ $outputQuantity }} {{ $productBom->finishedProduct?->baseUnit?->code }}
      </p>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a
        href="{{ route('production.product-boms.index') }}"
        class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"
      >
        <iconify-icon icon="mdi:arrow-left"></iconify-icon>
        <span>Back</span>
      </a>

      @if ($canExtendValidity)
        @perm('production.product-boms.extend-validity', 'edit')
          <button
            type="button"
            class="btn btn-outline-info btn-sm px-14 py-8 radius-8 app-action-button AjaxModal"
            data-ajax-modal="{{ route('production.product-boms.validityModal', $productBom) }}"
            data-size="md"
            data-onsuccess="ProductBomShow.onValiditySaved"
          >
            <iconify-icon icon="mdi:calendar-refresh-outline"></iconify-icon>
            <span>Extend Validity</span>
          </button>
        @endperm
      @endif

      @if ($productBom->status === 'DRAFT')
        @perm('production.product-boms.edit', 'edit')
          <a
            href="{{ route('production.product-boms.edit', $productBom) }}"
            class="btn btn-outline-success btn-sm px-14 py-8 radius-8 app-action-button"
          >
            <iconify-icon icon="lucide:edit"></iconify-icon>
            <span>Edit Draft</span>
          </a>
        @endperm
        @perm('production.product-boms.activate', 'edit')
          <form
            method="POST"
            action="{{ route('production.product-boms.activate', $productBom) }}"
            class="d-inline product-bom-activate-form"
          >
            @csrf
            @method('PATCH')
            <button
              class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button app-primary-action"
              type="submit"
            >
              <iconify-icon icon="mdi:check-circle-outline"></iconify-icon>
              <span>Activate BOM</span>
            </button>
          </form>
        @endperm
      @elseif ($productBom->status === 'ACTIVE')
        @perm('production.product-boms.deactivate', 'edit')
          <form
            method="POST"
            action="{{ route('production.product-boms.deactivate', $productBom) }}"
            class="d-inline"
          >
            @csrf
            @method('PATCH')
            <button
              class="btn btn-outline-warning btn-sm px-14 py-8 radius-8 app-action-button"
              type="submit"
            >
              <iconify-icon icon="mdi:pause-circle-outline"></iconify-icon>
              <span>Deactivate</span>
            </button>
          </form>
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

  <div class="card mb-24">
    <div class="card-header">
      <h5 class="card-title mb-0">Recipe Details</h5>
    </div>
    <div class="card-body">
      <div class="row g-3 text-sm">
        <div class="col-md-3">
          <span class="text-muted d-block">Finished Product</span>
          <strong>{{ $productBom->finishedProduct?->name }}</strong>
        </div>
        <div class="col-md-2">
          <span class="text-muted d-block">Version</span>
          <code>{{ $productBom->version }}</code>
        </div>
        <div class="col-md-2">
          <span class="text-muted d-block">Output Quantity</span>
          <strong>
            {{ $outputQuantity }} {{ $productBom->finishedProduct?->baseUnit?->code }}
          </strong>
        </div>
        <div class="col-md-3">
          <span class="text-muted d-block">Effective Dates</span>
          {{ $productBom->effective_from?->format('d M Y') ?? '—' }}
          to
          {{ $productBom->effective_to?->format('d M Y') ?? 'No expiry' }}
          @if ($productBom->validity_updated_at)
            <small class="text-muted d-block mt-1">
              Extended {{ $productBom->validity_updated_at->format('d M Y, h:i A') }}
              @if ($productBom->validityUpdater)
                by {{ $productBom->validityUpdater->name }}
              @endif
            </small>
          @endif
        </div>
        <div class="col-md-2">
          <span class="text-muted d-block">Created By</span>
          {{ $productBom->creator?->name ?? 'System' }}
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex align-items-center gap-2">
      <h5 class="card-title mb-0">Recipe Components</h5>
      <x-backend.field-note label="Component quantity information">
        Base quantity is the normalized stock quantity. Wastage is applied later when an Assembly Batch loads
        this recipe.
      </x-backend.field-note>
    </div>
    <div class="card-body table-responsive">
      <table class="table bordered-table mb-0">
        <thead>
          <tr>
            <th>Type</th>
            <th>Component</th>
            <th>Selected Unit</th>
            <th class="text-end">Required Qty</th>
            <th class="text-end">Base Qty</th>
            <th class="text-end">Wastage %</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($productBom->items as $item)
            @php
              $isRawMaterial = $item->raw_material_id !== null;
              $component = $isRawMaterial ? $item->rawMaterial : $item->part;
              $componentCode = $isRawMaterial
                  ? $component?->material_code
                  : $component?->part_code;
              $typeLabel = $isRawMaterial ? 'Raw Material' : 'Part';
              $typeClass = $isRawMaterial
                  ? 'bg-info-focus text-info-main'
                  : 'bg-warning-focus text-warning-main';
            @endphp
            <tr>
              <td>
                <span class="badge {{ $typeClass }} px-10 py-6 radius-6">
                  {{ $typeLabel }}
                </span>
              </td>
              <td>
                <strong>{{ $component?->name }}</strong><br>
                <code>{{ $componentCode }}</code>
              </td>
              <td>
                {{ $item->unit?->name }}
                <small class="text-muted">({{ $item->unit?->code }})</small>
              </td>
              <td class="text-end">
                {{ rtrim(rtrim(number_format((float) $item->required_quantity, 6, '.', ''), '0'), '.') }}
              </td>
              <td class="text-end">
                {{ rtrim(rtrim(number_format((float) $item->base_quantity, 6, '.', ''), '0'), '.') }}
                {{ $component?->baseUnit?->code }}
              </td>
              <td class="text-end">
                {{ rtrim(rtrim(number_format((float) $item->wastage_percent, 4, '.', ''), '0'), '.') }}%
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  window.ProductBomShow = {
    onValiditySaved: function() {
      window.location.reload();
    },
  };

  $(document).on('submit', '.product-bom-activate-form', function(event) {
    event.preventDefault();
    const form = this;

    Swal.fire({
      icon: 'warning',
      title: 'Activate this BOM?',
      text: 'Any active BOM for this Finished Product will become inactive. Assembly will use this version.',
      showCancelButton: true,
      confirmButtonText: 'Activate',
    }).then(function(result) {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
</script>
@endsection
