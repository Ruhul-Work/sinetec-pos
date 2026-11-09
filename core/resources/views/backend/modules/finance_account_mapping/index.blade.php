@extends('backend.layouts.master')

@section('meta')
    <title>Finance Account Mapping</title>
@endsection

@section('content')
    @php
        $requiredCount = count($definitions);
        $companyComplete = $companyMappings->count();
    @endphp

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Finance Account Mapping</h6>
                <x-backend.page-note title="Mapping resolution" label="View mapping rule" align="start">
                    A branch override is used first. If there is no override, the company default is used.
                    Transaction modules will use these rules when journal automation is enabled.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Define company-wide account rules and optional branch-specific overrides</p>
        </div>
        <span class="badge {{ $companyComplete === $requiredCount ? 'bg-dark-success-gradient' : 'bg-dark-warning-gradient' }} text-white px-14 py-9 radius-6">
            Company setup: {{ $companyComplete }}/{{ $requiredCount }}
        </span>
    </div>

    <div class="card">
        <div class="card-header pb-0">
            <ul class="nav nav-tabs border-0 gap-2" role="tablist">
                @if ($canManageCompany)
                    <li class="nav-item">
                        <button class="nav-link active finance-mapping-tab" data-bs-toggle="tab" data-bs-target="#companyMapping" type="button">
                            <iconify-icon icon="mdi:office-building-cog-outline"></iconify-icon>
                            Company Default
                        </button>
                    </li>
                @endif
                <li class="nav-item">
                    <button
                        class="nav-link finance-mapping-tab {{ $canManageCompany ? '' : 'active' }}"
                        data-bs-toggle="tab"
                        data-bs-target="#branchMapping"
                        type="button"
                    >
                        <iconify-icon icon="mdi:source-branch"></iconify-icon>
                        Branch Override
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content">
                @if ($canManageCompany)
                    <div class="tab-pane fade show active" id="companyMapping">
                        <form method="POST" action="{{ route('finance.account-mappings.company.update') }}">
                            @csrf
                            @method('PUT')
                            <div class="alert alert-info border-0 bg-info-focus text-info-main finance-mapping-alert">
                                <iconify-icon icon="mdi:information-outline"></iconify-icon>
                                <span>These accounts are the fallback for every branch. Saving a mapped account also assigns it to all active branches.</span>
                            </div>

                            <div class="finance-mapping-list">
                                @foreach ($definitions as $definition)
                                    @php $selected = $companyMappings->get($definition['key'])?->account_id; @endphp
                                    <div class="finance-mapping-row">
                                        <div class="finance-mapping-copy">
                                            <div class="d-flex align-items-center gap-2">
                                                <strong>{{ $definition['label'] }}</strong>
                                                <code>{{ $definition['key'] }}</code>
                                            </div>
                                            <p>{{ $definition['purpose'] }}</p>
                                            <small>Required: {{ $definition['expected_type'] }} &middot; {{ $definition['normal_balance'] }} normal</small>
                                        </div>
                                        <div class="finance-mapping-select">
                                            <select name="mappings[{{ $definition['key'] }}]" class="form-control js-s2">
                                                <option value="">Not configured</option>
                                                @foreach ($companyAccounts[$definition['key']] as $account)
                                                    <option
                                                        value="{{ $account['id'] }}"
                                                        @selected((int) $selected === $account['id'])
                                                    >
                                                        {{ $account['text'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button class="btn btn-primary app-action-button app-primary-action px-24">
                                    <iconify-icon icon="mdi:content-save-cog-outline"></iconify-icon>
                                    <span>Save Company Mapping</span>
                                </button>
                            </div>
                        </form>
                    </div>
                @endif

                <div class="tab-pane fade {{ $canManageCompany ? '' : 'show active' }}" id="branchMapping">
                    <div class="row align-items-end g-3 mb-4">
                        <div class="col-lg-6">
                            <label class="form-label">Select Branch <span class="text-danger">*</span></label>
                            <select id="financeMappingBranch" class="form-control js-s2">
                                <option value="">Select branch</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">
                                        {{ $branch->name }} ({{ $branch->code }})
                                        &middot; {{ $branchStatuses[$branch->id] }}/{{ $requiredCount }} effective
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-6">
                            <div class="alert alert-light border mb-0 finance-override-note">
                                <iconify-icon icon="mdi:information-outline"></iconify-icon>
                                <span>Leave an override blank to use the company default.</span>
                            </div>
                        </div>
                    </div>

                    <div id="branchMappingEmpty" class="finance-mapping-empty py-5 text-muted">
                        <iconify-icon icon="mdi:source-branch"></iconify-icon>
                        <p class="mb-0 mt-2">Select a branch to view its effective accounts.</p>
                    </div>

                    <form method="POST" action="" id="branchMappingForm" class="d-none">
                        @csrf
                        @method('PUT')
                        <div class="finance-mapping-list">
                            @foreach ($definitions as $definition)
                                <div class="finance-mapping-row" data-mapping-key="{{ $definition['key'] }}">
                                    <div class="finance-mapping-copy">
                                        <div class="d-flex align-items-center gap-2">
                                            <strong>{{ $definition['label'] }}</strong>
                                            <span class="badge bg-light text-dark js-effective-source">Missing</span>
                                        </div>
                                        <p>{{ $definition['purpose'] }}</p>
                                        <small class="js-effective-account text-muted">No effective account</small>
                                    </div>
                                    <div class="finance-mapping-select">
                                        <select
                                            name="mappings[{{ $definition['key'] }}]"
                                            class="form-control js-branch-mapping"
                                            data-key="{{ $definition['key'] }}"
                                        >
                                            <option value="">Use company default</option>
                                        </select>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button class="btn btn-primary app-action-button app-primary-action px-24">
                                <iconify-icon icon="mdi:content-save-cog-outline"></iconify-icon>
                                <span>Save Branch Overrides</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        (function($) {
            S2.auto($(document));

            const dataUrl = @json(route('finance.account-mappings.branch-data', 'BRANCH_ID'));
            const updateUrl = @json(route('finance.account-mappings.branch.update', 'BRANCH_ID'));

            $('#financeMappingBranch').on('change', function() {
                const id = $(this).val();
                if (!id) {
                    $('#branchMappingForm').addClass('d-none');
                    $('#branchMappingEmpty').removeClass('d-none');
                    return;
                }

                $('#branchMappingEmpty').addClass('d-none');
                $('#branchMappingForm').removeClass('d-none').attr('action', updateUrl.replace('BRANCH_ID', id));

                $.get(dataUrl.replace('BRANCH_ID', id), function(response) {
                    $('.js-branch-mapping').each(function() {
                        const key = $(this).data('key');
                        const options = response.accounts[key] || [];
                        const $select = $(this);
                        const effective = response.effective[key] || {};
                        const $row = $select.closest('.finance-mapping-row');

                        $select.empty().append(new Option('Use company default', ''));
                        options.forEach(item => $select.append(new Option(item.text, item.id)));
                        $select.val(response.overrides[key] || '').trigger('change.select2');
                        $row.find('.js-effective-account').text(effective.account || 'No effective account');
                        $row.find('.js-effective-source')
                            .text(effective.source === 'BRANCH' ? 'Branch override' : effective.source === 'COMPANY' ? 'Company default' : 'Missing')
                            .toggleClass('bg-success-focus text-success-main', !!effective.source)
                            .toggleClass('bg-danger-focus text-danger-main', !effective.source);
                    });
                });
            });

            @if (! $canManageCompany && $branches->count() === 1)
                $('#financeMappingBranch').val(@json((string) $branches->first()->id)).trigger('change');
            @endif
        })(jQuery);
    </script>
@endsection
