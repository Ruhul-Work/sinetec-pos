<div class="modal-header py-16 px-24 border-0">
  <div>
    <h5 class="modal-title mb-1">{{ $title }}</h5>
    <p class="text-muted text-sm mb-0">Add an alternative unit; the item's base unit remains implicit at factor 1.</p>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
  <form action="{{ $action }}" method="post" data-ajax="true" data-domain-unit-form
        data-item-info-base="{{ url('inventory/item-units/'.$domain.'/item') }}">
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <div class="row">
      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">{{ $config['singular'] }} <span class="text-danger">*</span></label>
        <select name="item_id" class="form-control js-s2-ajax"
                data-url="{{ route($config['item_select_route']) }}"
                data-init-id="{{ $mapping->item_id ?? '' }}"
                data-init-text="{{ $mapping->item_text ?? '' }}"
                placeholder="Select {{ strtolower($config['singular']) }}" required></select>
        <div class="invalid-feedback d-block item_id-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <div class="alert alert-light border mb-0 py-10 px-12">
          <span class="text-muted">Base Unit:</span>
          <strong data-base-unit-text>{{ $mapping->base_unit_text ?? 'Select an item to see its base unit.' }}</strong>
        </div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Alternative Unit <span class="text-danger">*</span></label>
        <select name="unit_id" class="form-control js-s2-ajax"
                data-url="{{ route('units.select2') }}"
                data-init-id="{{ $mapping->unit_id ?? '' }}"
                data-init-text="{{ $mapping->unit_text ?? '' }}"
                placeholder="Select alternative unit" required></select>
        <div class="invalid-feedback d-block unit_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6 mb-16">
        <label class="form-label text-sm mb-8">Usage <span class="text-danger">*</span></label>
        <select name="usage_type" class="form-select js-s2" required placeholder="Select usage">
          @foreach($config['usages'] as $usage)
            <option value="{{ $usage }}" @selected(($mapping->usage_type ?? 'ALL') === $usage)>
              {{ $usage === 'ALL' ? 'All Applicable' : ucfirst(strtolower($usage)) }}
            </option>
          @endforeach
        </select>
        <div class="invalid-feedback d-block usage_type-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">Conversion to Base Unit <span class="text-danger">*</span></label>
          <x-backend.field-note label="Item conversion example">
            Example: if 1 BOX contains 12 PCS, enter 12.
          </x-backend.field-note>
        </div>
        <div class="input-group">
          <span class="input-group-text">1 alternative unit =</span>
          <input type="number" name="conversion_to_base" class="form-control" required
                 min="0.000001" max="99999999999999.999999" step="0.000001"
                 value="{{ $mapping->conversion_to_base ?? '' }}" placeholder="e.g. 12">
          <span class="input-group-text">base units</span>
        </div>
        <div class="invalid-feedback d-block conversion_to_base-error" style="display:none"></div>
      </div>
    </div>

    <div class="d-flex align-items-center justify-content-center gap-3 mt-12">
      <button type="button" class="btn border border-danger-600 text-danger-600 px-40 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary px-48 py-12 radius-8">Save</button>
    </div>
  </form>
</div>
