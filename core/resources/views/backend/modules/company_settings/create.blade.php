@extends('backend.layouts.master')

@section('meta')
    <title>Add Company Setting</title>
@endsection

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Add Company Setting</h6>
            <p class="mb-0">This is independent of the retired business-type module.</p>
        </div>
        <a href="{{ route('company_setting.index') }}" class="btn btn-secondary">Back</a>
    </div>

    @include('backend.modules.company_settings.partials.form', ['companySetting' => null])
@endsection
