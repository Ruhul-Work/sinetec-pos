@extends('backend.layouts.master')

@section('meta')
    <title>Edit Company Setting</title>
@endsection

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Edit Company Setting</h6>
            <p class="mb-0">Company identity and contact information.</p>
        </div>
        <a href="{{ route('company_setting.index') }}" class="btn btn-secondary">Back</a>
    </div>

    @include('backend.modules.company_settings.partials.form', ['companySetting' => $companySetting])
@endsection
