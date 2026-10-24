<div class="modal-header py-16 px-24 border-0">
  <div>
    <h5 class="modal-title mb-1">{{ $title }}</h5>
    <p class="text-muted text-sm mb-0">Link an approved supplier and record expected delivery information.</p>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
  <form action="{{ $action }}" method="post" data-ajax="true">
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <div class="row">
      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">{{ $config['singular'] }} <span class="text-danger">*</span></label>
        <select name="item_id" class="form-control js-s2-ajax"
                data-url="{{ route($config['route_name'].'.items.select2') }}"
                data-init-id="{{ $mapping->item_id ?? '' }}"
                data-init-text="{{ $mapping->item_text ?? '' }}"
                placeholder="Select {{ strtolower($config['singular']) }}" required></select>
        <div class="invalid-feedback d-block item_id-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">Supplier <span class="text-danger">*</span></label>
        <select name="supplier_id" class="form-control js-s2-ajax"
                data-url="{{ route($config['route_name'].'.suppliers.select2') }}"
                data-init-id="{{ $mapping->supplier_id ?? '' }}"
                data-init-text="{{ $mapping->supplier_text ?? '' }}"
                placeholder="Select supplier" required></select>
        <div class="invalid-feedback d-block supplier_id-error" style="display:none"></div>
      </div>

      <div class="col-md-7 mb-16">
        <label class="form-label text-sm mb-8">Supplier Item Code</label>
        <input type="text" name="supplier_code" class="form-control"
               maxlength="80" value="{{ $mapping->supplier_code ?? '' }}"
               placeholder="Supplier's code for this item">
        <div class="invalid-feedback d-block supplier_code-error" style="display:none"></div>
      </div>

      <div class="col-md-5 mb-16">
        <label class="form-label text-sm mb-8">Lead Time (Days) <span class="text-danger">*</span></label>
        <input type="number" name="lead_days" class="form-control"
               min="0" max="3650" step="1" value="{{ $mapping->lead_days ?? 0 }}" required>
        <div class="invalid-feedback d-block lead_days-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-8">
        <input type="hidden" name="is_preferred" value="0">
        <div class="form-switch switch-primary d-flex align-items-center gap-3">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="supplierMappingPreferred" name="is_preferred" value="1"
                 @checked((bool) ($mapping->is_preferred ?? false))>
          <label class="form-check-label fw-medium" for="supplierMappingPreferred">Preferred supplier for this item</label>
          <x-backend.field-note label="Preferred supplier information">
            Selecting this will replace the item's previous preferred supplier.
          </x-backend.field-note>
        </div>
        <div class="invalid-feedback d-block is_preferred-error" style="display:none"></div>
      </div>
    </div>

    <div class="d-flex align-items-center justify-content-center gap-3 mt-20">
      <button type="button" class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary px-48 py-12 radius-8">Save</button>
    </div>
  </form>
</div>
