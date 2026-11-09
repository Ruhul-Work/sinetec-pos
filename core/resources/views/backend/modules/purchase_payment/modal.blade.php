<div class="modal-header border-0 px-24 py-16">
    <h5 class="modal-title">New Supplier Payment</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
    <form method="POST" action="{{ route('purchase.payments.store') }}" data-ajax="true">
        @csrf

        <div class="row g-3 align-items-end">
            <div class="col-lg-8">
                <label class="form-label">Purchase Receipt <span class="text-danger">*</span></label>
                <select
                    name="purchase_receipt_id"
                    id="paymentReceipt"
                    class="form-select form-select-sm js-s2"
                    required
                >
                    <option value="">Select receipt</option>
                    @foreach ($receipts as $receipt)
                        <option value="{{ $receipt->id }}">
                            {{ $receipt->receipt_no }} — {{ $receipt->supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-lg-4">
                <label class="form-label">Current Due</label>
                <div
                    id="paymentDueBox"
                    class="form-control form-control-sm d-flex align-items-center justify-content-between bg-info-focus text-info-main"
                >
                    <span>Receipt Due</span>
                    <strong id="paymentDueAmount">—</strong>
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Payment Type <span class="text-danger">*</span></label>
                <select name="payment_type_id" class="form-select form-select-sm js-s2" required>
                    @foreach ($types as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Cash / Bank Account <span class="text-danger">*</span></label>
                <select name="account_id" class="form-select form-select-sm js-s2" required>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">
                            {{ $account->code }} — {{ $account->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                <input
                    type="date"
                    name="payment_date"
                    class="form-control form-control-sm"
                    value="{{ now()->toDateString() }}"
                    required
                >
            </div>

            <div class="col-md-4">
                <label class="form-label">Amount <span class="text-danger">*</span></label>
                <input
                    type="number"
                    name="amount"
                    id="paymentAmount"
                    class="form-control form-control-sm"
                    step="0.0001"
                    min="0.0001"
                    required
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">Reference No</label>
                <input name="reference_no" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label">Note</label>
                <input name="note" class="form-control form-control-sm">
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-24">
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm app-action-button"
                data-bs-dismiss="modal"
            >
                Cancel
            </button>
            <button class="btn btn-primary btn-sm px-18 py-8 radius-8 app-primary-action">
                Save Draft Payment
            </button>
        </div>
    </form>
</div>

<script>
    (function ($) {
        const modal = $('#AjaxModal');
        const dueUrl = @json(route('purchase.payments.due', '__id__'));

        S2.auto(modal);

        modal
            .off('change.purchasePayment', '#paymentReceipt')
            .on('change.purchasePayment', '#paymentReceipt', function () {
                const receiptId = $(this).val();

                if (!receiptId) {
                    $('#paymentDueAmount').text('—');
                    $('#paymentAmount').removeAttr('max');
                    return;
                }

                $.get(dueUrl.replace('__id__', receiptId), function (response) {
                    $('#paymentDueAmount').text(Number(response.due).toFixed(2));
                    $('#paymentAmount').attr('max', response.due);
                });
            });
    })(jQuery);
</script>
