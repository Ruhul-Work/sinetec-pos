<div class="modal-header py-16 px-24 border-0">
  <h5 class="modal-title">{{ $title }}</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
  <form action="{{ $action }}" method="post" enctype="multipart/form-data" data-ajax="true">
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <div class="row">
      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Product Code <span class="text-danger">*</span></label>
        <input type="text" name="product_code" class="form-control radius-8 text-uppercase" required maxlength="80"
               value="{{ $product->product_code ?? '' }}" placeholder="e.g. FG-UPS-001">
        <div class="invalid-feedback d-block product_code-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Barcode</label>
        <input type="text" name="barcode" class="form-control radius-8" maxlength="100"
               value="{{ $product->barcode ?? '' }}" placeholder="Optional unique barcode">
        <div class="invalid-feedback d-block barcode-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control radius-8" required maxlength="200"
               value="{{ $product->name ?? '' }}" placeholder="Finished product name">
        <div class="invalid-feedback d-block name-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Category <span class="text-danger">*</span></label>
        <select name="finished_product_category_id" class="form-control js-s2-ajax"
                data-url="{{ route('inventory.finished-product-categories.select2') }}"
                data-init-id="{{ $product?->finished_product_category_id ?? '' }}"
                data-init-text="{{ $product?->category ? $product->category->name.' ('.$product->category->code.')' : '' }}"
                placeholder="Select finished product category" required></select>
        <div class="invalid-feedback d-block finished_product_category_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Base Unit <span class="text-danger">*</span></label>
        <select name="base_unit_id" class="form-control js-s2-ajax"
                data-url="{{ route('units.select2') }}"
                data-init-id="{{ $product?->base_unit_id ?? '' }}"
                data-init-text="{{ $product?->baseUnit ? $product->baseUnit->name.' ('.$product->baseUnit->code.')' : '' }}"
                placeholder="Select base unit" required></select>
        <div class="invalid-feedback d-block base_unit_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Brand</label>
        <select name="brand_id" class="form-control js-s2-ajax"
                data-url="{{ route('brand.brands.select2') }}"
                data-init-id="{{ $product?->brand_id ?? '' }}"
                data-init-text="{{ $product?->brand?->name ?? '' }}"
                placeholder="Select brand (optional)"></select>
        <div class="invalid-feedback d-block brand_id-error" style="display:none"></div>
      </div>

      <div class="col-md-3 mb-16">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">Default Retail Price</label>
          <x-backend.field-note label="Default retail price information">
            This is a default selling price only. Each sale and approved monthly report preserves its own price snapshot.
          </x-backend.field-note>
        </div>
        <input type="number" name="retail_price" class="form-control radius-8" min="0" step="0.0001"
               value="{{ $product?->retail_price ?? '' }}" placeholder="0.00">
        <div class="invalid-feedback d-block retail_price-error" style="display:none"></div>
      </div>

      <div class="col-md-3 mb-16">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">Default Wholesale Price</label>
          <x-backend.field-note label="Default wholesale price information" align="end">
            This is a default selling price only. Each sale and approved monthly report preserves its own price snapshot.
          </x-backend.field-note>
        </div>
        <input type="number" name="wholesale_price" class="form-control radius-8" min="0" step="0.0001"
               value="{{ $product?->wholesale_price ?? '' }}" placeholder="0.00">
        <div class="invalid-feedback d-block wholesale_price-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Specification</label>
        <textarea name="specification" class="form-control radius-8" rows="3" placeholder="Model, capacity, voltage or other specification">{{ $product->specification ?? '' }}</textarea>
        <div class="invalid-feedback d-block specification-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Description</label>
        <textarea name="description" class="form-control radius-8" rows="3" placeholder="Optional notes">{{ $product->description ?? '' }}</textarea>
        <div class="invalid-feedback d-block description-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8" for="finishedProductImage">Image</label>
        <input type="file" id="finishedProductImage" name="image" class="form-control js-image-picker"
               accept="image/jpeg,image/png"
               data-existing-url="{{ $product?->image_path ? image($product->image_path) : '' }}"
               data-existing-key="{{ $product?->image_path ?? '' }}"
               data-existing-name="{{ $product?->image_path ? basename($product->image_path) : '' }}"
               data-remove-name="remove_image" data-max-size-kb="2048">
        <div class="invalid-feedback d-block image-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-8">
        <div class="form-switch switch-success d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" name="is_active" value="1" id="finishedProductActive"
                 {{ ($product->is_active ?? true) ? 'checked' : '' }}>
          <label class="form-check-label" for="finishedProductActive">Active</label>
        </div>
        <div class="invalid-feedback d-block is_active-error" style="display:none"></div>
      </div>
    </div>

    <div class="d-flex align-items-center justify-content-center gap-3 mt-12">
      <button type="button" class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary px-48 py-12 radius-8">Save</button>
    </div>
  </form>
</div>
