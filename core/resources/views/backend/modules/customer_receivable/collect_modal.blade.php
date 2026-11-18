<div class="modal-header border-0 px-24 py-16">
  <div>
    <h5 class="modal-title mb-1">Collect Customer Due</h5>
    <div class="text-muted text-sm">{{ $sale->sale_no }} · {{ $sale->customer->name }}</div>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
  <form method="POST" action="{{ route('sales.customer-receivables.collect', $sale) }}" data-ajax="true">
    @csrf

    <div class="row g-3 mb-20">
      <div class="col-md-4">
        <div class="border radius-8 p-12 h-100 bg-primary-50">
          <span class="text-muted text-sm d-block">Sale Total</span>
          <strong>{{ number_format((float) $sale->total, 2) }}</strong>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border radius-8 p-12 h-100 bg-success-50">
          <span class="text-muted text-sm d-block">Already Paid</span>
          <strong class="text-success">{{ number_format((float) $sale->paid_amount, 2) }}</strong>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border radius-8 p-12 h-100 bg-danger-50">
          <span class="text-muted text-sm d-block">Current Due</span>
          <strong class="text-danger" id="customerReceiptCurrentDue" data-due="{{ $sale->due_amount }}">
            {{ number_format((float) $sale->due_amount, 2) }}
          </strong>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-20 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Collection Date & Time <span class="text-danger">*</span></label>
        <input type="datetime-local" name="receipt_at" class="form-control form-control-sm"
               value="{{ now()->format('Y-m-d\TH:i') }}" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Collection Note</label>
        <input type="text" name="note" class="form-control form-control-sm" maxlength="5000"
               placeholder="Optional note">
      </div>
    </div>

    <div class="d-flex align-items-center justify-content-between gap-2 mb-12">
      <div>
        <h6 class="mb-0">Payment Methods</h6>
        <small class="text-muted">Cash, Bank and MFS can be split across multiple rows.</small>
      </div>
      <button type="button" class="btn btn-outline-primary btn-sm app-action-button" id="addCustomerReceiptPayment">
        <iconify-icon icon="mdi:plus"></iconify-icon>
        <span>Add Method</span>
      </button>
    </div>

    <div class="border radius-8 overflow-hidden">
      <div class="row g-2 px-12 py-10 bg-light fw-semibold text-sm d-none d-md-flex">
        <div class="col-md-3">Method</div>
        <div class="col-md-3">Receiving Account</div>
        <div class="col-md-2">Amount</div>
        <div class="col-md-3">Reference</div>
        <div class="col-md-1 text-center">Action</div>
      </div>
      <div id="customerReceiptPaymentRows"></div>
    </div>

    <div class="d-flex justify-content-end mt-12">
      <div class="d-flex align-items-center gap-4">
        <div class="text-end">
          <span class="text-muted text-sm d-block">Estimated Change</span>
          <strong class="text-primary" id="customerReceiptChange">0.00</strong>
        </div>
        <div class="text-end">
          <span class="text-muted text-sm d-block">Remaining Due After Collection</span>
          <strong class="text-danger" id="customerReceiptRemainingDue">
            {{ number_format((float) $sale->due_amount, 2) }}
          </strong>
        </div>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-24">
      <button type="button" class="btn btn-outline-secondary btn-sm app-action-button" data-bs-dismiss="modal">
        Cancel
      </button>
      <button class="btn btn-success btn-sm px-18 py-8 radius-8 app-action-button">
        <iconify-icon icon="mdi:cash-check" class="text-lg"></iconify-icon>
        <span>Collect & Post</span>
      </button>
    </div>
  </form>
</div>

<template id="customerReceiptPaymentTemplate">
  <div class="customer-receipt-payment-row row g-2 align-items-center px-12 py-10 border-top">
    <div class="col-md-3">
      <label class="form-label d-md-none">Method</label>
      <select class="form-select form-select-sm js-s2 payment-method" required>
        <option value="">Select method</option>
        @foreach ($paymentTypes as $paymentType)
          <option value="{{ $paymentType->id }}" data-allows-change="{{ $paymentType->allows_change ? 1 : 0 }}">
            {{ $paymentType->name }}
          </option>
        @endforeach
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label d-md-none">Receiving Account</label>
      <select class="form-select form-select-sm js-s2 payment-account" required>
        <option value="">Select account</option>
        @foreach ($accounts as $account)
          <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label d-md-none">Amount</label>
      <input type="number" class="form-control form-control-sm payment-amount" min="0.0001" step="0.0001" required>
    </div>
    <div class="col-md-3">
      <label class="form-label d-md-none">Reference</label>
      <input type="text" class="form-control form-control-sm payment-reference" maxlength="100">
    </div>
    <div class="col-md-1 d-flex justify-content-center">
      <button type="button" class="btn w-32-px h-32-px rounded-circle bg-danger-focus text-danger-main
                     d-inline-flex align-items-center justify-content-center remove-customer-receipt-payment"
              title="Remove payment row">
        <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
      </button>
    </div>
  </div>
</template>

<script>
  (function($) {
    const modal = $('#AjaxModal');
    const rows = modal.find('#customerReceiptPaymentRows');
    const template = modal.find('#customerReceiptPaymentTemplate')[0];
    const currentDue = Number(modal.find('#customerReceiptCurrentDue').data('due')) || 0;

    function refreshNames() {
      rows.find('.customer-receipt-payment-row').each(function(index) {
        const row = $(this);
        row.find('.payment-method').attr('name', `payments[${index}][payment_type_id]`);
        row.find('.payment-account').attr('name', `payments[${index}][account_id]`);
        row.find('.payment-amount').attr('name', `payments[${index}][amount]`);
        row.find('.payment-reference').attr('name', `payments[${index}][reference_no]`);
      });
    }

    function updateSummary() {
      let tendered = 0;
      rows.find('.payment-amount').each(function() {
        tendered += Number($(this).val()) || 0;
      });
      const applied = Math.min(tendered, currentDue);
      modal.find('#customerReceiptRemainingDue').text(Math.max(currentDue - applied, 0).toFixed(2));
      modal.find('#customerReceiptChange').text(Math.max(tendered - currentDue, 0).toFixed(2));
    }

    function addRow() {
      const row = $(template.content.cloneNode(true));
      rows.append(row);
      refreshNames();
      S2.auto(modal);
    }

    modal.off('click.customerReceipt', '#addCustomerReceiptPayment')
      .on('click.customerReceipt', '#addCustomerReceiptPayment', addRow);
    modal.off('click.customerReceipt', '.remove-customer-receipt-payment')
      .on('click.customerReceipt', '.remove-customer-receipt-payment', function() {
        if (rows.find('.customer-receipt-payment-row').length === 1) {
          return;
        }
        $(this).closest('.customer-receipt-payment-row').remove();
        refreshNames();
        updateSummary();
      });
    modal.off('input.customerReceipt', '.payment-amount')
      .on('input.customerReceipt', '.payment-amount', updateSummary);

    addRow();
  })(jQuery);
</script>
