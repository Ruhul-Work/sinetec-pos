@extends('backend.layouts.master')

@section('meta')
    <title>Account Ledger</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Account Ledger</h6>
                <x-backend.page-note title="How to read this ledger" label="View ledger rule" align="start">
                    Only POSTED journal lines and POSTED account opening balances appear here.
                    Draft and Void journals are excluded. Running Balance uses the account's full
                    chronological history, even when the selected date range hides earlier rows.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Account-wise branch movement, debit, credit and true running balance</p>
        </div>

        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a
                    href="{{ route('backend.dashboard') }}"
                    class="d-flex align-items-center gap-1 hover-text-primary"
                >
                    <iconify-icon
                        icon="solar:home-smile-angle-outline"
                        class="icon text-lg"
                    ></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Account Ledger</li>
        </ul>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Ledger Filters</h5>
            <button
                type="button"
                id="resetAccountLedgerFilters"
                class="btn btn-outline-secondary btn-sm px-12 py-8 radius-8 app-action-button"
            >
                <iconify-icon icon="mdi:filter-remove-outline"></iconify-icon>
                <span>Reset Filters</span>
            </button>
        </div>
        <div class="card-body">
            <div class="row gy-3 align-items-end" id="accountLedgerFilters">
                <div class="col-xl-4 col-md-6">
                    <label class="form-label text-sm">
                        Account <span class="text-danger">*</span>
                    </label>
                    <select
                        id="ledgerAccount"
                        class="form-control"
                        data-placeholder="Select an account"
                    ></select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label text-sm">Branch</label>
                    <select id="ledgerBranch" class="form-control js-s2">
                        <option value="">All available branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">
                                {{ $branch->name }} ({{ $branch->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-sm">From Date</label>
                    <input
                        type="date"
                        id="ledgerDateFrom"
                        class="form-control"
                        value="{{ now()->startOfMonth()->format('Y-m-d') }}"
                    >
                </div>
                <div class="col-xl-2 col-md-4">
                    <label class="form-label text-sm">To Date</label>
                    <input
                        type="date"
                        id="ledgerDateTo"
                        class="form-control"
                        value="{{ now()->format('Y-m-d') }}"
                    >
                </div>
                <div class="col-xl-1 col-md-4">
                    <button
                        type="button"
                        id="applyAccountLedgerFilters"
                        class="btn btn-primary w-100 px-14 py-10 radius-8 app-action-button app-primary-action"
                    >
                        <iconify-icon icon="mdi:magnify"></iconify-icon>
                        <span>Apply</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="accountLedgerSummary" class="row g-3 mb-24 d-none">
        <x-backend.finance-summary-card
            variant="opening"
            label="Opening Balance"
            summary-key="opening"
            description="Before selected period"
        />
        <x-backend.finance-summary-card
            variant="debit"
            label="Period Debit"
            summary-key="debit"
            description="Selected date range"
            value-class="text-success-main"
        />
        <x-backend.finance-summary-card
            variant="credit"
            label="Period Credit"
            summary-key="credit"
            description="Selected date range"
            value-class="text-danger-main"
        />
        <x-backend.finance-summary-card
            variant="closing"
            label="Closing Balance"
            summary-key="closing"
            description="As of selected end date"
        />
    </div>

    <div class="card basic-data-table">
        <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <h5 class="card-title mb-0">Posted Account Movements</h5>
            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
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
                id="accountLedgerTable"
                style="width: 100%"
            >
                <thead>
                    <tr>
                        <th style="width: 65px">S.L</th>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Branch</th>
                        <th>Voucher / Source</th>
                        <th>Memo</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Running Balance</th>
                        <th style="width: 80px">Journal</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('finance.account-ledger.list.ajax'));

        (function ($) {
            const accountUrl = @json(route('finance.account-ledger.accounts.select2'));
            const summaryUrl = @json(route('finance.account-ledger.summary'));
            const defaultDateFrom = @json(now()->startOfMonth()->format('Y-m-d'));
            const defaultDateTo = @json(now()->format('Y-m-d'));

            S2.auto($('#accountLedgerFilters'));
            S2.init($('#ledgerAccount'), {
                ajax: {
                    url: accountUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            q: params.term || '',
                            branch_id: $('#ledgerBranch').val()
                        };
                    },
                    processResults: function (response) {
                        return { results: response.results || [] };
                    }
                }
            });

            function requestData() {
                return {
                    account_id: $('#ledgerAccount').val(),
                    branch_id: $('#ledgerBranch').val(),
                    date_from: $('#ledgerDateFrom').val(),
                    date_to: $('#ledgerDateTo').val()
                };
            }

            function reloadLedger() {
                if ($.fn.DataTable.isDataTable('#accountLedgerTable')) {
                    $('#accountLedgerTable').DataTable().ajax.reload();
                }
            }

            function setBalanceSummary(key, balance) {
                const prefix = balance.side ? `${balance.side} ` : '';

                $(`[data-summary="${key}"]`)
                    .attr('class', `mb-0 fw-bold ${balance.class}`)
                    .text(prefix + balance.amount);
            }

            function reloadSummary() {
                if (!$('#ledgerAccount').val()) {
                    $('#accountLedgerSummary').addClass('d-none');
                    return;
                }

                $.post(summaryUrl, requestData()).done(function (response) {
                    if (!response.selected) {
                        $('#accountLedgerSummary').addClass('d-none');
                        return;
                    }

                    setBalanceSummary('opening', response.opening);
                    $('[data-summary="debit"]').text(response.debit);
                    $('[data-summary="credit"]').text(response.credit);
                    setBalanceSummary('closing', response.closing);
                    $('#accountLedgerSummary').removeClass('d-none');
                });
            }

            function refreshLedger() {
                reloadLedger();
                reloadSummary();
            }

            $(document).on('preXhr.dt', '#accountLedgerTable', function (event, settings, data) {
                Object.assign(data, requestData());
            });

            $('#ledgerAccount').on('change', refreshLedger);
            $('#ledgerBranch').on('change', function () {
                $('#ledgerAccount').empty().trigger('change');
                refreshLedger();
            });
            $('#applyAccountLedgerFilters').on('click', refreshLedger);
            $('#ledgerDateFrom, #ledgerDateTo').on('change', refreshLedger);
            $('#resetAccountLedgerFilters').on('click', function () {
                $('#ledgerBranch').val('').trigger('change');
                $('#ledgerAccount').empty().trigger('change');
                $('#ledgerDateFrom').val(defaultDateFrom);
                $('#ledgerDateTo').val(defaultDateTo);
                refreshLedger();
            });
        })(jQuery);
    </script>
@endsection
