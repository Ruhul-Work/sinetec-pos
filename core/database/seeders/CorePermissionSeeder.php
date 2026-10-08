<?php

namespace Database\Seeders;

use App\Models\backend\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class CorePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = [
            ['key' => 'core.users', 'name' => 'Users', 'module' => 'Access', 'prefixes' => ['usermanage.']],
            ['key' => 'core.permissions', 'name' => 'Permissions', 'module' => 'Access', 'prefixes' => ['rbac.permissions.']],
            ['key' => 'core.roles', 'name' => 'Roles', 'module' => 'Access', 'prefixes' => ['rbac.role.']],
            ['key' => 'core.firewall', 'name' => 'Firewall', 'module' => 'Security', 'prefixes' => ['security.firewall.']],
            ['key' => 'core.branches', 'name' => 'Branches', 'module' => 'Organisation', 'prefixes' => ['org.branch.', 'org.branches.']],
            ['key' => 'core.countries', 'name' => 'Countries', 'module' => 'Location', 'prefixes' => ['country.countries.']],
            ['key' => 'core.divisions', 'name' => 'Divisions', 'module' => 'Location', 'prefixes' => ['division.divisions.']],
            ['key' => 'core.districts', 'name' => 'Districts', 'module' => 'Location', 'prefixes' => ['district.districts.']],
            ['key' => 'core.upazilas', 'name' => 'Upazilas', 'module' => 'Location', 'prefixes' => ['upazila.upazilas.']],
            ['key' => 'core.brands', 'name' => 'Brands', 'module' => 'Master Data', 'prefixes' => ['brand.brands.']],
            ['key' => 'core.units', 'name' => 'Units', 'module' => 'Master Data', 'prefixes' => ['units.']],
            ['key' => 'core.warehouses', 'name' => 'Warehouses', 'module' => 'Inventory', 'prefixes' => ['inventory.warehouses.']],
            ['key' => 'core.suppliers', 'name' => 'Suppliers', 'module' => 'Master Data', 'prefixes' => ['supplier.']],
            ['key' => 'core.customers', 'name' => 'Customers', 'module' => 'Master Data', 'prefixes' => ['customer.']],
            ['key' => 'core.payment_types', 'name' => 'Payment Types', 'module' => 'Finance', 'prefixes' => ['paymentTypes.']],
            ['key' => 'core.company_settings', 'name' => 'Company Settings', 'module' => 'Administration', 'prefixes' => ['company_setting.']],
            ['key' => 'core.fiscal_years', 'name' => 'Fiscal Years', 'module' => 'Finance', 'prefixes' => ['fiscal-years.']],
            ['key' => 'core.account_types', 'name' => 'Account Types', 'module' => 'Finance', 'prefixes' => ['account-types.']],
            ['key' => 'core.accounts', 'name' => 'Accounts', 'module' => 'Finance', 'prefixes' => ['accounts.']],
            ['key' => 'core.branch_accounts', 'name' => 'Branch Accounts', 'module' => 'Finance', 'prefixes' => ['branch-accounts.']],
        ];

        $protectedRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => $route->getName() && in_array('perm', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->getName())
            ->unique()
            ->values();

        DB::transaction(function () use ($catalogue, $protectedRoutes) {
            foreach ($catalogue as $sort => $definition) {
                $routeNames = $protectedRoutes
                    ->filter(fn (string $name) => collect($definition['prefixes'])
                        ->contains(fn (string $prefix) => str_starts_with($name, $prefix)))
                    ->values();

                $permission = Permission::withTrashed()->firstOrNew(['key' => $definition['key']]);
                $permission->fill([
                    'name' => $definition['name'],
                    'module' => $definition['module'],
                    'type' => 'route',
                    'description' => 'Generated from the current retained application routes.',
                    'sort' => ($sort + 1) * 10,
                    'is_active' => true,
                ]);
                $permission->deleted_at = null;
                $permission->save();

                DB::table('permission_routes')->where('permission_id', $permission->id)->delete();

                if ($routeNames->isNotEmpty()) {
                    DB::table('permission_routes')->whereIn('route_name', $routeNames)->delete();
                    DB::table('permission_routes')->insert(
                        $routeNames->map(fn (string $name) => [
                            'permission_id' => $permission->id,
                            'route_name' => $name,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ])->all()
                    );
                }
            }
        });

        Cache::forget('perm_map');
        Cache::forget('perm_key_map');
        Cache::forget('perm_id_by_key');
    }
}
