@extends('backend.layouts.master')

@section('meta')
    <title>Loan Repayments</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Loan Repayments</h6>
            <p class="text-muted m-0">Repay a posted Director / Partner loan from Cash or Bank</p>
        </div>

        <button
            class="btn btn-primary app-primary-action d-inline-flex align-items-center gap-1 px-14 py-9 radius-8 AjaxModal"
            data-ajax-modal="{{ route('finance.loan-repayments.create') }}"
            data-size="lg"
            data-onsuccess="LoanRepayments.onSaved"
        >
            <iconify-icon icon="mdi:cash-minus"></iconify-icon>
            <span>New Repayment</span>
        </button>
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Repayment Documents</h5>
            <div class="actions-bar d-flex align-items-center gap-2">
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
            </div>
        </div>

        <div class="card-body">
            <table
                class="table bordered-table table-scroll mb-0 AjaxDataTable"
                id="repaymentTable"
                style="width: 100%"
            >
                <thead>
                    <tr>
                        <th>S.L</th>
                        <th>Repayment No</th>
                        <th>Loan Receipt</th>
                        <th>Date</th>
                        <th>Paid From</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('finance.loan-repayments.list.ajax'));

        window.LoanRepayments = {
            onSaved: function (response) {
                if (response && response.redirect_url) {
                    window.location.href = response.redirect_url;
                }
            }
        };
    </script>
@endsection
