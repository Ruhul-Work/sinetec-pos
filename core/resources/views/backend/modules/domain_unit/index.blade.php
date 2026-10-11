@extends('backend.layouts.master')

@section('meta')
  <title>Item Units</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Item Unit Mappings</h6>
        <x-backend.page-note
          title="How item unit conversion works"
          label="View item unit note"
          align="start">
          The item's base unit is automatically available with factor <code>1</code>. Add only alternative units here.
          For example, when the base unit is <code>PCS</code>, mapping <code>1 BOX = 12 PCS</code> means the conversion factor is <code>12</code>.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Alternative purchase, production and sale units with item-specific base conversion</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Item Units</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header border-bottom">
      <ul class="nav nav-pills domain-unit-tabs">
        @foreach($domains as $key => $domainConfig)
          <li class="nav-item">
            <a class="nav-link {{ $domain === $key ? 'active' : '' }}"
               href="{{ route('inventory.item-units.index', $key) }}">
              <iconify-icon icon="{{ $domainConfig['icon'] }}"></iconify-icon>
              <span>{{ $domainConfig['label'] }}</span>
            </a>
          </li>
        @endforeach
      </ul>

    </div>

    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">{{ $config['label'] }} Unit List</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="domainUnitUsageFilter" class="form-select form-select-sm w-auto">
          <option value="">All usage</option>
          @foreach($config['usages'] as $usage)
            <option value="{{ $usage }}">{{ $usage === 'ALL' ? 'All Applicable' : ucfirst(strtolower($usage)) }}</option>
          @endforeach
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>

        @perm('inventory.item-units.store', 'add')
          <button class="d-flex btn btn-primary btn-sm px-12 py-8 radius-8 AjaxModal"
                  data-ajax-modal="{{ route('inventory.item-units.createModal', $domain) }}"
                  data-size="md" data-onload="DomainUnitsIndex.onLoad" data-onsuccess="DomainUnitsIndex.onSaved">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon> Add {{ $config['singular'] }} Unit
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="domainUnitsTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>{{ $config['singular'] }}</th>
            <th>Base Unit</th>
            <th>Alternative Unit</th>
            <th>Conversion</th>
            <th>Usage</th>
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
  var DATATABLE_URL = @json(route('inventory.item-units.list.ajax', $domain));

  window.DomainUnitsIndex = {
    onLoad: function($modal) {
      S2.auto($modal);

      const $form = $modal.find('[data-domain-unit-form]');
      const $item = $form.find('[name="item_id"]');
      const $baseUnit = $form.find('[data-base-unit-text]');
      const infoBase = $form.data('item-info-base');

      $item.on('change', function() {
        const itemId = $(this).val();
        $baseUnit.text('Select an item to see its base unit.');
        if (!itemId) return;

        $.get(`${infoBase}/${itemId}/base-unit`)
          .done(function(response) {
            const unit = response.base_unit;
            $baseUnit.text(`${unit.name} (${unit.code})`);
          })
          .fail(function() {
            $baseUnit.text('Unable to load the base unit.');
          });
      });
    },
    onSaved: function() {
      $('#domainUnitsTable').DataTable().ajax.reload(null, false);
    }
  };

  $(document).on('preXhr.dt', '#domainUnitsTable', function(e, settings, data) {
    data.usage_type = $('#domainUnitUsageFilter').val();
  });

  $(document).on('change', '#domainUnitUsageFilter', function() {
    if ($.fn.DataTable.isDataTable('#domainUnitsTable')) {
      $('#domainUnitsTable').DataTable().ajax.reload();
    }
  });

  $(document).on('click', '.btn-domain-unit-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');

    const doDelete = () => $.ajax({
      url: url,
      type: 'POST',
      dataType: 'json',
      data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: function(res) {
        $('#domainUnitsTable').DataTable().ajax.reload(null, false);
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
        title: 'Delete unit mapping?',
        text: 'The item will no longer accept this alternative unit in new documents.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#d33'
      }).then(result => { if (result.isConfirmed) doDelete(); });
    } else if (confirm('Delete this item unit mapping?')) {
      doDelete();
    }
  });
</script>
@endsection
