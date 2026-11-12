@php($isProductionSettlement = (bool) $selectedProductionBatchId)

<div class="modal-header py-16 px-24 border-0">
    <div>
        <h5 class="modal-title">
            {{ $isProductionSettlement ? 'Settle Production Costs' : 'New General Expense Payment' }}
        </h5>
        <small class="text-muted">
            {{ $isProductionSettlement
                ? 'Choose one or more unpaid cost lines and enter the amount being paid now.'
                : 'Save a Draft first, then record it to create the finance Journal Draft.' }}
        </small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form method="POST" action="{{ route('finance.expenses.store') }}" data-ajax="true">
    @csrf

    <input type="hidden" name="source_mode" value="{{ $isProductionSettlement ? 'PRODUCTION' : 'GENERAL' }}">

    @if ($isProductionSettlement)
        <input type="hidden" name="part_production_batch_id" value="{{ $productionBatch->id }}">
    @endif

    <div class="modal-body p-24">
        <div class="alert alert-light-info border-info-subtle text-sm py-10 mb-20">
            @if ($isProductionSettlement)
                Production completion already recorded the cost in Production Expense Payable. This payment will
                debit that payable and credit the selected Cash / Bank account after its Journal is posted.
            @else
                General expense payment: Debit the category Expense account and credit the selected Cash / Bank account.
            @endif
        </div>

        @if ($isProductionSettlement)
            <div class="production-settlement-source radius-8 p-16 mb-20">
                <div class="row g-3">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Production Batch</small>
                        <strong>{{ $productionBatch->batch_no }}</strong>
                    </div>
                    <div class="col-md-5">
                        <small class="text-muted d-block">Output Part</small>
                        <strong>{{ $productionBatch->part_name }}</strong>
                        <code class="ms-1">{{ $productionBatch->part_code }}</code>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block">Production Date</small>
                        <strong>{{ date('d M Y', strtotime($productionBatch->production_date)) }}</strong>
                    </div>
                </div>
            </div>

            <div class="table-responsive mb-20">
                <table class="table bordered-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 52px">Pay</th>
                            <th>Production Cost Category</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Already Booked</th>
                            <th class="text-end">Remaining</th>
                            <th style="width: 170px" class="text-end">Pay Now</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($productionAllocations as $index => $allocation)
                            @php($remaining = max((float) $allocation->amount - (float) $allocation->booked_amount, 0))
                            <tr>
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        name="production_items[{{ $index }}][selected]"
                                        value="1"
                                        class="form-check-input js-settlement-item"
                                        data-target="#productionSettlementAmount{{ $index }}"
                                    >
                                    <input
                                        type="hidden"
                                        name="production_items[{{ $index }}][part_production_expense_id]"
                                        value="{{ $allocation->id }}"
                                    >
                                </td>
                                <td>
                                    <strong>{{ $allocation->category_name }}</strong>
                                    @if ($allocation->note)
                                        <small class="text-muted d-block">{{ $allocation->note }}</small>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format((float) $allocation->amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $allocation->booked_amount, 2) }}</td>
                                <td class="text-end fw-semibold text-warning-main">
                                    {{ number_format($remaining, 2) }}
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="production_items[{{ $index }}][amount]"
                                        id="productionSettlementAmount{{ $index }}"
                                        class="form-control form-control-sm text-end js-settlement-amount"
                                        min="0.0001"
                                        max="{{ number_format($remaining, 4, '.', '') }}"
                                        step="0.0001"
                                        value="{{ number_format($remaining, 4, '.', '') }}"
                                        disabled
                                    >
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-20">
                                    Every production cost line in this batch is already booked.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        <div class="row g-3">
            <div class="col-lg-4">
                <label class="form-label">Branch <span class="text-danger">*</span></label>
                <select
                    name="branch_id"
                    id="expenseBranch"
                    class="form-select form-select-sm js-s2"
                    @disabled(current_branch_id() || $isProductionSettlement)
                    required
                >
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($branchId === (int) $branch->id)>
                            {{ $branch->name }} — {{ $branch->code }}
                        </option>
                    @endforeach
                </select>
                @if (current_branch_id() || $isProductionSettlement)
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @endif
            </div>

            <div class="col-lg-4">
                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="expense_date"
                    class="form-control form-control-sm"
                    value="{{ old('expense_date', now()->toDateString()) }}"
                    required
                >
            </div>

            <div class="col-lg-4">
                <label class="form-label">Amount <span class="text-danger">*</span></label>
                <input
                    type="number"
                    name="amount"
                    id="expenseAmount"
                    class="form-control form-control-sm text-end"
                    min="0.0001"
                    step="0.0001"
                    value="{{ old('amount') }}"
                    @readonly($isProductionSettlement)
                    required
                >
            </div>

            @unless ($isProductionSettlement)
                <div class="col-lg-8">
                    <label class="form-label">Expense Category <span class="text-danger">*</span></label>
                    <select name="expense_category_id" id="generalExpenseCategory" class="form-select form-select-sm js-s2" required>
                        <option value="">Select expense category</option>
                        @foreach ($options['categories'] as $category)
                            <option value="{{ $category->id }}">
                                {{ $category->name }} — {{ $category->account_code }} {{ $category->account_name }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted">General expenses are not included in production inventory cost.</small>
                </div>
            @endunless

            <div class="col-lg-4">
                <label class="form-label">Cash / Bank Account <span class="text-danger">*</span></label>
                <select name="account_id" id="expenseCashAccount" class="form-select form-select-sm js-s2" required>
                    <option value="">Select Cash / Bank account</option>
                    @foreach ($options['cash_accounts'] as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-lg-4">
                <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                <select name="payment_type_id" id="expensePaymentType" class="form-select form-select-sm js-s2" required>
                    <option value="">Select payment type</option>
                    @foreach ($options['payment_types'] as $paymentType)
                        <option value="{{ $paymentType->id }}">{{ $paymentType->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-lg-4">
                <label class="form-label">Paid To</label>
                <input type="text" name="payee" class="form-control form-control-sm" maxlength="150">
            </div>

            <div class="col-lg-4">
                <label class="form-label">Reference No.</label>
                <input type="text" name="reference_no" class="form-control form-control-sm" maxlength="100">
            </div>

            <div class="col-lg-8">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control form-control-sm" maxlength="5000">
            </div>
        </div>
    </div>

    <div class="modal-footer px-24 py-16 border-0 d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm px-16 py-8 radius-8 app-action-button" data-bs-dismiss="modal">
            Cancel
        </button>
        <button type="submit" class="btn btn-primary btn-sm px-18 py-8 radius-8 app-primary-action d-inline-flex align-items-center gap-1">
            <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
            <span>Save Draft</span>
        </button>
    </div>
</form>

<script>
    (function ($) {
        const $modal = $('#AjaxModal');
        const isProductionSettlement = @json($isProductionSettlement);
        const optionsUrl = @json(route('finance.expenses.branch-options', '__branch__'));

        function appendOptions($select, placeholder, rows, labelCallback) {
            $select.empty().append($('<option>').val('').text(placeholder));

            rows.forEach(function (row) {
                $select.append($('<option>').val(row.id).text(labelCallback(row)));
            });

            $select.trigger('change');
        }

        function updateSettlementTotal() {
            let total = 0;

            $('.js-settlement-item:checked').each(function () {
                const $amount = $($(this).data('target'));
                total += Number($amount.val()) || 0;
            });

            $('#expenseAmount').val(total > 0 ? total.toFixed(4) : '');
        }

        S2.auto($modal);

        $modal
            .off('change.productionSettlement', '.js-settlement-item')
            .on('change.productionSettlement', '.js-settlement-item', function () {
                const $amount = $($(this).data('target'));
                $amount.prop('disabled', !this.checked);
                updateSettlementTotal();
            })
            .off('input.productionSettlement', '.js-settlement-amount')
            .on('input.productionSettlement', '.js-settlement-amount', updateSettlementTotal);

        if (!isProductionSettlement) {
            $modal
                .off('change.generalExpenseBranch', '#expenseBranch')
                .on('change.generalExpenseBranch', '#expenseBranch', function () {
                    const branchId = $(this).val();

                    if (!branchId) {
                        return;
                    }

                    $.get(optionsUrl.replace('__branch__', branchId), function (response) {
                        appendOptions(
                            $('#generalExpenseCategory'),
                            'Select expense category',
                            response.categories || [],
                            function (row) {
                                return `${row.name} — ${row.account_code} ${row.account_name}`;
                            },
                        );
                        appendOptions(
                            $('#expenseCashAccount'),
                            'Select Cash / Bank account',
                            response.cash_accounts || [],
                            function (row) {
                                return `${row.code} — ${row.name}`;
                            },
                        );
                        appendOptions(
                            $('#expensePaymentType'),
                            'Select payment type',
                            response.payment_types || [],
                            function (row) {
                                return row.name;
                            },
                        );
                    });
                });
        }
    })(jQuery);
</script>
