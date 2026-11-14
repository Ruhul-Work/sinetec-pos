@extends('backend.layouts.master')

@section('meta')
  <title>Internal Part Production</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Internal Part Production</h6>
      <p class="text-muted m-0">Consume raw materials from an active BOM and receive the manufactured Part into stock.</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Part Production</li>
    </ul>
  </div>

  @if (session('success'))
    <div class="alert alert-success d-flex align-items-center gap-2">
      <iconify-icon icon="mdi:check-circle-outline" class="text-xl"></iconify-icon>
      {{ session('success') }}
    </div>
  @endif

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <div>
        <h5 class="card-title mb-0">Production Batch List</h5>
        <p class="text-muted text-sm mb-0">Draft batches have no stock effect; completion permanently posts consumption and output.</p>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap part-production-index-actions">
        <select id="partProductionStatusFilter" class="form-select form-select-sm part-production-status-filter">
          <option value="">All status</option>
          <option value="DRAFT">Draft</option>
          <option value="COMPLETED">Completed</option>
          <option value="VOID">Void</option>
        </select>
        <div class="search-set">
          <div id="tableSearch" class="search-input"></div>
        </div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('production.part-productions.create', 'add')
          <a href="{{ route('production.part-productions.create') }}" class="btn btn-primary btn-sm px-16 py-8 radius-8 app-action-button app-primary-action">
            <iconify-icon icon="mdi:factory"></iconify-icon>
            <span>New Production Batch</span>
          </a>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <div class="table-responsive">
        <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="partProductionsTable" style="width: 100%">
          <thead>
            <tr>
              <th style="width: 60px">S.L</th>
              <th>Batch No.</th>
              <th>Production Date</th>
              <th>Output Part</th>
              <th>Warehouse Flow</th>
              <th class="text-end">Planned Qty</th>
              <th class="text-end">Good Qty</th>
              <th>QC</th>
              <th class="text-end">Cost Snapshot</th>
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
  var DATATABLE_URL = @json(route('production.part-productions.list.ajax'));

  $(document).on('preXhr.dt', '#partProductionsTable', function(event, settings, data) {
    data.status = $('#partProductionStatusFilter').val();
  });

  $(document).on('change', '#partProductionStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#partProductionsTable')) {
      $('#partProductionsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-part-production-delete', function() {
    const url = $(this).data('url');

    const deleteDraft = function() {
      $.ajax({
        url: url,
        type: 'POST',
        data: {
          _method: 'DELETE',
          _token: @json(csrf_token()),
        },
      }).done(function(response) {
        $('#partProductionsTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({
          icon: 'success',
          title: response?.msg || 'Draft deleted.',
          timer: 1200,
          showConfirmButton: false,
        });
      }).fail(function(xhr) {
        const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'The production draft could not be deleted.';
        window.Swal
          ? Swal.fire({ icon: 'error', title: 'Delete failed', text: message })
          : alert(message);
      });
    };

    if (window.Swal) {
      Swal.fire({
        icon: 'warning',
        title: 'Delete this production Draft?',
        text: 'Only Draft batches can be deleted.',
        showCancelButton: true,
        confirmButtonText: 'Delete Draft',
        confirmButtonColor: '#dc3545',
      }).then(result => {
        if (result.isConfirmed) {
          deleteDraft();
        }
      });
    } else if (confirm('Delete this production Draft?')) {
      deleteDraft();
    }
  });
</script>
@endsection
