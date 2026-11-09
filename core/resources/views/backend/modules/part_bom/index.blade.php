@extends('backend.layouts.master')

@section('meta')
  <title>Part BOM / Recipe</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Part BOM / Recipe</h6>
      <p class="text-muted m-0">Define the raw materials required to make a Part before production starts.</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li>
      <li class="fw-medium">Part BOM</li>
    </ul>
  </div>

  @if(session('success'))
    <div class="alert alert-success d-flex align-items-center gap-2"><iconify-icon icon="mdi:check-circle-outline" class="text-xl"></iconify-icon>{{ session('success') }}</div>
  @endif

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <div>
        <h5 class="card-title mb-0">Part BOM List</h5>
        <p class="text-muted text-sm mb-0">Only active Make or Both Parts can have an active production recipe.</p>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap part-bom-index-actions">
        <select id="partBomStatusFilter" class="form-select form-select-sm part-bom-status-filter">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
        <div class="search-set"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
        @perm('production.part-boms.create', 'add')
          <a href="{{ route('production.part-boms.create') }}" class="btn btn-primary btn-sm px-16 py-8 radius-8 app-action-button app-primary-action">
            <iconify-icon icon="mdi:playlist-plus"></iconify-icon><span>Create Part BOM</span>
          </a>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="partBomsTable" style="width:100%">
          <thead>
            <tr>
              <th style="width:60px">S.L</th>
              <th>Part</th>
              <th>Version</th>
              <th class="text-end">Output Qty</th>
              <th class="text-center">Materials</th>
              <th>Effective Dates</th>
              <th>Status</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('production.part-boms.list.ajax'));

  $(document).on('preXhr.dt', '#partBomsTable', function(event, settings, data) {
    data.status = $('#partBomStatusFilter').val();
  });

  $(document).on('change', '#partBomStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#partBomsTable')) {
      $('#partBomsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-part-bom-delete', function() {
    const url = $(this).data('url');
    const submitDelete = function() {
      $.ajax({
        url: url,
        type: 'POST',
        data: { _method: 'DELETE', _token: @json(csrf_token()) },
      }).done(function(response) {
        $('#partBomsTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({ icon: 'success', title: response?.msg || 'Draft deleted.', timer: 1100, showConfirmButton: false });
      }).fail(function(xhr) {
        const message = xhr.responseJSON?.message || 'The Draft BOM could not be deleted.';
        window.Swal ? Swal.fire({ icon: 'error', title: 'Delete failed', text: message }) : alert(message);
      });
    };

    if (window.Swal) {
      Swal.fire({ icon: 'warning', title: 'Delete this Draft BOM?', text: 'Only unused Draft BOMs can be deleted.', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
        .then(result => { if (result.isConfirmed) submitDelete(); });
    } else if (confirm('Delete this Draft BOM?')) {
      submitDelete();
    }
  });
</script>
@endsection
