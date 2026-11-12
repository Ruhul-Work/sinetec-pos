<div class="modal-header py-16 px-24 border-0">
  <div>
    <h5 class="modal-title mb-1">{{ $title }}</h5>
    <p class="text-muted text-sm mb-0">Classify direct costs used while preparing a production batch.</p>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body p-24 pt-8">
  <form action="{{ $action }}" method="post" data-ajax="true">
    @csrf
    @if ($method === 'PUT')
      @method('PUT')
    @endif

    <div class="alert alert-light-info border-info-subtle text-sm py-10 mb-20">
      Production cost categories do not need individual ledger accounts. All unpaid direct production costs use
      the Production Expense Payable control account configured in Finance Account Mapping.
    </div>

    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label text-sm">Code <span class="text-danger">*</span></label>
        <input
          type="text"
          name="code"
          class="form-control form-control-sm text-uppercase radius-8"
          value="{{ $category->code }}"
          maxlength="50"
          placeholder="e.g. DIRECT_LABOUR"
          required
        >
        <div class="invalid-feedback d-block code-error" style="display: none"></div>
      </div>

      <div class="col-md-7">
        <label class="form-label text-sm">Category Name <span class="text-danger">*</span></label>
        <input
          type="text"
          name="name"
          class="form-control form-control-sm radius-8"
          value="{{ $category->name }}"
          maxlength="120"
          placeholder="e.g. Direct Labour"
          required
        >
        <div class="invalid-feedback d-block name-error" style="display: none"></div>
      </div>

      <div class="col-12">
        <div class="form-switch switch-success d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input
            class="form-check-input"
            type="checkbox"
            name="is_active"
            value="1"
            id="productionExpenseCategoryActive"
            @checked($category->is_active)
          >
          <label class="form-check-label" for="productionExpenseCategoryActive">Active</label>
        </div>
        <div class="invalid-feedback d-block is_active-error" style="display: none"></div>
      </div>
    </div>

    <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
      <button
        type="button"
        class="btn border border-danger-600 text-danger-600 px-36 py-10 radius-8 app-action-button"
        data-bs-dismiss="modal"
      >
        <span>Cancel</span>
      </button>
      <button type="submit" class="btn btn-primary px-44 py-10 radius-8 app-action-button app-primary-action">
        <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
        <span>Save Category</span>
      </button>
    </div>
  </form>
</div>
