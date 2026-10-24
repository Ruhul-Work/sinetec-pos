<div class="modal-header py-16 px-24 border-0">
  <div>
    <h5 class="modal-title mb-1">{{ $title }}</h5>
    <p class="text-muted text-sm mb-0">Quantities are stored in the selected item's base unit.</p>
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
                data-init-id="{{ $level->item_id ?? '' }}"
                data-init-text="{{ $level->item_text ?? '' }}"
                placeholder="Select {{ strtolower($config['singular']) }}" required></select>
        <div class="invalid-feedback d-block item_id-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">Warehouse <span class="text-danger">*</span></label>
        <select name="warehouse_id" class="form-control js-s2-ajax"
                data-url="{{ route($config['route_name'].'.warehouses.select2') }}"
                data-init-id="{{ $level->warehouse_id ?? '' }}"
                data-init-text="{{ $level->warehouse_text ?? '' }}"
                placeholder="Select warehouse" required></select>
        <div class="invalid-feedback d-block warehouse_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Minimum Quantity <span class="text-danger">*</span></label>
        <input type="number" name="minimum_quantity" class="form-control"
               min="0.000001" step="0.000001" value="{{ $level->minimum_quantity ?? '' }}"
               placeholder="Low-stock threshold" required>
        <div class="invalid-feedback d-block minimum_quantity-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">Default Replenishment Qty</label>
          <x-backend.field-note label="Default replenishment quantity information">
            Leave blank when the quantity should come from future demand planning.
          </x-backend.field-note>
        </div>
        <input type="number" name="reorder_quantity" class="form-control"
               min="0" step="0.000001"
               value="{{ isset($level) && (float) $level->reorder_quantity > 0 ? $level->reorder_quantity : '' }}"
               placeholder="Optional fixed lot quantity">
        <div class="invalid-feedback d-block reorder_quantity-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-8">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">Maximum / Target Quantity</label>
          <x-backend.field-note label="Maximum quantity information">
            When provided, it must be equal to or greater than the minimum quantity.
          </x-backend.field-note>
        </div>
        <input type="number" name="maximum_quantity" class="form-control"
               min="0.000001" step="0.000001" value="{{ $level->maximum_quantity ?? '' }}"
               placeholder="Optional warehouse target ceiling">
        <div class="invalid-feedback d-block maximum_quantity-error" style="display:none"></div>
      </div>
    </div>

    <div class="d-flex align-items-center justify-content-center gap-3 mt-20">
      <button type="button" class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary px-48 py-12 radius-8">Save</button>
    </div>
  </form>
</div>
