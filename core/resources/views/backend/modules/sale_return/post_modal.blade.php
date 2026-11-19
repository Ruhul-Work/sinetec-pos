@php
    $refundRequired = (float) $summary['refund_amount'] > 0;
@endphp

<div class="modal-header border-0 px-24 py-16">
    <div>
        <h5 class="modal-title mb-1">Post Sales Return</h5>
        <div class="text-muted text-sm">
            {{ $saleReturn->return_no }} · {{ $saleReturn->sale->sale_no }}
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
    <form method="POST" action="{{ route('sales.returns.post', $saleReturn) }}" data-ajax="true">
        @csrf

        <div class="row g-3 mb-20">
            <div class="col-md-4">
                <div class="border radius-8 p-12 h-100 bg-primary-50">
                    <span class="text-muted text-sm d-block">Return Amount</span>
                    <strong>{{ number_format((float) $summary['total'], 2) }}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border radius-8 p-12 h-100 bg-warning-50">
                    <span class="text-muted text-sm d-block">Due Adjustment</span>
                    <strong>{{ number_format((float) $summary['due_adjustment'], 2) }}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border radius-8 p-12 h-100 bg-success-50">
                    <span class="text-muted text-sm d-block">Customer Refund</span>
                    <strong>{{ number_format((float) $summary['refund_amount'], 2) }}</strong>
                </div>
            </div>
        </div>

        @if ($refundRequired)
            <div class="d-flex align-items-center justify-content-between gap-2 mb-12">
                <div>
                    <h6 class="mb-0">Refund Methods</h6>
                    <small class="text-muted">The refund can be split across Cash, Bank or MFS accounts.</small>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm app-action-button"
                    id="addSaleReturnRefund">
                    <iconify-icon icon="mdi:plus"></iconify-icon>
                    <span>Add Method</span>
                </button>
            </div>

            <div class="border radius-8 overflow-hidden">
                <div class="row g-2 px-12 py-10 bg-light fw-semibold text-sm d-none d-md-flex">
                    <div class="col-md-3">Method</div>
                    <div class="col-md-3">Pay From Account</div>
                    <div class="col-md-2">Amount</div>
                    <div class="col-md-3">Reference</div>
                    <div class="col-md-1 text-center">Action</div>
                </div>
                <div id="saleReturnRefundRows"></div>
            </div>

            <div class="d-flex justify-content-end mt-12">
                <div class="text-end">
                    <span class="text-muted text-sm d-block">Remaining to Allocate</span>
                    <strong class="text-danger" id="saleReturnRefundRemaining">
                        {{ number_format((float) $summary['refund_amount'], 2) }}
                    </strong>
                </div>
            </div>
        @else
            <div class="alert alert-info mb-0">
                This return will reduce the customer's outstanding due. No cash or bank refund is required.
            </div>
        @endif

        <div class="alert alert-warning mt-20 mb-0">
            Posting is final: returned quantity will enter stock, customer balance will adjust and Finance will post.
        </div>

        <div class="d-flex justify-content-end gap-2 mt-24">
            <button type="button" class="btn btn-outline-secondary btn-sm app-action-button"
                data-bs-dismiss="modal">
                Cancel
            </button>
            <button class="btn btn-success btn-sm px-18 py-8 radius-8 app-action-button"
                id="postSaleReturnButton">
                <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon>
                <span>Confirm & Post</span>
            </button>
        </div>
    </form>
</div>

@if ($refundRequired)
    <template id="saleReturnRefundTemplate">
        <div class="sale-return-refund-row row g-2 align-items-center px-12 py-10 border-top">
            <div class="col-md-3">
                <label class="form-label d-md-none">Method</label>
                <select class="form-select form-select-sm js-s2 refund-method" required>
                    <option value="">Select method</option>
                    @foreach ($paymentTypes as $paymentType)
                        <option value="{{ $paymentType->id }}">{{ $paymentType->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label d-md-none">Pay From Account</label>
                <select class="form-select form-select-sm js-s2 refund-account" required>
                    <option value="">Select account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label d-md-none">Amount</label>
                <input type="number" class="form-control form-control-sm refund-amount"
                    min="0.0001" step="0.0001" required>
            </div>
            <div class="col-md-3">
                <label class="form-label d-md-none">Reference</label>
                <input type="text" class="form-control form-control-sm refund-reference" maxlength="100">
            </div>
            <div class="col-md-1 d-flex justify-content-center">
                <button type="button"
                    class="btn w-32-px h-32-px rounded-circle bg-danger-focus text-danger-main
                        d-inline-flex align-items-center justify-content-center remove-sale-return-refund"
                    title="Remove refund row">
                    <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
                </button>
            </div>
        </div>
    </template>

    <script>
        (function($) {
            const modal = $('#AjaxModal');
            const rows = modal.find('#saleReturnRefundRows');
            const template = modal.find('#saleReturnRefundTemplate')[0];
            const refundRequired = Number(@json($summary['refund_amount']));

            function refreshNames() {
                rows.find('.sale-return-refund-row').each(function(index) {
                    const row = $(this);
                    row.find('.refund-method').attr('name', `payments[${index}][payment_type_id]`);
                    row.find('.refund-account').attr('name', `payments[${index}][account_id]`);
                    row.find('.refund-amount').attr('name', `payments[${index}][amount]`);
                    row.find('.refund-reference').attr('name', `payments[${index}][reference_no]`);
                });
            }

            function updateSummary() {
                let allocated = 0;
                rows.find('.refund-amount').each(function() {
                    allocated += Number($(this).val()) || 0;
                });

                const remaining = refundRequired - allocated;
                modal.find('#saleReturnRefundRemaining')
                    .text(remaining.toFixed(2))
                    .toggleClass('text-success', Math.abs(remaining) < 0.0001)
                    .toggleClass('text-danger', Math.abs(remaining) >= 0.0001);
                modal.find('#postSaleReturnButton').prop('disabled', Math.abs(remaining) >= 0.0001);
            }

            function addRow(initialAmount = '') {
                const row = $(template.content.cloneNode(true));
                row.find('.refund-amount').val(initialAmount);
                rows.append(row);
                refreshNames();
                S2.auto(modal);
                updateSummary();
            }

            modal.off('click.saleReturnRefund', '#addSaleReturnRefund')
                .on('click.saleReturnRefund', '#addSaleReturnRefund', function() {
                    addRow();
                });
            modal.off('click.saleReturnRefund', '.remove-sale-return-refund')
                .on('click.saleReturnRefund', '.remove-sale-return-refund', function() {
                    if (rows.find('.sale-return-refund-row').length === 1) {
                        return;
                    }

                    $(this).closest('.sale-return-refund-row').remove();
                    refreshNames();
                    updateSummary();
                });
            modal.off('input.saleReturnRefund', '.refund-amount')
                .on('input.saleReturnRefund', '.refund-amount', updateSummary);

            addRow(refundRequired.toFixed(2));
        })(jQuery);
    </script>
@endif
