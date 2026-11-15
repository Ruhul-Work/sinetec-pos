@extends('backend.layouts.master')

@section('meta')
  <title>Final Product Assembly</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Final Product Assembly</h6>
      <p class="text-muted m-0">Consume Raw Materials and Parts from a Product Recipe, then receive good Finished Products.</p>
    </div>
  </div>

  @if (session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <div>
        <h5 class="card-title mb-0">Assembly Batch List</h5>
        <p class="text-muted text-sm mb-0">Draft has no stock effect. Completion permanently posts component OUT and Finished Product IN.</p>
      </div>
      <div class="product-assembly-toolbar d-flex align-items-center gap-2 flex-wrap">
        <select id="assemblyStatusFilter" class="form-select form-select-sm product-assembly-status-filter">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="COMPLETED">Completed</option>
          <option value="VOID">Void</option>
        </select>
        <div class="search-set"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('production.product-assemblies.create', 'add')
          <a href="{{ route('production.product-assemblies.create') }}" class="btn btn-primary btn-sm px-16 py-8 radius-8 app-action-button app-primary-action">
            <iconify-icon icon="mdi:package-variant-closed-check"></iconify-icon>
            <span>New Assembly Batch</span>
          </a>
        @endperm
      </div>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="productAssembliesTable" style="width:100%">
          <thead><tr>
            <th>S.L</th>
            <th>Batch No.</th>
            <th>Date</th>
            <th>Finished Product</th>
            <th>Warehouse Flow</th>
            <th class="text-end">Planned</th>
            <th class="text-end">Good</th>
            <th>QC</th>
            <th>Status</th>
            <th class="text-end">Action</th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('production.product-assemblies.list.ajax'));

  $(document).on('preXhr.dt', '#productAssembliesTable', function(event, settings, data) {
    data.status = $('#assemblyStatusFilter').val();
  });

  $('#assemblyStatusFilter').on('change', function() {
    $('#productAssembliesTable').DataTable().ajax.reload();
  });

  $(document).on('click', '.btn-assembly-delete', function() {
    const url = $(this).data('url');
    const remove = () => $.ajax({
      url: url,
      type: 'POST',
      data: { _method: 'DELETE', _token: @json(csrf_token()) },
    }).done(() => $('#productAssembliesTable').DataTable().ajax.reload(null, false));

    window.Swal
      ? Swal.fire({ icon: 'warning', title: 'Delete this Assembly Draft?', showCancelButton: true, confirmButtonText: 'Delete Draft' })
          .then(result => result.isConfirmed && remove())
      : confirm('Delete this Assembly Draft?') && remove();
  });
</script>
@endsection
