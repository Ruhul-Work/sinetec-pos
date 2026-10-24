<div class="modal-header py-16 px-24 border-0">
  <h5 class="modal-title">{{ $title }}</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body p-24">
  <form action="{{ $action }}" method="post" data-ajax="true">
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <div class="row">
      <div class="col-md-5 mb-16">
        <label class="form-label text-sm mb-8">Code <span class="text-danger">*</span></label>
        <input type="text" name="code" class="form-control radius-8 text-uppercase" required maxlength="50"
               value="{{ $category->code ?? '' }}" placeholder="e.g. PCB">
        <div class="invalid-feedback d-block code-error" style="display:none"></div>
      </div>

      <div class="col-md-7 mb-16">
        <label class="form-label text-sm mb-8">Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control radius-8" required maxlength="150"
               value="{{ $category->name ?? '' }}" placeholder="Category name">
        <div class="invalid-feedback d-block name-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">Parent Category</label>
          <x-backend.field-note label="Parent category information">
            Leave empty to create a root category.
          </x-backend.field-note>
        </div>
        <select name="parent_id"
                class="form-control js-s2-ajax"
                data-url="{{ route($routePrefix.'.select2', ['exclude' => $category?->id]) }}"
                data-init-id="{{ $category?->parent_id ?? '' }}"
                data-init-text="{{ $category?->parent ? $category->parent->name.' ('.$category->parent->code.')' : '' }}"
                placeholder="Select parent category (optional)"></select>
        <div class="invalid-feedback d-block parent_id-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-16">
        <label class="form-label text-sm mb-8">Description</label>
        <textarea name="description" class="form-control radius-8" rows="3" placeholder="Optional description">{{ $category->description ?? '' }}</textarea>
        <div class="invalid-feedback d-block description-error" style="display:none"></div>
      </div>

      <div class="col-12 mb-8">
        <div class="form-switch switch-success d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input class="form-check-input" type="checkbox" name="is_active" value="1" id="inventoryCategoryActive"
                 {{ ($category->is_active ?? true) ? 'checked' : '' }}>
          <label class="form-check-label" for="inventoryCategoryActive">Active</label>
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
