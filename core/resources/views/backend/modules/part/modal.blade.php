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
        <label class="form-label text-sm mb-8">Part Code <span class="text-danger">*</span></label>
        <input type="text" name="part_code" class="form-control radius-8 text-uppercase" required maxlength="80"
               value="{{ $part->part_code ?? '' }}" placeholder="e.g. PT-PCB-001">
        <div class="invalid-feedback d-block part_code-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Barcode</label>
        <input type="text" name="barcode" class="form-control radius-8" maxlength="100"
               value="{{ $part->barcode ?? '' }}" placeholder="Optional unique barcode">
        <div class="invalid-feedback d-block barcode-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control radius-8" required maxlength="200"
               value="{{ $part->name ?? '' }}" placeholder="Part name">
        <div class="invalid-feedback d-block name-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Category <span class="text-danger">*</span></label>
        <select name="part_category_id"
                class="form-control js-s2-ajax"
                data-url="{{ route('inventory.part-categories.select2') }}"
                data-init-id="{{ $part?->part_category_id ?? '' }}"
                data-init-text="{{ $part?->category ? $part->category->name.' ('.$part->category->code.')' : '' }}"
                placeholder="Select part category" required></select>
        <div class="invalid-feedback d-block part_category_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Procurement Mode <span class="text-danger">*</span></label>
        <select name="procurement_mode" class="form-select js-s2" required placeholder="Select procurement mode">
          @foreach($procurementModes as $mode)
            <option value="{{ $mode }}" @selected(($part->procurement_mode ?? 'BUY') === $mode)>{{ ucfirst(strtolower($mode)) }}</option>
          @endforeach
        </select>
        <div class="invalid-feedback d-block procurement_mode-error" style="display:none"></div>
        <small class="text-muted">Make: internally produced, Buy: purchased, Both: either source.</small>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Base Unit <span class="text-danger">*</span></label>
        <select name="base_unit_id"
                class="form-control js-s2-ajax"
                data-url="{{ route('units.select2') }}"
                data-init-id="{{ $part?->base_unit_id ?? '' }}"
                data-init-text="{{ $part?->baseUnit ? $part->baseUnit->name.' ('.$part->baseUnit->code.')' : '' }}"
                placeholder="Select base unit" required></select>
        <div class="invalid-feedback d-block base_unit_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Brand</label>
        <select name="brand_id"
                class="form-control js-s2-ajax"
                data-url="{{ route('brand.brands.select2') }}"
                data-init-id="{{ $part?->brand_id ?? '' }}"
                data-init-text="{{ $part?->brand?->name ?? '' }}"
                placeholder="Select brand (optional)"></select>
        <div class="invalid-feedback d-block brand_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Specification</label>
        <textarea name="specification" class="form-control radius-8" rows="3" placeholder="Size, voltage, rating or other specification">{{ $part->specification ?? '' }}</textarea>
        <div class="invalid-feedback d-block specification-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Description</label>
        <textarea name="description" class="form-control radius-8" rows="3" placeholder="Optional notes">{{ $part->description ?? '' }}</textarea>
        <div class="invalid-feedback d-block description-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8" for="partImage">Image</label>
        <input type="file"
               id="partImage"
               name="image"
               class="form-control js-image-picker"
               accept="image/jpeg,image/png"
               data-existing-url="{{ $part?->image_path ? image($part->image_path) : '' }}"
               data-existing-key="{{ $part?->image_path ?? '' }}"
               data-existing-name="{{ $part?->image_path ? basename($part->image_path) : '' }}"
               data-remove-name="remove_image"
               data-max-size-kb="2048">
        <div class="invalid-feedback d-block image-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-8">
        <div class="form-switch switch-success d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" name="is_active" value="1" id="partActive"
                 {{ ($part->is_active ?? true) ? 'checked' : '' }}>
          <label class="form-check-label" for="partActive">Active</label>
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
