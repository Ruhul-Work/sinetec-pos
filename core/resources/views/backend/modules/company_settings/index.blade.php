@extends('backend.layouts.master')

@section('meta')
    <title>Company Settings</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-0">Company Settings</h6>
            <p class="mb-0">Company identity and contact information.</p>
        </div>
        @perm('company_setting.create')
            <a href="{{ route('company_setting.create') }}" class="btn btn-primary btn-sm">Add Company Setting</a>
        @endperm
    </div>

    <div class="card basic-data-table">
        <div class="card-body">
            <table class="table bordered-table table-scroll mb-0 AjaxDataTable" style="width:100%">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Company</th>
                        <th>Code</th>
                        <th>Logo</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Address</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = "{{ route('company_setting.list.ajax') }}";
    </script>
@endsection
