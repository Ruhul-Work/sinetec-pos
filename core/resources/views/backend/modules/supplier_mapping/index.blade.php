@extends('backend.layouts.master')

@section('meta')
  <title>{{ $config['title'] }}</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">{{ $config['title'] }}</h6>
        <x-backend.page-note
          title="Why supplier mapping is required"
          label="View supplier mapping note"
          align="start">
          A {{ strtolower($config['singular']) }} may be sourced from several suppliers. This mapping keeps each supplier's
          item code, expected lead time and one preferred source. Purchase prices are not stored here; actual rates come
          from purchase transactions and price history.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Approved suppliers, supplier item codes and expected delivery lead time</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Supplier Mapping</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">{{ $config['list_title'] }}</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="supplierMappingPreferredFilter" class="form-select form-select-sm w-auto">
          <option value="">All suppliers</option>
          <option value="1">Preferred only</option>
          <option value="0">Standard only</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm($config['route_name'].'.store', 'add')
          <button class="d-flex btn btn-primary btn-sm px-12 py-8 radius-8 AjaxModal"
                  data-ajax-modal="{{ route($config['route_name'].'.createModal') }}"
                  data-size="md"
                  data-onload="SupplierMappingsIndex.onLoad"
                  data-onsuccess="SupplierMappingsIndex.onSaved">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon>
            Add {{ $config['singular'] }} Supplier
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="supplierMappingsTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>{{ $config['singular'] }}</th>
            <th>Supplier</th>
            <th>Supplier Item Code</th>
            <th>Lead Time</th>
            <th>Source</th>
            <th>Updated</th>
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
  var DATATABLE_URL = @json(route($config['route_name'].'.list.ajax'));

  window.SupplierMappingsIndex = {
    onLoad: function($modal) {
      S2.auto($modal);
    },
    onSaved: function() {
      $('#supplierMappingsTable').DataTable().ajax.reload(null, false);
    }
  };

  $(document).on('preXhr.dt', '#supplierMappingsTable', function(e, settings, data) {
    data.preferred = $('#supplierMappingPreferredFilter').val();
  });

  $(document).on('change', '#supplierMappingPreferredFilter', function() {
    if ($.fn.DataTable.isDataTable('#supplierMappingsTable')) {
      $('#supplierMappingsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-supplier-mapping-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');

    const doDelete = () => $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#supplierMappingsTable').DataTable().ajax.reload(null, false);
        window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1000, showConfirmButton: false});
      },
      error: function(xhr) {
        const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.';
        window.Swal ? Swal.fire({icon: 'error', title: 'Failed', text: message}) : alert(message);
      }
    });

    if (window.Swal) {
      Swal.fire({
        icon: 'warning',
        title: 'Delete supplier mapping?',
        text: 'This supplier will no longer be suggested for the selected item.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#d33'
      }).then(result => { if (result.isConfirmed) doDelete(); });
    } else if (confirm('Delete this supplier mapping?')) {
      doDelete();
    }
  });
</script>
@endsection
