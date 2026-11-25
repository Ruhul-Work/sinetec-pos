<?php

namespace Tests\Feature;

use App\Enums\Reporting\DemandPlanStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidDemandPlanException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Role;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Reporting\ReportingPeriod;
use App\Models\Sales\Sale;
use App\Models\Sales\SaleItem;
use App\Models\Sales\SaleReturn;
use App\Models\Sales\SaleReturnItem;
use App\Services\Reporting\DemandPlanService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemandPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_history_suggestion_uses_posted_net_sales_monthly_average(): void
    {
        $setup = $this->setupPlanningData();
        $this->postedSale($setup, '2026-08-15 10:00:00', '30');
        $this->postedSale($setup, '2026-09-15 10:00:00', '30');
        $octoberSaleItem = $this->postedSale($setup, '2026-10-15 10:00:00', '30');
        $this->postedReturn($setup, $octoberSaleItem, '2026-10-20 10:00:00', '15');

        $suggestion = app(DemandPlanService::class)
            ->suggestions($setup['branch'], $setup['period'], 3)
            ->firstWhere(fn (array $row): bool => $row['finished_product']->is($setup['product']));

        $this->assertNotNull($suggestion);
        $this->assertSame('75.000000', $suggestion['historical_net_quantity']);
        $this->assertSame('25.000000', $suggestion['suggested_quantity']);
    }

    public function test_draft_snapshots_suggestion_and_approval_freezes_the_plan(): void
    {
        $setup = $this->setupPlanningData();
        $this->postedSale($setup, '2026-10-15 10:00:00', '60');
        $service = app(DemandPlanService::class);

        $plan = $service->createDraft(
            $setup['branch'],
            $setup['period'],
            3,
            [
                $setup['product']->id => [
                    'forecast_quantity' => '30',
                    'safety_quantity' => '5',
                    'basis' => 'Management target for November.',
                ],
            ],
            'November branch demand.',
            $setup['user']->id,
        );

        $this->assertSame(DemandPlanStatus::Draft, $plan->status);
        $this->assertSame(3, $plan->history_months);
        $this->assertCount(1, $plan->items);
        $this->assertSame('60.000000', $plan->items->first()->historical_net_quantity);
        $this->assertSame('20.000000', $plan->items->first()->suggested_quantity);
        $this->assertSame('30.000000', $plan->items->first()->forecast_quantity);
        $this->assertSame('5.000000', $plan->items->first()->safety_quantity);

        $plan = $service->approve($plan, $setup['user']->id);
        $this->assertSame(DemandPlanStatus::Approved, $plan->status);
        $this->assertNotNull($plan->approved_at);

        $this->expectException(InvalidDemandPlanException::class);
        $this->expectExceptionMessage('Only a Draft Demand Plan can be changed.');
        $service->updateDraft($plan, 3, [
            $setup['product']->id => [
                'forecast_quantity' => '35',
                'safety_quantity' => '5',
            ],
        ], null);
    }

    public function test_only_one_plan_is_allowed_for_one_branch_and_target_month(): void
    {
        $setup = $this->setupPlanningData();
        $service = app(DemandPlanService::class);
        $items = [
            $setup['product']->id => [
                'forecast_quantity' => '10',
                'safety_quantity' => '0',
            ],
        ];

        $service->createDraft(
            $setup['branch'],
            $setup['period'],
            3,
            $items,
            null,
            $setup['user']->id,
        );

        $this->expectException(InvalidDemandPlanException::class);
        $this->expectExceptionMessage('A Demand Plan already exists for this branch and target month.');
        $service->createDraft(
            $setup['branch'],
            $setup['period'],
            3,
            $items,
            null,
            $setup['user']->id,
        );
    }

    public function test_super_user_can_open_demand_plan_ui_and_search_the_register(): void
    {
        $setup = $this->setupPlanningData();
        $role = Role::query()->create([
            'name' => 'Super User',
            'key' => 'super-user',
            'is_super' => true,
        ]);
        $setup['user']->update(['role_id' => $role->id]);
        $this->seed(CorePermissionSeeder::class);

        $plan = app(DemandPlanService::class)->createDraft(
            $setup['branch'],
            $setup['period'],
            3,
            [
                $setup['product']->id => [
                    'forecast_quantity' => '12',
                    'safety_quantity' => '2',
                ],
            ],
            null,
            $setup['user']->id,
        );

        $this->actingAs($setup['user'])
            ->get(route('planning.demand-plans.index'))
            ->assertOk()
            ->assertSee('Demand Plan Register');

        $this->get(route('planning.demand-plans.create'))
            ->assertOk()
            ->assertSee('Demand Test Product')
            ->assertSee('Load Suggestions');

        $this->postJson(route('planning.demand-plans.list.ajax'), [
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => $plan->plan_no],
            'order' => [['column' => 0, 'dir' => 'desc']],
        ])->assertOk()
            ->assertJsonPath('draw', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonCount(1, 'data');
    }

    /** @return array<string, mixed> */
    private function setupPlanningData(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Paltan',
            'code' => 'PL-1',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Paltan Warehouse',
            'code' => 'PLW-1',
            'type' => 'store',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Demand Planner',
            'email' => 'demand-planner@example.test',
            'password' => Hash::make('password'),
            'branch_id' => $branch->id,
            'status' => true,
        ]);
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $category = FinishedProductCategory::query()->create([
            'code' => 'FG-DEMAND',
            'name' => 'Demand Finished Goods',
            'is_active' => true,
        ]);
        $product = FinishedProduct::query()->create([
            'product_code' => 'FG-DP-001',
            'name' => 'Demand Test Product',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => 100,
            'wholesale_price' => 90,
            'is_active' => true,
        ]);
        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'status' => ReportingPeriodStatus::Open,
        ]);

        return compact('branch', 'warehouse', 'user', 'unit', 'product', 'period');
    }

    /** @param array<string, mixed> $setup */
    private function postedSale(array $setup, string $saleAt, string $quantity): SaleItem
    {
        $sale = Sale::query()->create([
            'sale_no' => 'SALE-'.str_replace(['-', ' ', ':'], '', $saleAt),
            'branch_id' => $setup['branch']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'channel' => 'POS',
            'sale_at' => $saleAt,
            'status' => 'POSTED',
            'subtotal' => 100,
            'total' => 100,
            'paid_amount' => 100,
            'due_amount' => 0,
            'created_by' => $setup['user']->id,
            'posted_by' => $setup['user']->id,
            'posted_at' => $saleAt,
        ]);

        return SaleItem::query()->create([
            'sale_id' => $sale->id,
            'finished_product_id' => $setup['product']->id,
            'unit_id' => $setup['unit']->id,
            'conversion_to_base' => 1,
            'quantity' => $quantity,
            'base_quantity' => $quantity,
            'unit_price' => 100,
            'line_total' => 100,
        ]);
    }

    /** @param array<string, mixed> $setup */
    private function postedReturn(
        array $setup,
        SaleItem $saleItem,
        string $returnAt,
        string $quantity,
    ): void {
        $return = SaleReturn::query()->create([
            'return_no' => 'RETURN-'.str_replace(['-', ' ', ':'], '', $returnAt),
            'sale_id' => $saleItem->sale_id,
            'branch_id' => $setup['branch']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'return_at' => $returnAt,
            'status' => 'POSTED',
            'total' => 100,
            'created_by' => $setup['user']->id,
            'posted_by' => $setup['user']->id,
            'posted_at' => $returnAt,
        ]);

        SaleReturnItem::query()->create([
            'sale_return_id' => $return->id,
            'sale_item_id' => $saleItem->id,
            'finished_product_id' => $setup['product']->id,
            'unit_id' => $setup['unit']->id,
            'quantity' => $quantity,
            'base_quantity' => $quantity,
            'unit_price' => 100,
            'line_total' => 100,
        ]);
    }
}
