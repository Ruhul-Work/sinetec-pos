<div class="modal-header py-16 px-24 border-0">
  <div>
    <div class="d-flex align-items-center gap-2 mb-1">
      <h5 class="modal-title mb-0">Extend BOM Validity</h5>
      <x-backend.field-note label="How validity extension works">
        This action changes only the expiry date. The Part, version, output quantity and recipe components remain
        locked so historical production records stay unchanged.
      </x-backend.field-note>
    </div>
    <p class="text-muted text-sm mb-0">
      {{ $partBom->part?->name }} ({{ $partBom->part?->part_code }}) — {{ $partBom->version }}
    </p>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<div class="modal-body p-24 pt-8">
  <form
    action="{{ route('production.part-boms.extend-validity', $partBom) }}"
    method="post"
    data-ajax="true"
  >
    @csrf
    @method('PATCH')

    <div class="row g-3">
      <div class="col-md-6">
        <span class="text-muted text-sm d-block mb-1">Current Effective To</span>
        <strong>{{ $partBom->effective_to?->format('d M Y') }}</strong>
      </div>

      <div class="col-md-6">
        <span class="text-muted text-sm d-block mb-1">Current State</span>
        @if ($partBom->isExpired())
          <span class="badge text-sm fw-semibold bg-dark-danger-gradient text-white px-12 py-7 radius-4">
            Expired
          </span>
        @else
          <span class="badge text-sm fw-semibold bg-dark-success-gradient text-white px-12 py-7 radius-4">
            Valid
          </span>
        @endif
      </div>

      <div class="col-12">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label text-sm mb-0" for="partBomEffectiveTo">New Effective To</label>
          <x-backend.field-note label="Validity date rule">
            Choose a date after the current expiry date. Leave it blank when this recipe should remain valid
            without a scheduled expiry.
          </x-backend.field-note>
        </div>
        <input
          type="date"
          name="effective_to"
          id="partBomEffectiveTo"
          class="form-control form-control-sm radius-8"
          min="{{ now()->toDateString() }}"
        >
        <div class="invalid-feedback d-block effective_to-error" style="display: none"></div>
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
      <button
        type="submit"
        class="btn btn-primary px-40 py-10 radius-8 app-action-button app-primary-action"
      >
        <iconify-icon icon="mdi:calendar-refresh-outline"></iconify-icon>
        <span>Extend Validity</span>
      </button>
    </div>
  </form>
</div>
