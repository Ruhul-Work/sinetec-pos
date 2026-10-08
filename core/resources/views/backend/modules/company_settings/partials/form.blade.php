@php
    $isEditing = $companySetting !== null;
    $action = $isEditing ? route('company_setting.update', $companySetting) : route('company_setting.store');
@endphp

<form action="{{ $action }}" method="POST" enctype="multipart/form-data">
    @csrf
    @if ($isEditing)
        @method('PUT')
    @endif

    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                    <input name="name" class="form-control" value="{{ old('name', $companySetting?->name) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Company Code <span class="text-danger">*</span></label>
                    <input name="code" class="form-control" value="{{ old('code', $companySetting?->code) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-select">
                        <option value="1" @selected(old('is_active', $companySetting?->is_active ?? true))>Active</option>
                        <option value="0" @selected(! old('is_active', $companySetting?->is_active ?? true))>Inactive</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" value="{{ old('email', $companySetting?->email) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone <span class="text-danger">*</span></label>
                    <input name="phone" class="form-control" value="{{ old('phone', $companySetting?->phone) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Website</label>
                    <input type="url" name="website" class="form-control" value="{{ old('website', $companySetting?->website) }}">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2">{{ old('address', $companySetting?->address) }}</textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label">City</label>
                    <input name="city" class="form-control" value="{{ old('city', $companySetting?->city) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Country</label>
                    <input name="country" class="form-control" value="{{ old('country', $companySetting?->country) }}">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Logo</label>
                    <input type="file" name="logo" class="form-control" accept="image/png,image/jpeg">
                    @if ($companySetting?->logo)
                        <img src="{{ image($companySetting->logo) }}" alt="Current logo" class="mt-2" style="height:48px;max-width:180px;object-fit:contain">
                    @endif
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">{{ $isEditing ? 'Update' : 'Save' }}</button>
        </div>
    </div>
</form>
