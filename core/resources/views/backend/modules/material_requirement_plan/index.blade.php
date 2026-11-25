@extends('backend.layouts.master')

@section('meta')
    <title>Material Requirement Plans (MRP)</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Material Requirement Plans</h6>
                <x-backend.page-note title="How Material Requirement Planning works" label="View MRP note">
                    A Material Requirement Plan (MRP) calculates the raw materials and parts needed to fulfill an Approved Demand Plan.
                    It explodes the net Finished Product requirements through active Bills of Material (BOM) and subtracts current stock.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Translate approved product demand into procurement and manufacturing requirements.</p>
        </div>
        <a href="{{ route('reporting.dashboard.index') }}"
           class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Reporting</span>
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">MRP Register</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
                <select id="mrpStatusFilter" class="form-select form-select-sm" style="width: 145px">
                    <option value="">All status</option>
                    <option value="DRAFT">Draft</option>
                    <option value="APPROVED">Approved</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
                <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
                @perm('planning.material-requirement-plans.create', 'add')
                    <a href="{{ route('planning.material-requirement-plans.create') }}"
                       class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button app-primary-action">
                        <iconify-icon icon="mdi:clipboard-list-outline"></iconify-icon>
                        <span>New MRP</span>
                    </a>
                @endperm
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="mrpTable"
                       class="table bordered-table table-scroll mb-0 align-middle AjaxDataTable"
                       style="width: 100%">
                    <thead>
                        <tr>
                            <th style="width: 60px">S.L</th>
                            <th>MRP No.</th>
                            <th>Demand Plan</th>
                            <th>Target Month</th>
                            <th>Branch</th>
                            <th class="text-center">Products</th>
                            <th class="text-center">Components</th>
                            <th>Status</th>
                            <th>Approved</th>
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
        var DATATABLE_URL = @json(route('planning.material-requirement-plans.list.ajax'));

        $(document).on('preXhr.dt', '#mrpTable', function(event, settings, data) {
            data.status = $('#mrpStatusFilter').val();
        });

        $('#mrpStatusFilter').on('change', function() {
            if ($.fn.DataTable.isDataTable('#mrpTable')) {
                $('#mrpTable').DataTable().ajax.reload();
            }
        });

        $(document).on('click', '.btn-mrp-delete', function() {
            const url = $(this).data('url');

            Swal.fire({
                icon: 'warning',
                title: 'Delete this Material Requirement Plan?',
                text: 'Only an unused Draft or Cancelled MRP can be deleted.',
                showCancelButton: true,
                confirmButtonText: 'Delete',
                confirmButtonColor: '#dc3545',
            }).then(function(result) {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {_method: 'DELETE', _token: @json(csrf_token())},
                }).done(function(response) {
                    $('#mrpTable').DataTable().ajax.reload(null, false);
                    Swal.fire({
                        icon: 'success',
                        title: response.message || 'MRP deleted.',
                        timer: 1100,
                        showConfirmButton: false,
                    });
                }).fail(function(xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Delete failed',
                        text: xhr.responseJSON?.message || 'The MRP could not be deleted.',
                    });
                });
            });
        });
    </script>
@endsection
