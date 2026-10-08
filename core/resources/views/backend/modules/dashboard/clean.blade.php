@extends('backend.layouts.master')

@section('meta')
    <title>{{ $companySetting?->name ?? config('app.name') }} — Dashboard</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-1">{{ $companySetting?->name ?? config('app.name') }}</h6>
            <p class="text-secondary-light mb-0">Electronics Manufacturing & Inventory System</p>
        </div>
        <span class="badge text-sm fw-semibold text-primary-600 bg-primary-100 px-20 py-9 radius-4">
            Foundation Setup
        </span>
    </div>

    <div class="row gy-4">
        <div class="col-xxl-3 col-xl-4 col-sm-6">
            <div class="card h-100 radius-12">
                <div class="card-body p-20 d-flex align-items-center gap-3">
                    <span class="w-52-px h-52-px radius-12 d-inline-flex justify-content-center align-items-center text-2xl bg-primary-100 text-primary-600 flex-shrink-0">
                        <iconify-icon icon="mdi:office-building-outline"></iconify-icon>
                    </span>
                    <div>
                        <span class="text-secondary-light text-sm">Organization</span>
                        <h6 class="fw-semibold mb-0">Branch & Warehouse</h6>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xxl-3 col-xl-4 col-sm-6">
            <div class="card h-100 radius-12">
                <div class="card-body p-20 d-flex align-items-center gap-3">
                    <span class="w-52-px h-52-px radius-12 d-inline-flex justify-content-center align-items-center text-2xl bg-success-100 text-success-600 flex-shrink-0">
                        <iconify-icon icon="mdi:database-cog-outline"></iconify-icon>
                    </span>
                    <div>
                        <span class="text-secondary-light text-sm">Master Data</span>
                        <h6 class="fw-semibold mb-0">Ready for Setup</h6>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xxl-3 col-xl-4 col-sm-6">
            <div class="card h-100 radius-12">
                <div class="card-body p-20 d-flex align-items-center gap-3">
                    <span class="w-52-px h-52-px radius-12 d-inline-flex justify-content-center align-items-center text-2xl bg-warning-100 text-warning-600 flex-shrink-0">
                        <iconify-icon icon="mdi:bank-outline"></iconify-icon>
                    </span>
                    <div>
                        <span class="text-secondary-light text-sm">Finance</span>
                        <h6 class="fw-semibold mb-0">Master Setup</h6>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xxl-3 col-xl-4 col-sm-6">
            <div class="card h-100 radius-12">
                <div class="card-body p-20 d-flex align-items-center gap-3">
                    <span class="w-52-px h-52-px radius-12 d-inline-flex justify-content-center align-items-center text-2xl bg-lilac-100 text-lilac-600 flex-shrink-0">
                        <iconify-icon icon="mdi:factory"></iconify-icon>
                    </span>
                    <div>
                        <span class="text-secondary-light text-sm">Next Build</span>
                        <h6 class="fw-semibold mb-0">Raw, Part & Product</h6>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card h-100 radius-12">
                <div class="card-body p-24">
                    <div class="d-flex align-items-center gap-3 mb-20">
                        <span class="w-44-px h-44-px rounded-circle d-inline-flex justify-content-center align-items-center bg-primary-100 text-primary-600 text-xl">
                            <iconify-icon icon="mdi:clipboard-check-outline"></iconify-icon>
                        </span>
                        <div>
                            <h6 class="fw-semibold mb-1">System Preparation</h6>
                            <p class="text-secondary-light text-sm mb-0">Complete these independent master records before transaction modules begin.</p>
                        </div>
                    </div>
                    <div class="row gy-3">
                        <div class="col-md-6"><div class="border radius-8 p-16 h-100"><span class="fw-semibold d-block mb-4">1. Organization</span><span class="text-secondary-light text-sm">Company, branch, warehouse and address setup.</span></div></div>
                        <div class="col-md-6"><div class="border radius-8 p-16 h-100"><span class="fw-semibold d-block mb-4">2. Master Data</span><span class="text-secondary-light text-sm">Units, brands, suppliers, customers and payment types.</span></div></div>
                        <div class="col-md-6"><div class="border radius-8 p-16 h-100"><span class="fw-semibold d-block mb-4">3. Finance Foundation</span><span class="text-secondary-light text-sm">Fiscal year, account type, accounts and branch mapping.</span></div></div>
                        <div class="col-md-6"><div class="border radius-8 p-16 h-100"><span class="fw-semibold d-block mb-4">4. New Modules</span><span class="text-secondary-light text-sm">Raw material, parts, finished product, production and sales.</span></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card h-100 radius-12">
                <div class="card-body p-24">
                    <h6 class="fw-semibold mb-4">Quick Setup</h6>
                    <p class="text-secondary-light text-sm mb-20">Use the foundation menus to prepare the system.</p>
                    <div class="d-grid gap-12">
                        @perm('org.branches.index')
                            <a href="{{ route('org.branches.index') }}" class="btn btn-outline-primary">Manage Branches</a>
                        @endperm
                        @perm('inventory.warehouses.index')
                            <a href="{{ route('inventory.warehouses.index') }}" class="btn btn-outline-success">Manage Warehouses</a>
                        @endperm
                        @perm('units.index')
                            <a href="{{ route('units.index') }}" class="btn btn-outline-secondary">Configure Units</a>
                        @endperm
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
