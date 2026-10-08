@extends('backend.layouts.master')

@section('meta')
  <title>Unit Conversions</title>
@endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Unit Conversions</h6>
      <p class="text-muted m-0">Universal conversion rules shared by inventory modules</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Unit Conversions</li>
    </ul>
  </div>

  <div class="alert alert-info bg-info-focus text-info-main border-info-main mb-24">
    <div class="d-flex gap-2 align-items-start">
      <iconify-icon icon="mdi:information-outline" class="text-xl mt-1"></iconify-icon>
      <div>
        <strong>Rule:</strong> enter one universal direction only. For example,
        <code>1 KG = 1000 G</code>. The reverse value is calculated automatically; do not create <code>G → KG</code> separately.
        Item-specific purchase, production or sale units will be configured inside each inventory item later.
      </div>
    </div>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h5 class="card-title mb-0">Conversion Rules</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
          @include('backend.include.buttons')
        </ul>
        @perm('units.index', 'view')
          <a href="{{ route('units.index') }}" class="d-flex btn btn-outline-primary btn-sm px-12 py-8 radius-8">
            <iconify-icon icon="mdi:ruler" class="text-xl"></iconify-icon> Units
          </a>
        @endperm
        @perm('units.conversions.store', 'add')
          <button class="d-flex btn btn-primary btn-sm px-12 py-8 radius-8 AjaxModal"
            data-ajax-modal="{{ route('units.conversions.createModal') }}"
            data-size="md" data-onsuccess="UnitConversionsIndex.onSaved">
            <iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon> Add Conversion
          </button>
        @endperm
      </div>
    </div>

    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="unitConversionsTable" style="width:100%">
        <thead>
          <tr>
            <th style="width:60px">S.L</th>
            <th>From Unit</th>
            <th>To Unit</th>
            <th>Conversion</th>
            <th>Calculated Reverse</th>
            <th>Updated</th>
            <th style="width:120px; text-align:right">Action</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = "{{ route('units.conversions.list.ajax') }}";

  window.UnitConversionsIndex = {
    onSaved: function(res) {
      $('.AjaxDataTable').DataTable().ajax.reload(null, false);
      if (window.Swal) {
        Swal.fire({ icon: 'success', title: res?.msg || 'Saved', timer: 1000, showConfirmButton: false });
      }
    }
  };

  $(document).on('click', '.btn-conversion-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');

    const removeConversion = () => {
      $.ajax({
        url: url,
        type: 'POST',
        dataType: 'json',
        data: { _method: 'DELETE', _token: '{{ csrf_token() }}' }
      }).done(function(res) {
        $('.AjaxDataTable').DataTable().ajax.reload(null, false);
        if (window.Swal) {
          Swal.fire({ icon: 'success', title: res?.msg || 'Deleted', timer: 900, showConfirmButton: false });
        }
      }).fail(function(xhr) {
        const message = xhr.responseJSON?.message || xhr.responseJSON?.msg || 'Delete failed.';
        window.Swal ? Swal.fire({ icon: 'error', title: 'Failed', text: message }) : alert(message);
      });
    };

    if (window.Swal) {
      Swal.fire({
        icon: 'warning',
        title: 'Delete conversion?',
        text: 'The reverse calculation will also become unavailable.',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete',
        confirmButtonColor: '#d33'
      }).then(result => { if (result.isConfirmed) removeConversion(); });
    } else if (confirm('Delete this conversion?')) {
      removeConversion();
    }
  });
</script>
@endsection
