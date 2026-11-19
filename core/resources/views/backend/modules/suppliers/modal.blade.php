<form action="{{ $action }}" method="POST" data-ajax="true" data-modal-key="supplier-form">
  @csrf
  @if ($method === 'PUT')
    @method('PUT')
  @endif

  <div class="modal-header border-0 px-24 pt-20 pb-12">
    <div class="d-flex align-items-center gap-2">
      <div>
        <h5 class="modal-title mb-1">{{ $title }}</h5>
        <p class="text-muted text-sm mb-0">Maintain the supplier used by purchasing and item sourcing.</p>
      </div>
      <x-backend.field-note label="Supplier master rules">
        The same supplier record is used by Purchase Orders, Receipts, Returns, Payments and Supplier Mappings.
        A supplier already used by a transaction cannot be deleted; make it inactive instead.
      </x-backend.field-note>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>

  <div class="modal-body px-24 py-12">
    <div class="row g-3">
      <div class="col-md-8">
        <label class="form-label text-sm" for="supplierName">Supplier Name <span class="text-danger">*</span></label>
        <input
          type="text"
          name="name"
          id="supplierName"
          class="form-control form-control-sm radius-8"
          value="{{ $supplier->name }}"
          maxlength="150"
          autocomplete="organization"
          placeholder="Enter supplier name"
          required
        >
        <div class="invalid-feedback d-block name-error" style="display: none"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label text-sm" for="supplierCode">Supplier Code</label>
        <input
          type="text"
          name="code"
          id="supplierCode"
          class="form-control form-control-sm radius-8 text-uppercase"
          value="{{ $supplier->code }}"
          maxlength="50"
          placeholder="Optional code"
        >
        <div class="invalid-feedback d-block code-error" style="display: none"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label text-sm" for="supplierPhone">Phone</label>
        <input
          type="text"
          name="phone"
          id="supplierPhone"
          class="form-control form-control-sm radius-8"
          value="{{ $supplier->phone }}"
          maxlength="32"
          autocomplete="tel"
          placeholder="01XXXXXXXXX"
        >
        <div class="invalid-feedback d-block phone-error" style="display: none"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label text-sm" for="supplierEmail">Email</label>
        <input
          type="email"
          name="email"
          id="supplierEmail"
          class="form-control form-control-sm radius-8"
          value="{{ $supplier->email }}"
          maxlength="191"
          autocomplete="email"
          placeholder="Optional email"
        >
        <div class="invalid-feedback d-block email-error" style="display: none"></div>
      </div>

      <div class="col-md-8">
        <label class="form-label text-sm" for="supplierAddress">Address</label>
        <input
          type="text"
          name="address"
          id="supplierAddress"
          class="form-control form-control-sm radius-8"
          value="{{ $supplier->address }}"
          maxlength="255"
          autocomplete="street-address"
          placeholder="Optional address"
        >
        <div class="invalid-feedback d-block address-error" style="display: none"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label text-sm" for="supplierPostalCode">Postal Code</label>
        <input
          type="text"
          name="postal_code"
          id="supplierPostalCode"
          class="form-control form-control-sm radius-8"
          value="{{ $supplier->postal_code }}"
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
            id="supplierActive"
            @checked($supplier->is_active)
          >
          <label class="form-check-label" for="supplierActive">Supplier is active</label>
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
      <span>Save Supplier</span>
    </button>
  </div>
</form>
