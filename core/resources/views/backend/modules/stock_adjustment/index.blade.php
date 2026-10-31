@extends('backend.layouts.master')

@section('meta') <title>Stock Adjustments</title> @endsection

@section('content')
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <div class="d-flex align-items-center gap-2">
        <h6 class="fw-semibold mb-0">Stock Adjustments</h6>
        <x-backend.page-note title="How stock adjustment works" label="View stock adjustment rules" align="start">
          Use Gain for additional stock, Loss/Damage/Wastage for reductions, and Correction when the direction must be selected manually.
          Save as Draft, verify and Approve, then Post. Only posting changes the stock ledger and warehouse balance.
        </x-backend.page-note>
      </div>
      <p class="text-muted m-0">Controlled stock gain, loss, damage, wastage and correction</p>
    </div>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium"><a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary"><iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard</a></li>
      <li>-</li><li class="fw-medium">Stock Adjustments</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
      <h5 class="card-title mb-0">Stock Adjustment Documents</h5>
      <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
        <select id="adjustmentTypeFilter" class="form-select form-select-sm w-auto">
          <option value="">All types</option>
          @foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select id="adjustmentStatusFilter" class="form-select form-select-sm w-auto">
          <option value="">All status</option><option value="DRAFT">Draft</option><option value="APPROVED">Approved</option><option value="POSTED">Posted</option>
        </select>
        <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
        <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">@include('backend.include.buttons')</ul>
        @perm('inventory.stock-adjustments.store', 'add')
          <a href="{{ route('inventory.stock-adjustments.create') }}" class="btn btn-primary btn-sm px-12 py-8 radius-8 app-action-button"><iconify-icon icon="ic:baseline-plus" class="text-xl"></iconify-icon><span>Add Adjustment</span></a>
        @endperm
      </div>
    </div>
    <div class="card-body">
      <table class="table bordered-table table-scroll mb-0 AjaxDataTable" id="stockAdjustmentTable" style="width:100%">
        <thead><tr><th style="width:60px">S.L</th><th>Document No</th><th>Date</th><th>Type</th><th>Branch</th><th>Warehouse</th><th>Lines</th><th>Status</th><th style="width:130px">Action</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
@endsection

@section('script')
<script>
  var DATATABLE_URL = @json(route('inventory.stock-adjustments.list.ajax'));
  $(document).on('preXhr.dt', '#stockAdjustmentTable', function(e, settings, data) {
    data.status = $('#adjustmentStatusFilter').val();
    data.type = $('#adjustmentTypeFilter').val();
  });
  $(document).on('change', '#adjustmentStatusFilter, #adjustmentTypeFilter', function() {
    if ($.fn.DataTable.isDataTable('#stockAdjustmentTable')) $('#stockAdjustmentTable').DataTable().ajax.reload();
  });
  $(document).on('click', '.btn-stock-adjustment-delete', function(e) {
    e.preventDefault();
    const url = $(this).data('url');
    const remove = () => $.ajax({
      url, type: 'POST', dataType: 'json', data: {_method: 'DELETE', _token: @json(csrf_token())},
      success: res => { $('#stockAdjustmentTable').DataTable().ajax.reload(null, false); window.Swal && Swal.fire({icon: 'success', title: res?.msg || 'Deleted', timer: 1200, showConfirmButton: false}); },
      error: xhr => { const message = xhr.responseJSON?.msg || xhr.responseJSON?.message || 'Delete failed.'; window.Swal ? Swal.fire({icon: 'error', title: 'Failed', text: message}) : alert(message); }
    });
    if (window.Swal) {
      Swal.fire({icon: 'warning', title: 'Delete this draft?', text: 'This action removes the unposted adjustment.', showCancelButton: true, confirmButtonText: 'Yes, delete', confirmButtonColor: '#d33'}).then(result => { if (result.isConfirmed) remove(); });
    } else if (confirm('Delete this stock adjustment draft?')) remove();
  });
</script>
@endsection
