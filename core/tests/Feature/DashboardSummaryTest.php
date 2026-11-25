<?php

namespace Tests\Feature;

use App\Models\backend\Branch;
use App\Models\backend\Role;
use App\Models\backend\User;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summarizes_posted_documents_with_branch_isolation(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 25)->setTime(12, 0));

        $mainBranch = $this->createBranchWithSales('MAIN', 200);
        $otherBranch = $this->createBranchWithSales('OTHER', 50);
        $this->seed(CorePermissionSeeder::class);
        $mainTotals = [
            'todaySales' => 200.0,
            'monthlyNetSales' => 280.0,
            'monthlyPurchases' => 80.0,
            'monthlyInvoiceCount' => 2,
            'monthlyExpenses' => 25.0,
            'monthlySalesReturns' => 20.0,
        ];

        $this->actingAs($this->createUser($mainBranch, true))
            ->withSession(['branch_scope' => 'all'])
            ->get(route('backend.dashboard'))
            ->assertOk()
            ->assertViewHas('dashboardSummary', [
                'todaySales' => 250.0,
                'monthlyNetSales' => 410.0,
                'monthlyPurchases' => 160.0,
                'monthlyInvoiceCount' => 4,
                'monthlyExpenses' => 50.0,
                'monthlySalesReturns' => 40.0,
            ])
            ->assertViewHas('salesPurchaseExpenseTrend', function (array $trend): bool {
                return $trend['monthly']['sales'][10] === 410.0
                    && $trend['monthly']['purchaseAndExpense'][10] === 210.0
                    && $trend['daily']['sales'][29] === 210.0
                    && $trend['weekly']['sales'][11] === 210.0
                    && $trend['yearly']['sales'][4] === 2210.0;
            })
            ->assertViewHas('topCustomers', function ($customers): bool {
                return $customers->pluck('name')->all() === ['MAIN Customer', 'OTHER Customer']
                    && (float) $customers->first()->total_sale === 280.0;
            })
            ->assertViewHas('topSuppliers', fn ($suppliers): bool => $suppliers->count() === 2)
            ->assertViewHas('recentTransactions', function ($transactions): bool {
                return $transactions->count() === 5
                    && $transactions->pluck('branch_id')->unique()->count() === 2
                    && $transactions->pluck('route')->contains('purchase.returns.show')
                    && ! $transactions->contains(fn ($row): bool => str_ends_with($row->reference, '-DRAFT'));
            })
            ->assertViewHas('overallReportData', function (array $report): bool {
                return $report['monthly'] === ['purchases' => 160.0, 'sales' => 450.0, 'expenses' => 50.0, 'returns' => 40.0]
                    && $report['today']['sales'] === 250.0
                    && $report['weekly']['sales'] === 250.0
                    && $report['yearly']['sales'] === 2250.0;
            })
            ->assertViewHas('purchaseSaleChartData', function (array $chart): bool {
                return $chart['this_week']['totalSales'] === 210.0
                    && $chart['this_month']['totalSales'] === 410.0
                    && $chart['this_month']['totalPurchase'] === 160.0
                    && $chart['this_year']['totalSales'] === 2210.0;
            })
            ->assertSee('Monthly Net Sales')
            ->assertSee('href="'.route('sales.pos.create').'" class="btn pos-top-btn"', false)
            ->assertDontSee('sidebar-menu-group-title">Purchasing', false)
            ->assertSee('250.00');

        $this->withSession(['branch_scope' => $mainBranch->id])
            ->get(route('backend.dashboard'))
            ->assertOk()
            ->assertViewHas('dashboardSummary', $mainTotals)
            ->assertViewHas('salesPurchaseExpenseTrend', fn (array $trend): bool => $trend['monthly']['sales'][10] === 280.0);

        $operator = $this->createUser($mainBranch, false);
        $permissions = DB::table('permissions')
            ->whereIn('key', ['sales.pos', 'purchase.orders', 'production.product_assemblies'])
            ->pluck('id');
        foreach ($permissions as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $operator->role_id,
                'permission_id' => $permissionId,
                'can_view' => true,
            ]);
        }
        $this->actingAs($operator)
            ->withSession(['branch_scope' => 'all'])
            ->get(route('backend.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['sidebar-menu-group-title">Inventory', '<span>Purchasing</span>'], false)
            ->assertSee('Final Product Assembly')
            ->assertDontSee('<span>Stock Operations</span>', false)
            ->assertDontSee('class="btn pos-top-btn"', false)
            ->assertViewHas('dashboardSummary', $mainTotals)
            ->assertViewHas('salesPurchaseExpenseTrend', fn (array $trend): bool => $trend['monthly']['purchaseAndExpense'][10] === 105.0)
            ->assertViewHas('topCustomers', fn ($customers): bool => $customers->pluck('name')->all() === ['MAIN Customer'])
            ->assertViewHas('topSuppliers', fn ($suppliers): bool => $suppliers->pluck('name')->all() === ['MAIN'])
            ->assertViewHas('recentTransactions', fn ($transactions): bool => $transactions->pluck('reference')->all() === ['MAIN-POS'])
            ->assertViewHas('overallReportData', fn (array $report): bool => $report['monthly']['sales'] === 300.0)
            ->assertViewHas('purchaseSaleChartData', fn (array $chart): bool => $chart['this_month']['totalPurchase'] === 80.0);

        $this->withSession(['branch_scope' => $otherBranch->id])
            ->get(route('backend.dashboard'))
            ->assertOk()
            ->assertViewHas('dashboardSummary', $mainTotals);

        $this->travelTo(now()->setDay(30));
        $this->get(route('backend.dashboard'))
            ->assertOk()
            ->assertViewHas('purchaseSaleChartData', function (array $chart): bool {
                return $chart['this_month']['purchase'][4] === 7.0
                    && $chart['this_month']['totalPurchase'] === 87.0;
            });
    }

    private function createBranchWithSales(string $code, int $todaySale): Branch
    {
        $branch = Branch::query()->create(['name' => $code, 'code' => $code, 'is_active' => true]);
        $warehouse = DB::table('warehouses')->insertGetId([
            'branch_id' => $branch->id,
            'name' => $code,
            'code' => $code,
            'type' => 'store',
        ]);
        $supplier = DB::table('suppliers')->insertGetId(['name' => $code, 'code' => $code]);
        $customer = DB::table('customers')->insertGetId(['name' => $code.' Customer', 'code' => $code]);
        $expenseCategory = DB::table('expense_categories')->insertGetId([
            'name' => $code,
            'code' => $code,
        ]);

        $location = ['branch_id' => $branch->id, 'warehouse_id' => $warehouse];
        $saleLocation = $location + ['customer_id' => $customer];
        $saleId = DB::table('sales')->insertGetId($saleLocation + [
            'sale_no' => $code.'-POS',
            'channel' => 'POS',
            'sale_at' => '2026-11-25 11:00:00',
            'status' => 'POSTED',
            'total' => $todaySale,
        ]);

        $otherSales = [
            'WHOLESALE' => ['2026-11-01 00:00:00', 'POSTED', 100],
            'DRAFT' => ['2026-11-25 10:00:00', 'DRAFT', 900],
            'VOID' => ['2026-11-25 10:00:00', 'VOID', 900],
            'PRIOR' => ['2026-10-31 23:59:59', 'POSTED', 900],
            'FUTURE' => ['2026-11-26 00:00:00', 'POSTED', 900],
        ];
        foreach ($otherSales as $suffix => [$date, $status, $amount]) {
            DB::table('sales')->insert($saleLocation + [
                'sale_no' => $code.'-'.$suffix,
                'channel' => 'WHOLESALE',
                'sale_at' => $date,
                'status' => $status,
                'total' => $amount,
            ]);
        }

        DB::table('sale_returns')->insert($location + [
            'return_no' => $code.'-RETURN',
            'sale_id' => $saleId,
            'return_at' => '2026-11-25 11:30:00',
            'status' => 'POSTED',
            'total' => 20,
        ]);
        $receipt = DB::table('purchase_receipt')->insertGetId($location + [
            'receipt_no' => $code.'-PURCHASE',
            'supplier_id' => $supplier,
            'receipt_date' => '2026-11-25',
            'status' => 'POSTED',
            'total' => 80,
        ]);
        DB::table('purchase_return')->insert($location + [
            'return_no' => $code.'-PURCHASE-RETURN',
            'purchase_receipt_id' => $receipt,
            'supplier_id' => $supplier,
            'return_date' => '2026-11-25',
            'posted_at' => '2026-11-25 13:00:00',
            'status' => 'POSTED',
            'total' => 10,
        ]);
        DB::table('purchase_receipt')->insert($location + [
            'receipt_no' => $code.'-MONTH-END-PURCHASE',
            'supplier_id' => $supplier,
            'receipt_date' => '2026-11-30',
            'status' => 'POSTED',
            'total' => 7,
        ]);
        DB::table('expenses')->insert([
            'expense_no' => $code.'-EXPENSE',
            'branch_id' => $branch->id,
            'expense_category_id' => $expenseCategory,
            'expense_date' => '2026-11-25',
            'status' => 'POSTED',
            'amount' => 25,
        ]);

        return $branch;
    }

    private function createUser(Branch $branch, bool $super): User
    {
        $role = Role::query()->create([
            'name' => $super ? 'Super User' : 'Branch Operator',
            'key' => $super ? 'super-user' : 'branch-operator',
            'is_super' => $super,
        ]);

        return User::query()->create([
            'name' => $role->name,
            'email' => $role->key.'@example.test',
            'password' => bcrypt('password'),
            'branch_id' => $branch->id,
            'role_id' => $role->id,
            'status' => true,
        ]);
    }
}
