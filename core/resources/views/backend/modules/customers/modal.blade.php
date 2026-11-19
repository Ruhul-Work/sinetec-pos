<form action="{{ $action }}" method="POST" data-ajax="true" data-modal-key="customer-form">
  @csrf
  @if ($method === 'PUT')
    @method('PUT')
  @endif

  <div class="modal-header border-0 px-24 pt-20 pb-12">
    <div class="d-flex align-items-center gap-2">
      <div>
        <h5 class="modal-title mb-1">{{ $title }}</h5>
        <p class="text-muted text-sm mb-0">Maintain the customer used by POS, Wholesale and Receivables.</p>
      </div>
      <x-backend.field-note label="Customer master rules">
        The same customer record is used by POS, Wholesale Sales, Due Collection and Sales Return.
        A customer already used by a transaction cannot be deleted; make it inactive instead.
      </x-backend.field-note>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>

  <div class="modal-body px-24 py-12">
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label text-sm" for="customerName">Customer Name <span class="text-danger">*</span></label>
        <input
          type="text"
          name="name"
          id="customerName"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->name }}"
          maxlength="150"
          autocomplete="name"
          placeholder="Enter customer name"
          required
        >
        <div class="invalid-feedback d-block name-error" style="display: none"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label text-sm" for="customerCode">Customer Code</label>
        <input
          type="text"
          name="code"
          id="customerCode"
          class="form-control form-control-sm radius-8 text-uppercase"
          value="{{ $customer->code }}"
          maxlength="50"
          placeholder="Optional code"
        >
        <div class="invalid-feedback d-block code-error" style="display: none"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label text-sm" for="customerPhone">Phone <span class="text-danger">*</span></label>
        <input
          type="text"
          name="phone"
          id="customerPhone"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->phone }}"
          maxlength="32"
          autocomplete="tel"
          placeholder="01XXXXXXXXX"
          required
        >
        <div class="invalid-feedback d-block phone-error" style="display: none"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label text-sm" for="customerAlternatePhone">Alternate Phone</label>
        <input
          type="text"
          name="alternate_phone"
          id="customerAlternatePhone"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->alternate_phone }}"
          maxlength="32"
          placeholder="Optional"
        >
        <div class="invalid-feedback d-block alternate_phone-error" style="display: none"></div>
      </div>

      <div class="col-md-7">
        <label class="form-label text-sm" for="customerEmail">Email</label>
        <input
          type="email"
          name="email"
          id="customerEmail"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->email }}"
          maxlength="191"
          autocomplete="email"
          placeholder="Optional email"
        >
        <div class="invalid-feedback d-block email-error" style="display: none"></div>
      </div>

      <div class="col-md-5">
        <label class="form-label text-sm" for="customerBirthDate">Date of Birth</label>
        <input
          type="date"
          name="birth_date"
          id="customerBirthDate"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->birth_date?->format('Y-m-d') }}"
        >
        <div class="invalid-feedback d-block birth_date-error" style="display: none"></div>
      </div>

      <div class="col-md-8">
        <label class="form-label text-sm" for="customerAddress">Address</label>
        <input
          type="text"
          name="address"
          id="customerAddress"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->address }}"
          maxlength="255"
          autocomplete="street-address"
          placeholder="Optional address"
        >
        <div class="invalid-feedback d-block address-error" style="display: none"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label text-sm" for="customerPostalCode">Postal Code</label>
        <input
          type="text"
          name="postal_code"
          id="customerPostalCode"
          class="form-control form-control-sm radius-8"
          value="{{ $customer->postal_code }}"
          maxlength="20"
          placeholder="Optional"
        >
        <div class="invalid-feedback d-block postal_code-error" style="display: none"></div>
      </div>

      <div class="col-12">
        <div class="form-switch switch-success d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input
            class="form-check-input"
            type="checkbox"
            name="is_active"
            value="1"
            id="customerActive"
            @checked($customer->is_active)
          >
          <label class="form-check-label" for="customerActive">Customer is active</label>
        </div>
        <div class="invalid-feedback d-block is_active-error" style="display: none"></div>
      </div>
    </div>
  </div>

  <div class="modal-footer border-0 px-24 py-16">
    <button type="button" class="btn btn-outline-secondary px-18 py-9 radius-8 app-action-button" data-bs-dismiss="modal">
      <span>Cancel</span>
    </button>
    <button type="submit" class="btn btn-primary app-primary-action px-22 py-9 radius-8 app-action-button">
      <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
      <span>Save Customer</span>
    </button>
  </div>
</form>
