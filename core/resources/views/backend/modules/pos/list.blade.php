@extends('backend.layouts.master')

@section('meta')
    <title>Sales</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Sales</h6>
            <p class="m-0">Manage POS sales</p>
        </div>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="#" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon> Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">POS</li>
        </ul>
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0">Sales List</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
            </div>
        </div>

        <div class="card-body">
            <table class="table bordered-table table-scroll AjaxDataTable" id="salesTable" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Date</th>
                        <th>Sold By</th>
                        <th width="120">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection


@section('script')
    <script>
        var DATATABLE_URL = "{{ route('pos.sales.list.ajax') }}";

        $(document).on('click', '.btn-resume-sale', function() {

            let saleId = $(this).data('id');

            // redirect to POS page with resume
            window.location.href =
                "{{ route('pos.index') }}?resume_sale_id=" + saleId;
        });
    </script>
@endsection
