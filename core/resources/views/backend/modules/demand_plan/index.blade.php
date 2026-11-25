@extends('backend.layouts.master')

@section('meta')
    <title>Demand Plans</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Demand Plans</h6>
                <x-backend.page-note title="How Demand Planning works" label="View Demand Planning note">
                    A Demand Plan estimates one branch's Finished Product requirement for one target month.
                    Sales history provides a suggestion; the planner may override it. Approval freezes the plan
                    for Material Requirement Planning and never changes stock or Finance.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Prepare branch-wise Finished Product demand before material planning.</p>
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
            <h5 class="card-title mb-0">Demand Plan Register</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
                <select id="demandPlanStatusFilter" class="form-select form-select-sm" style="width: 145px">
                    <option value="">All status</option>
                    <option value="DRAFT">Draft</option>
                    <option value="APPROVED">Approved</option>
                    <option value="CANCELLED">Cancelled</option>
                </select>
                <div class="search-set me-2"><div id="tableSearch" class="search-input"></div></div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
                @perm('planning.demand-plans.create', 'add')
                    <a href="{{ route('planning.demand-plans.create') }}"
                       class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button app-primary-action">
                        <iconify-icon icon="mdi:chart-timeline-variant-shimmer"></iconify-icon>
                        <span>New Demand Plan</span>
                    </a>
                @endperm
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="demandPlansTable"
                       class="table bordered-table table-scroll mb-0 align-middle AjaxDataTable"
                       style="width: 100%">
                    <thead>
                        <tr>
                            <th style="width: 60px">S.L</th>
                            <th>Plan No.</th>
                            <th>Target Month</th>
                            <th>Branch</th>
                            <th class="text-center">Products</th>
                            <th class="text-end">Planned Qty</th>
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
        var DATATABLE_URL = @json(route('planning.demand-plans.list.ajax'));

        $(document).on('preXhr.dt', '#demandPlansTable', function(event, settings, data) {
            data.status = $('#demandPlanStatusFilter').val();
        });

        $('#demandPlanStatusFilter').on('change', function() {
            if ($.fn.DataTable.isDataTable('#demandPlansTable')) {
                $('#demandPlansTable').DataTable().ajax.reload();
            }
        });

        $(document).on('click', '.btn-demand-plan-delete', function() {
            const url = $(this).data('url');

            Swal.fire({
                icon: 'warning',
                title: 'Delete this Demand Plan?',
                text: 'Only an unused Draft or Cancelled plan can be deleted.',
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
                    $('#demandPlansTable').DataTable().ajax.reload(null, false);
                    Swal.fire({
                        icon: 'success',
                        title: response.message || 'Demand Plan deleted.',
                        timer: 1100,
                        showConfirmButton: false,
                    });
                }).fail(function(xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Delete failed',
                        text: xhr.responseJSON?.message || 'The Demand Plan could not be deleted.',
                    });
                });
            });
        });
    </script>
@endsection
