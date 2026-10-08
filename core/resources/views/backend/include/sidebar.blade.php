<aside class="sidebar">
    <button type="button" class="sidebar-close-btn">
        <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
    </button>

    <div>
        @php
            $logoUrl = $companySetting?->logo ? image($companySetting->logo) : asset('theme/admin/assets/images/logo1.png');
        @endphp
        <a href="{{ route('backend.dashboard') }}" class="sidebar-logo">
            <img src="{{ $logoUrl }}" alt="site logo" class="light-logo img-fluid">
            <img src="{{ $logoUrl }}" alt="site logo" class="dark-logo">
            <img src="{{ $logoUrl }}" alt="site logo" class="logo-icon">
        </a>
    </div>

    <div class="sidebar-menu-area">
        <ul class="sidebar-menu" id="sidebar-menu">
            <li class="dropdown {{ Route::is('backend.dashboard') ? 'active' : '' }}">
                <a href="javascript:void(0)">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="menu-icon"></iconify-icon>
                    <span>Dashboard</span>
                </a>
                <ul class="sidebar-submenu">
                    <li>
                        <a href="{{ route('backend.dashboard') }}">
                            <i class="ri-circle-fill circle-icon text-warning-main w-auto"></i> Dashboard
                        </a>
                    </li>
                </ul>
            </li>

            @permgroup(['usermanage', 'rbac', 'security'])
                <li class="sidebar-menu-group-title">Administration</li>
                <li class="dropdown {{ Route::is('usermanage.*') || Route::is('rbac.*') || Route::is('security.*') ? 'active' : '' }}">
                    <a href="javascript:void(0)">
                        <iconify-icon icon="flowbite:users-group-outline" class="menu-icon"></iconify-icon>
                        <span>Users & Security</span>
                    </a>
                    <ul class="sidebar-submenu">
                        @perm('usermanage.users.index')
                            <li><a class="{{ Route::is('usermanage.users.*') ? 'active' : '' }}" href="{{ route('usermanage.users.index') }}"><iconify-icon icon="flowbite:users-outline" class="menu-icon"></iconify-icon><span>Users</span></a></li>
                        @endperm
                        @perm('rbac.role.index')
                            <li><a class="{{ Route::is('rbac.role.*') ? 'active' : '' }}" href="{{ route('rbac.role.index') }}"><iconify-icon icon="mdi:shield-account-outline" class="menu-icon"></iconify-icon><span>Roles</span></a></li>
                        @endperm
                        @perm('rbac.permissions.index')
                            <li><a class="{{ Route::is('rbac.permissions.*') ? 'active' : '' }}" href="{{ route('rbac.permissions.index') }}"><iconify-icon icon="mdi:lock-check-outline" class="menu-icon"></iconify-icon><span>Permissions</span></a></li>
                        @endperm
                        @perm('security.firewall.index')
                            <li><a class="{{ Route::is('security.firewall.*') ? 'active' : '' }}" href="{{ route('security.firewall.index') }}"><iconify-icon icon="mdi:shield-outline" class="menu-icon"></iconify-icon><span>Firewall</span></a></li>
                        @endperm
                    </ul>
                </li>
            @endpermgroup

            @permgroup(['org.branches', 'inventory.warehouses', 'company_setting'])
                <li class="sidebar-menu-group-title">Organization</li>
                <li class="dropdown {{ Route::is('org.branches.*') || Route::is('inventory.warehouses.*') || Route::is('company_setting.*') ? 'active' : '' }}">
                    <a href="javascript:void(0)">
                        <iconify-icon icon="mdi:office-building-outline" class="menu-icon"></iconify-icon>
                        <span>Company & Locations</span>
                    </a>
                    <ul class="sidebar-submenu">
                        @perm('org.branches.index')
                            <li><a class="{{ Route::is('org.branches.*') ? 'active' : '' }}" href="{{ route('org.branches.index') }}"><iconify-icon icon="mdi:store-outline" class="menu-icon"></iconify-icon><span>Branches</span></a></li>
                        @endperm
                        @perm('inventory.warehouses.index')
                            <li><a class="{{ Route::is('inventory.warehouses.*') ? 'active' : '' }}" href="{{ route('inventory.warehouses.index') }}"><iconify-icon icon="mdi:warehouse" class="menu-icon"></iconify-icon><span>Warehouses</span></a></li>
                        @endperm
                        @perm('company_setting.index')
                            <li><a class="{{ Route::is('company_setting.*') ? 'active' : '' }}" href="{{ route('company_setting.index') }}"><iconify-icon icon="mdi:cog-outline" class="menu-icon"></iconify-icon><span>Company Settings</span></a></li>
                        @endperm
                    </ul>
                </li>
            @endpermgroup

            @permgroup(['units', 'brand', 'supplier', 'customer', 'paymentTypes'])
                <li class="sidebar-menu-group-title">Master Data</li>
                <li class="dropdown {{ Route::is('units.*') || Route::is('brand.brands.*') || Route::is('supplier.*') || Route::is('customer.*') || Route::is('paymentTypes.*') ? 'active' : '' }}">
                    <a href="javascript:void(0)">
                        <iconify-icon icon="mdi:database-cog-outline" class="menu-icon"></iconify-icon>
                        <span>Master Setup</span>
                    </a>
                    <ul class="sidebar-submenu">
                        @perm('units.index')
                            <li><a class="{{ Route::is('units.*') ? 'active' : '' }}" href="{{ route('units.index') }}"><iconify-icon icon="mdi:ruler" class="menu-icon"></iconify-icon><span>Units</span></a></li>
                        @endperm
                        @perm('brand.brands.index')
                            <li><a class="{{ Route::is('brand.brands.*') ? 'active' : '' }}" href="{{ route('brand.brands.index') }}"><iconify-icon icon="mdi:tag-outline" class="menu-icon"></iconify-icon><span>Brands</span></a></li>
                        @endperm
                        @perm('supplier.index')
                            <li><a class="{{ Route::is('supplier.*') ? 'active' : '' }}" href="{{ route('supplier.index') }}"><iconify-icon icon="mdi:truck-outline" class="menu-icon"></iconify-icon><span>Suppliers</span></a></li>
                        @endperm
                        @perm('customer.index')
                            <li><a class="{{ Route::is('customer.*') ? 'active' : '' }}" href="{{ route('customer.index') }}"><iconify-icon icon="mdi:account-group-outline" class="menu-icon"></iconify-icon><span>Customers</span></a></li>
                        @endperm
                        @perm('paymentTypes.index')
                            <li><a class="{{ Route::is('paymentTypes.*') ? 'active' : '' }}" href="{{ route('paymentTypes.index') }}"><iconify-icon icon="mdi:cash-multiple" class="menu-icon"></iconify-icon><span>Payment Types</span></a></li>
                        @endperm
                    </ul>
                </li>
            @endpermgroup

            @permgroup(['fiscal-years', 'account-types', 'accounts', 'branch-accounts'])
                <li class="sidebar-menu-group-title">Finance Foundation</li>
                <li class="dropdown {{ Route::is('fiscal-years.*') || Route::is('account-types.*') || Route::is('accounts.*') || Route::is('branch-accounts.*') ? 'active' : '' }}">
                    <a href="javascript:void(0)">
                        <iconify-icon icon="mdi:bank-outline" class="menu-icon"></iconify-icon>
                        <span>Accounts Setup</span>
                    </a>
                    <ul class="sidebar-submenu">
                        @perm('fiscal-years.index')
                            <li><a class="{{ Route::is('fiscal-years.*') ? 'active' : '' }}" href="{{ route('fiscal-years.index') }}"><iconify-icon icon="mdi:calendar-clock" class="menu-icon"></iconify-icon><span>Fiscal Years</span></a></li>
                        @endperm
                        @perm('account-types.index')
                            <li><a class="{{ Route::is('account-types.*') ? 'active' : '' }}" href="{{ route('account-types.index') }}"><iconify-icon icon="mdi:shape-outline" class="menu-icon"></iconify-icon><span>Account Types</span></a></li>
                        @endperm
                        @perm('accounts.index')
                            <li><a class="{{ Route::is('accounts.*') ? 'active' : '' }}" href="{{ route('accounts.index') }}"><iconify-icon icon="mdi:format-list-bulleted" class="menu-icon"></iconify-icon><span>Accounts</span></a></li>
                        @endperm
                        @perm('branch-accounts.index')
                            <li><a class="{{ Route::is('branch-accounts.*') ? 'active' : '' }}" href="{{ route('branch-accounts.index') }}"><iconify-icon icon="mdi:source-branch" class="menu-icon"></iconify-icon><span>Branch Accounts</span></a></li>
                        @endperm
                    </ul>
                </li>
            @endpermgroup

            @permgroup(['country', 'division', 'district', 'upazila'])
                <li class="sidebar-menu-group-title">Location Management</li>
                <li class="dropdown {{ Route::is('country.*') || Route::is('division.*') || Route::is('district.*') || Route::is('upazila.*') ? 'active' : '' }}">
                    <a href="javascript:void(0)">
                        <iconify-icon icon="mdi:map-marker-radius-outline" class="menu-icon"></iconify-icon>
                        <span>Address Setup</span>
                    </a>
                    <ul class="sidebar-submenu">
                        @perm('country.countries.index')
                            <li><a class="{{ Route::is('country.*') ? 'active' : '' }}" href="{{ route('country.countries.index') }}"><span>Countries</span></a></li>
                        @endperm
                        @perm('division.divisions.index')
                            <li><a class="{{ Route::is('division.*') ? 'active' : '' }}" href="{{ route('division.divisions.index') }}"><span>Divisions</span></a></li>
                        @endperm
                        @perm('district.districts.index')
                            <li><a class="{{ Route::is('district.*') ? 'active' : '' }}" href="{{ route('district.districts.index') }}"><span>Districts</span></a></li>
                        @endperm
                        @perm('upazila.upazilas.index')
                            <li><a class="{{ Route::is('upazila.*') ? 'active' : '' }}" href="{{ route('upazila.upazilas.index') }}"><span>Upazilas</span></a></li>
                        @endperm
                    </ul>
                </li>
            @endpermgroup
        </ul>
    </div>
</aside>
