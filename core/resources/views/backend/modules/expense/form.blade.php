<div class="modal-header py-16 px-24 border-0">
    <div>
        <h5 class="modal-title">New Expense Payment</h5>
        <small class="text-muted">Save a Draft first, then record it to create the finance Journal Draft</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form method="POST" action="{{ route('finance.expenses.store') }}" data-ajax="true">
    @csrf

    <div class="modal-body p-24">
        <div class="alert alert-light-info border-info-subtle text-sm py-10 mb-20">
            Direct payment only: Debit Expense and Credit Cash/Bank. Supplier or other payable expenses will use a
            separate due/payment flow later.
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <label class="form-label">Branch <span class="text-danger">*</span></label>
                <select
                    name="branch_id"
                    id="expenseBranch"
                    class="form-select form-select-sm js-s2"
                    @disabled(current_branch_id())
                    required
                >
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($branchId === (int) $branch->id)>
                            {{ $branch->name }} — {{ $branch->code }}
                        </option>
                    @endforeach
                </select>
                @if (current_branch_id())
                    <input type="hidden" name="branch_id" value="{{ current_branch_id() }}">
                @endif
            </div>

            <div class="col-lg-4">
                <label class="form-label">Expense Source <span class="text-danger">*</span></label>
                <select name="source_mode" id="expenseSourceMode" class="form-select form-select-sm js-s2" required>
                    <option value="PRODUCTION" @selected($selectedProductionExpenseId)>Production Cost Allocation</option>
                    <option value="GENERAL" @selected(! $selectedProductionExpenseId)>General / Period Expense</option>
                </select>
            </div>

            <div class="col-lg-4">
                <label class="form-label">Expense Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="expense_date"
                    class="form-control form-control-sm"
                    value="{{ old('expense_date', now()->toDateString()) }}"
                    required
                >
            </div>

            <div class="col-12 js-production-source-field">
                <label class="form-label">Unbooked Production Expense <span class="text-danger">*</span></label>
                <select
                    name="part_production_expense_id"
                    id="productionExpenseAllocation"
                    class="form-select form-select-sm js-s2"
                >
                    <option value="">Select completed batch expense</option>
                    @foreach ($options['production_expenses'] as $allocation)
                        <option
                            value="{{ $allocation->id }}"
                            data-amount="{{ $allocation->amount }}"
                            @selected((int) $selectedProductionExpenseId === (int) $allocation->id)
                        >
                            {{ $allocation->batch_no }} — {{ $allocation->part_name }} —
                            {{ $allocation->category_name }} — {{ number_format($allocation->amount, 2) }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">
                    Amount and expense account come from the completed production batch and cannot be changed here.
                </small>
            </div>

            <div class="col-lg-8 js-general-source-field">
                <label class="form-label">Expense Category <span class="text-danger">*</span></label>
                <select name="expense_category_id" id="generalExpenseCategory" class="form-select form-select-sm js-s2">
                    <option value="">Select expense category</option>
                    @foreach ($options['categories'] as $category)
                        <option value="{{ $category->id }}">
                            {{ $category->name }} — {{ $category->account_code }} {{ $category->account_name }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">General expenses are not included in production or inventory cost.</small>
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
                >
            </div>

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
                <label class="form-label">Payee</label>
                <input
                    type="text"
                    name="payee"
                    class="form-control form-control-sm"
                    maxlength="150"
                    placeholder="Person or organisation paid"
                >
            </div>

            <div class="col-lg-4">
                <label class="form-label">Reference No.</label>
                <input
                    type="text"
                    name="reference_no"
                    class="form-control form-control-sm"
                    maxlength="100"
                    placeholder="Voucher, cheque, bank reference"
                >
            </div>

            <div class="col-lg-8">
                <label class="form-label">Description</label>
                <input
                    type="text"
                    name="description"
                    class="form-control form-control-sm"
                    maxlength="5000"
                    placeholder="Optional payment note"
                >
            </div>
        </div>
    </div>

    <div class="modal-footer px-24 py-16 border-0 d-flex justify-content-end gap-2">
        <button
            type="button"
            class="btn btn-outline-secondary btn-sm px-16 py-8 radius-8 app-action-button"
            data-bs-dismiss="modal"
        >
            Cancel
        </button>
        <button
            type="submit"
            class="btn btn-primary btn-sm px-18 py-8 radius-8 app-primary-action d-inline-flex align-items-center gap-1"
        >
            <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
            <span>Save Draft</span>
        </button>
    </div>
</form>

<script>
    (function ($) {
        const $modal = $('#AjaxModal');
        const optionsUrl = @json(route('finance.expenses.branch-options', '__branch__'));

        function appendOptions($select, placeholder, rows, labelCallback) {
            $select.empty().append($('<option>').val('').text(placeholder));

            rows.forEach(function (row) {
                const $option = $('<option>').val(row.id).text(labelCallback(row));

                if (row.amount !== undefined) {
                    $option.attr('data-amount', row.amount);
                }

                $select.append($option);
            });

            $select.trigger('change');
        }

        function toggleSourceFields() {
            const isProduction = $('#expenseSourceMode').val() === 'PRODUCTION';
            const $amount = $('#expenseAmount');

            $('.js-production-source-field').toggleClass('d-none', !isProduction);
            $('.js-general-source-field').toggleClass('d-none', isProduction);
            $('#productionExpenseAllocation').prop('required', isProduction);
            $('#generalExpenseCategory').prop('required', !isProduction);
            $amount.prop('readonly', isProduction);
            $amount.prop('required', !isProduction);

            if (isProduction) {
                const amount = $('#productionExpenseAllocation option:selected').data('amount') || '';
                $amount.val(amount);
            } else {
                $amount.val('');
            }
        }

        S2.auto($modal);
        toggleSourceFields();

        $modal
            .off('change.expensePayment', '#expenseSourceMode')
            .on('change.expensePayment', '#expenseSourceMode', toggleSourceFields)
            .off('change.expensePaymentAllocation', '#productionExpenseAllocation')
            .on('change.expensePaymentAllocation', '#productionExpenseAllocation', toggleSourceFields)
            .off('change.expensePaymentBranch', '#expenseBranch')
            .on('change.expensePaymentBranch', '#expenseBranch', function () {
                const branchId = $(this).val();

                if (!branchId) {
                    return;
                }

                $.get(optionsUrl.replace('__branch__', branchId), function (response) {
                    appendOptions(
                        $('#productionExpenseAllocation'),
                        'Select completed batch expense',
                        response.production_expenses || [],
                        function (row) {
                            return `${row.batch_no} — ${row.part_name} — ${row.category_name} — ${Number(row.amount).toFixed(2)}`;
                        },
                    );
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
                    toggleSourceFields();
                });
            });
    })(jQuery);
</script>
