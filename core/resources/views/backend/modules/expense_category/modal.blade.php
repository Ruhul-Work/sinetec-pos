<div class="modal-header py-16 px-24 border-0">
  <div>
    <h5 class="modal-title mb-1">{{ $title }}</h5>
    <p class="text-muted text-sm mb-0">Connect a general business cost with its accounting destination.</p>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body p-24 pt-8">
  <form action="{{ $action }}" method="post" data-ajax="true">
    @csrf
    @if ($method === 'PUT')
      @method('PUT')
    @endif

    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label text-sm">Code <span class="text-danger">*</span></label>
        <input
          type="text"
          name="code"
          class="form-control form-control-sm text-uppercase radius-8"
          value="{{ $category->code }}"
          maxlength="50"
          placeholder="e.g. OFFICE_RENT"
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
          placeholder="e.g. Office Rent"
          required
        >
        <div class="invalid-feedback d-block name-error" style="display: none"></div>
      </div>

      <div class="col-12">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0">
            Expense Account <span class="text-danger">*</span>
          </label>
          <x-backend.field-note label="Expense account selection rule">
            Only active child or leaf accounts under the Expense account type are available. Saving this
            category also assigns the selected account to every active branch.
          </x-backend.field-note>
        </div>
        <select name="expense_account_id" class="form-select form-select-sm js-s2" required>
          <option value="">Select expense account</option>
          @foreach ($accounts as $account)
            <option value="{{ $account->id }}" @selected((int) $category->expense_account_id === $account->id)>
              {{ $account->code }} — {{ $account->name }}
            </option>
          @endforeach
        </select>
        <div class="invalid-feedback d-block expense_account_id-error" style="display: none"></div>
      </div>

      <div class="col-12">
        <div class="form-switch switch-success d-flex align-items-center gap-3">
          <input type="hidden" name="is_active" value="0">
          <input
            class="form-check-input"
            type="checkbox"
            name="is_active"
            value="1"
            id="generalExpenseCategoryActive"
            @checked($category->is_active)
          >
          <label class="form-check-label" for="generalExpenseCategoryActive">Active</label>
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
