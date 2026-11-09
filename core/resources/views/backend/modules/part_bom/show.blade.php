@extends('backend.layouts.master')

@section('meta')
  <title>Part BOM {{ $partBom->version }}</title>
@endsection

@section('content')
  @php
    $statusClass = match($partBom->status) {
      'ACTIVE' => 'bg-dark-success-gradient',
      'INACTIVE' => 'bg-dark-secondary-gradient',
      default => 'bg-dark-warning-gradient',
    };
  @endphp

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <h6 class="fw-semibold mb-0">{{ $partBom->part?->name }} — {{ $partBom->version }}</h6>
        <span class="badge text-sm fw-semibold {{ $statusClass }} px-12 py-7 radius-4 text-white">{{ ucfirst(strtolower($partBom->status)) }}</span>
      </div>
      <p class="text-muted m-0">{{ $partBom->part?->part_code }} · Output: {{ rtrim(rtrim(number_format((float) $partBom->output_quantity, 6, '.', ''), '0'), '.') }} {{ $partBom->part?->baseUnit?->code }}</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a href="{{ route('production.part-boms.index') }}" class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="mdi:arrow-left"></iconify-icon><span>Back</span></a>
      @if($partBom->status === 'DRAFT')
        @perm('production.part-boms.edit')<a href="{{ route('production.part-boms.edit', $partBom) }}" class="btn btn-outline-success btn-sm px-14 py-8 radius-8 app-action-button"><iconify-icon icon="lucide:edit"></iconify-icon><span>Edit Draft</span></a>@endperm
        @perm('production.part-boms.activate')
          <form method="POST" action="{{ route('production.part-boms.activate', $partBom) }}" class="d-inline bom-activate-form">@csrf @method('PATCH')<button class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button app-primary-action" type="submit"><iconify-icon icon="mdi:check-circle-outline"></iconify-icon><span>Activate BOM</span></button></form>
        @endperm
      @elseif($partBom->status === 'ACTIVE')
        @perm('production.part-boms.deactivate')
          <form method="POST" action="{{ route('production.part-boms.deactivate', $partBom) }}" class="d-inline">@csrf @method('PATCH')<button class="btn btn-outline-warning btn-sm px-14 py-8 radius-8 app-action-button" type="submit"><iconify-icon icon="mdi:pause-circle-outline"></iconify-icon><span>Deactivate</span></button></form>
        @endperm
      @endif
    </div>
  </div>

  @if(session('success'))<div class="alert alert-success d-flex align-items-center gap-2"><iconify-icon icon="mdi:check-circle-outline" class="text-xl"></iconify-icon>{{ session('success') }}</div>@endif

  <div class="card mb-24">
    <div class="card-header"><h5 class="card-title mb-0">Recipe Details</h5></div>
    <div class="card-body">
      <div class="row g-3 text-sm">
        <div class="col-md-3"><span class="text-muted d-block">Part</span><strong>{{ $partBom->part?->name }}</strong></div>
        <div class="col-md-2"><span class="text-muted d-block">Version</span><code>{{ $partBom->version }}</code></div>
        <div class="col-md-2"><span class="text-muted d-block">Output Quantity</span><strong>{{ rtrim(rtrim(number_format((float) $partBom->output_quantity, 6, '.', ''), '0'), '.') }} {{ $partBom->part?->baseUnit?->code }}</strong></div>
        <div class="col-md-3"><span class="text-muted d-block">Effective Dates</span>{{ $partBom->effective_from?->format('d M Y') ?? '—' }} to {{ $partBom->effective_to?->format('d M Y') ?? '—' }}</div>
        <div class="col-md-2"><span class="text-muted d-block">Created By</span>{{ $partBom->creator?->name ?? 'System' }}</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h5 class="card-title mb-0">Raw Material Components</h5></div>
    <div class="card-body table-responsive">
      <table class="table bordered-table mb-0">
        <thead><tr><th>Raw Material</th><th>Selected Unit</th><th class="text-end">Required Qty</th><th class="text-end">Base Qty</th><th class="text-end">Wastage %</th></tr></thead>
        <tbody>
          @foreach($partBom->items as $item)
            <tr>
              <td><strong>{{ $item->rawMaterial?->name }}</strong><br><code>{{ $item->rawMaterial?->material_code }}</code></td>
              <td>{{ $item->unit?->name }} <small class="text-muted">({{ $item->unit?->code }})</small></td>
              <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->required_quantity, 6, '.', ''), '0'), '.') }}</td>
              <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->base_quantity, 6, '.', ''), '0'), '.') }} {{ $item->rawMaterial?->baseUnit?->code }}</td>
              <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->wastage_percent, 4, '.', ''), '0'), '.') }}%</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  $(document).on('submit', '.bom-activate-form', function(event) {
    if (!window.Swal) return;
    event.preventDefault();
    const form = this;
    Swal.fire({ icon: 'warning', title: 'Activate this BOM?', text: 'Any currently active BOM for the same Part will become inactive. Production will later use this version.', showCancelButton: true, confirmButtonText: 'Activate' })
      .then(result => { if (result.isConfirmed) form.submit(); });
  });
</script>
@endsection
