<form action="{{ route('customer.store') }}" method="POST" data-ajax="true" data-modal-key="customer-create">
  @csrf

  <div class="modal-header border-0 px-24 pt-20 pb-12">
    <div>
      <h5 class="modal-title mb-1">Add Customer</h5>
      <p class="text-muted text-sm mb-0">Create a customer without leaving the current sale.</p>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>

  <div class="modal-body px-24 py-12">
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label" for="quickCustomerName">Customer Name <span class="text-danger">*</span></label>
        <input type="text" name="name" id="quickCustomerName" class="form-control"
               maxlength="150" autocomplete="name" placeholder="Enter customer name" required>
        <div class="invalid-feedback d-block name-error"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label" for="quickCustomerCode">Customer Code</label>
        <input type="text" name="code" id="quickCustomerCode" class="form-control"
               maxlength="50" placeholder="Optional code">
        <div class="invalid-feedback d-block code-error"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label" for="quickCustomerPhone">Phone <span class="text-danger">*</span></label>
        <input type="text" name="phone" id="quickCustomerPhone" class="form-control"
               maxlength="32" autocomplete="tel" placeholder="01XXXXXXXXX" required>
        <div class="invalid-feedback d-block phone-error"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label" for="quickCustomerAlternatePhone">Alternate Phone</label>
        <input type="text" name="alternate_phone" id="quickCustomerAlternatePhone"
               class="form-control" maxlength="32" placeholder="Optional">
        <div class="invalid-feedback d-block alternate_phone-error"></div>
      </div>

      <div class="col-md-7">
        <label class="form-label" for="quickCustomerEmail">Email</label>
        <input type="email" name="email" id="quickCustomerEmail" class="form-control"
               maxlength="191" autocomplete="email" placeholder="Optional email">
        <div class="invalid-feedback d-block email-error"></div>
      </div>

      <div class="col-md-5">
        <label class="form-label" for="quickCustomerBirthDate">Date of Birth</label>
        <input type="date" name="birth_date" id="quickCustomerBirthDate" class="form-control">
        <div class="invalid-feedback d-block birth_date-error"></div>
      </div>

      <div class="col-md-8">
        <label class="form-label" for="quickCustomerAddress">Address</label>
        <input type="text" name="address" id="quickCustomerAddress" class="form-control"
               maxlength="255" placeholder="Optional address">
        <div class="invalid-feedback d-block address-error"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label" for="quickCustomerPostalCode">Postal Code</label>
        <input type="text" name="postal_code" id="quickCustomerPostalCode" class="form-control"
               maxlength="20" placeholder="Optional">
        <div class="invalid-feedback d-block postal_code-error"></div>
      </div>

      <div class="col-12">
        <div class="form-switch switch-purple d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" name="is_active" value="1"
                 id="quickCustomerActive" checked>
          <label class="form-check-label" for="quickCustomerActive">Customer is active</label>
        </div>
      </div>
    </div>
  </div>

  <div class="modal-footer border-0 px-24 py-16">
    <button type="button" class="btn btn-outline-secondary px-18 py-9 radius-8" data-bs-dismiss="modal">
      Cancel
    </button>
    <button type="submit" class="btn btn-primary app-primary-action px-18 py-9 radius-8 app-action-button">
      <iconify-icon icon="mdi:account-plus-outline"></iconify-icon>
      <span>Save Customer</span>
    </button>
  </div>
</form>
