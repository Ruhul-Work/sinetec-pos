@extends('backend.layouts.master')

@section('meta')
  <title>Parts</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Parts</h6>
      <p class="text-muted m-0">Internally made, externally purchased or dual-source part records</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Parts</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Part List</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="partProcurementModeFilter" class="form-select form-select-sm w-auto">
          <option value="">All procurement</option>
          <option value="MAKE">Make</option>
          <option value="BUY">Buy</option>
          <option value="BOTH">Both</option>
        </select>
        <select id="partStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm('inventory.parts.store', 'add')
          <button class="d-flex btn btn-primary btn-sm px-12 py-8 radius-8 AjaxModal"
                  data-ajax-modal="{{ route('inventory.parts.createModal') }}"
                  data-size="lg"
                  data-onload="PartsIndex.onLoad"
                  data-onsuccess="PartsIndex.onSaved">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon> Add Part
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="partsTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>Image</th>
            <th>Code</th>
            <th>Name / Barcode</th>
            <th>Category</th>
            <th>Procurement</th>
            <th>Base Unit</th>
            <th>Brand</th>
            <th>Status</th>
            <th style="width:120px">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('inventory.parts.list.ajax'));

  window.PartsIndex = {
    onLoad: function($modal) {
      S2.auto($modal);
    },
    onSaved: function() {
      $('#partsTable').DataTable().ajax.reload(null, false);
    }
  };

  $(document).on('preXhr.dt', '#partsTable', function(e, settings, data) {
    data.procurement_mode = $('#partProcurementModeFilter').val();
    data.status = $('#partStatusFilter').val();
  });

  $(document).on('change', '#partProcurementModeFilter, #partStatusFilter', function() {
    if ($.fn.DataTable.isDataTable('#partsTable')) {
      $('#partsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-part-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');

    const doDelete = () => $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#partsTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1000, showConfirmButton: false});
      },
      error: function(xhr) {
        const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
        window.Swal
          ? Swal.fire({icon: xhr.status === 422 ? 'warning' : 'error', title: xhr.status === 422 ? 'Blocked' : 'Failed', text: message})
          : alert(message);
      }
    });

    if (window.Swal) {
      Swal.fire({
        icon: 'warning',
        title: 'Delete part?',
        text: 'Referenced parts cannot be deleted. Use inactive status when history exists.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#d33'
      }).then(result => { if (result.isConfirmed) doDelete(); });
    } else if (confirm('Delete this part?')) {
      doDelete();
    }
  });
</script>
@endsection
