<div class="modal-header">
  <div>
    <h5 class="modal-title mb-1">{{ $title }}</h5>
    <p class="text-muted text-sm mb-0">Define how many To Units equal one From Unit.</p>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<form action="{{ $action }}" method="post" data-ajax="true">
  @csrf
  @if($method === 'PUT') @method('PUT') @endif

  <div class="modal-body">
    <div class="row gy-3">
      <div class="col-md-6">
        <label class="form-label">From Unit <span class="text-danger">*</span></label>
        <select name="from_unit_id" class="form-select" required>
          <option value="">Select unit</option>
          @foreach($units as $unit)
            <option value="{{ $unit->id }}" @selected((int) old('from_unit_id', $conversion->from_unit_id) === $unit->id)>
              {{ $unit->name }} ({{ $unit->code }}){{ $unit->is_active ? '' : ' — Inactive' }}
            </option>
          @endforeach
        </select>
        <div class="invalid-feedback d-block from_unit_id-error" style="display:none"></div>
      </div>

      <div class="col-md-6">
        <label class="form-label">To Unit <span class="text-danger">*</span></label>
        <select name="to_unit_id" class="form-select" required>
          <option value="">Select unit</option>
          @foreach($units as $unit)
            <option value="{{ $unit->id }}" @selected((int) old('to_unit_id', $conversion->to_unit_id) === $unit->id)>
              {{ $unit->name }} ({{ $unit->code }}){{ $unit->is_active ? '' : ' — Inactive' }}
            </option>
          @endforeach
        </select>
        <div class="invalid-feedback d-block to_unit_id-error" style="display:none"></div>
      </div>

      <div class="col-12">
        <div class="d-flex align-items-center gap-1 mb-8">
          <label class="form-label mb-0">Conversion Factor <span class="text-danger">*</span></label>
          <x-backend.field-note label="Conversion factor example">
            Examples: 1 KG = 1000 G, 1 M = 100 CM, 1 DOZEN = 12 PCS.
          </x-backend.field-note>
        </div>
        <div class="input-group">
          <span class="input-group-text">1 From =</span>
          <input name="factor" type="number" min="0.000001" max="99999999999999.999999" step="0.000001"
            class="form-control" required value="{{ old('factor', $conversion->factor) }}" placeholder="e.g. 1000">
          <span class="input-group-text">To</span>
        </div>
        <div class="invalid-feedback d-block factor-error" style="display:none"></div>
      </div>
    </div>
  </div>

  <div class="modal-footer">
    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button>
    <button class="btn btn-primary" type="submit">Save Conversion</button>
  </div>
</form>
