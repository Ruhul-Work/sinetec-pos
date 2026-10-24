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
          title="How reorder levels work"
          label="View reorder level note"
          align="start">
          Minimum quantity is the low-stock threshold for one item in one warehouse. Default replenishment quantity is optional:
          use it only for a fixed purchase or production lot. Leave it blank for future demand-based planning. Maximum quantity is
          an optional target ceiling. These settings never create or post stock transactions automatically.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Warehouse-wise minimum, optional fixed replenishment and target stock quantities</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Reorder Levels</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">{{ $config['list_title'] }}</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <div class="reorder-warehouse-filter">
          <select id="reorderWarehouseFilter" class="form-control"
                  data-url="{{ route($config['route_name'].'.warehouses.select2') }}"
                  placeholder="All warehouses"></select>
        </div>

        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm($config['route_name'].'.store', 'add')
          <button class="d-flex btn btn-primary btn-sm px-12 py-8 radius-8 AjaxModal"
                  data-ajax-modal="{{ route($config['route_name'].'.createModal') }}"
                  data-size="md"
                  data-onload="ReorderLevelsIndex.onLoad"
                  data-onsuccess="ReorderLevelsIndex.onSaved">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon>
            Add {{ $config['singular'] }} Level
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="reorderLevelsTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>{{ $config['singular'] }}</th>
            <th>Warehouse</th>
            <th>Minimum Qty</th>
            <th>Default Replenishment</th>
            <th>Maximum Qty</th>
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

  window.ReorderLevelsIndex = {
    onLoad: function($modal) {
      S2.auto($modal);
    },
    onSaved: function() {
      $('#reorderLevelsTable').DataTable().ajax.reload(null, false);
    }
  };

  $(function() {
    S2.ajax($('#reorderWarehouseFilter'), $('#reorderWarehouseFilter').data('url'), {
      placeholder: 'All warehouses',
      allowClear: true
    });
  });

  $(document).on('preXhr.dt', '#reorderLevelsTable', function(e, settings, data) {
    data.warehouse_id = $('#reorderWarehouseFilter').val();
  });

  $(document).on('change', '#reorderWarehouseFilter', function() {
    if ($.fn.DataTable.isDataTable('#reorderLevelsTable')) {
      $('#reorderLevelsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-reorder-level-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');

    const doDelete = () => $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#reorderLevelsTable').DataTable().ajax.reload(null, false);
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
        title: 'Delete reorder level?',
        text: 'Low-stock planning will no longer use this warehouse-specific setting.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#d33'
      }).then(result => { if (result.isConfirmed) doDelete(); });
    } else if (confirm('Delete this reorder level?')) {
      doDelete();
    }
  });
</script>
@endsection
