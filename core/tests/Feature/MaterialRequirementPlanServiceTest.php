<?php

namespace Tests\Feature;

use App\Enums\Reporting\DemandPlanStatus;
use App\Enums\Reporting\MrpStatus;
use App\Enums\Reporting\ReportingPeriodStatus;
use App\Exceptions\Reporting\InvalidMrpException;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Role;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Models\Production\ProductBom;
use App\Models\Production\ProductBomItem;
use App\Models\Reporting\DemandPlan;
use App\Models\Reporting\ReportingPeriod;
use App\Services\Reporting\MaterialRequirementPlanService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaterialRequirementPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mrp_explodes_bom_and_offsets_current_inventory(): void
    {
        $setup = $this->setupMrpData();

        // Demand Plan has forecast of 50 units and safety of 10 units = 60 units need
        $demandPlan = $this->createApprovedDemandPlan($setup, 50, 10);

        // Put 20 units of Finished Product in stock -> Net needed to produce = 40 units
        DB::table('finished_product_stock_balances')->insert([
            'finished_product_id' => $setup['product']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 20,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Each product BOM needs 2 kg of Raw Material (with 0% wastage) for 1 product
        // 40 units * 2 kg = 80 kg gross requirement
        // Put 30 kg of Raw Material in stock -> Net shortage = 50 kg
        DB::table('raw_material_stock_balances')->insert([
            'raw_material_id' => $setup['rawMaterial']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 30,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(MaterialRequirementPlanService::class);
        $mrp = $service->generate($demandPlan, $setup['user']->id);

        $this->assertSame(MrpStatus::Draft, $mrp->status);
        $this->assertCount(1, $mrp->finishedProductLines);
        $this->assertCount(1, $mrp->componentLines);

        $fpLine = $mrp->finishedProductLines->first();
        $this->assertSame('50.000000', $fpLine->demand_quantity);
        $this->assertSame('10.000000', $fpLine->safety_quantity);
        $this->assertSame('20.000000', $fpLine->available_stock);
        $this->assertSame('40.000000', $fpLine->net_required);

        $compLine = $mrp->componentLines->first();
        $this->assertSame('80.000000', $compLine->gross_requirement);
        $this->assertSame('30.000000', $compLine->available_stock);
        $this->assertSame('50.000000', $compLine->net_requirement);
    }

    public function test_purchase_recommendation_groups_by_preferred_supplier_and_prefills_po(): void
    {
        $setup = $this->setupMrpData();
        $demandPlan = $this->createApprovedDemandPlan($setup, 100, 0);

        // Raw Material needed: 100 * 2 = 200 kg. Zero stock -> net shortage 200 kg.
        $service = app(MaterialRequirementPlanService::class);
        $mrp = $service->generate($demandPlan, $setup['user']->id);
        $service->approve($mrp, $setup['user']->id);

        // Map supplier to raw material
        $supplier = Supplier::query()->create([
            'name' => 'Prime Chemicals Ltd',
            'code' => 'SUP-001',
            'is_active' => true,
        ]);
        DB::table('raw_material_suppliers')->insert([
            'raw_material_id' => $setup['rawMaterial']->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => 'PC-RAW-01',
            'lead_days' => 7,
            'is_preferred' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rateSheetId = DB::table('monthly_rate_sheets')->insertGetId([
            'reporting_period_id' => $setup['period']->id,
            'sheet_no' => 'MRS-202612-TEST',
            'status' => 'LOCKED',
            'created_by' => $setup['user']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monthly_raw_material_rates')->insert([
            'monthly_rate_sheet_id' => $rateSheetId,
            'raw_material_id' => $setup['rawMaterial']->id,
            'rate' => 125,
            'rate_basis' => 'MANUAL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recommendations = $service->purchaseRecommendations($mrp);

        $this->assertSame(1, $recommendations['total_items']);
        $this->assertSame(1, $recommendations['total_suppliers']);
        $this->assertCount(1, $recommendations['suppliers']);

        $supplierRec = $recommendations['suppliers'][0];
        $this->assertSame($supplier->id, $supplierRec['supplier_id']);
        $this->assertSame('Prime Chemicals Ltd', $supplierRec['supplier_name']);
        $this->assertSame(7, $supplierRec['lead_days']);
        $this->assertCount(1, $supplierRec['items']);
        $this->assertSame('200.000000', $supplierRec['items'][0]['net_requirement']);
        $this->assertSame('125.00', $supplierRec['items'][0]['estimated_unit_rate']);
        $this->assertSame('25000.00', $supplierRec['items'][0]['estimated_total']);

        // Test creating PO with prefilled MRP and supplier
        $response = $this->actingAs($setup['user'])
            ->get(route('purchase.orders.create', [
                'mrp_id' => $mrp->id,
                'supplier_id' => $supplier->id,
            ]));

        $response->assertOk();
        $response->assertSee('Prime Chemicals Ltd');
        $response->assertSee('200');
        $response->assertSee('Generated from Material Requirement Plan');
        $response->assertSee('name="material_requirement_plan_id" value="'.$mrp->id.'"', false);

        $storeResponse = $this->actingAs($setup['user'])
            ->post(route('purchase.orders.store'), [
                'material_requirement_plan_id' => $mrp->id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => $setup['warehouse']->id,
                'order_date' => '2026-11-24',
                'expected_date' => null,
                'discount_type' => 'FLAT',
                'discount_value' => 0,
                'tax' => 0,
                'note' => 'Generated from test MRP',
                'lines' => [[
                    'domain' => 'raw_material',
                    'item_id' => $setup['rawMaterial']->id,
                    'unit_id' => $setup['unit']->id,
                    'quantity' => 200,
                    'unit_rate' => 120,
                ]],
            ]);

        $order = DB::table('purchase_order')
            ->where('material_requirement_plan_id', $mrp->id)
            ->first();

        $storeResponse->assertRedirect(route('purchase.orders.show', $order->id));
        $this->assertSame($supplier->id, (int) $order->supplier_id);
        $this->assertSame($setup['branch']->id, (int) $order->branch_id);
    }

    public function test_mrp_offsets_safety_stock_and_open_incoming_purchase_orders(): void
    {
        $setup = $this->setupMrpData();
        $demandPlan = $this->createApprovedDemandPlan($setup, 100, 0);

        DB::table('raw_material_stock_balances')->insert([
            'raw_material_id' => $setup['rawMaterial']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'quantity' => 30,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('raw_material_reorder_levels')->insert([
            'raw_material_id' => $setup['rawMaterial']->id,
            'warehouse_id' => $setup['warehouse']->id,
            'minimum_quantity' => 20,
            'reorder_quantity' => 0,
            'maximum_quantity' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Incoming Supplier',
            'code' => 'SUP-IN',
            'is_active' => true,
        ]);
        $purchaseOrderId = DB::table('purchase_order')->insertGetId([
            'order_no' => 'PO-INCOMING-001',
            'branch_id' => $setup['branch']->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $setup['warehouse']->id,
            'order_date' => '2026-11-20',
            'status' => 'PARTIAL',
            'subtotal' => 6000,
            'discount_type' => 'FLAT',
            'discount_value' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 6000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_order_item')->insert([
            'purchase_order_id' => $purchaseOrderId,
            'raw_material_id' => $setup['rawMaterial']->id,
            'part_id' => null,
            'unit_id' => $setup['unit']->id,
            'conversion_to_base' => 1,
            'quantity' => 50,
            'base_quantity' => 50,
            'received_base_quantity' => 10,
            'unit_rate' => 120,
            'line_total' => 6000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mrp = app(MaterialRequirementPlanService::class)->generate(
            $demandPlan,
            $setup['user']->id,
        );
        $line = $mrp->componentLines->first();

        $this->assertSame('200.000000', $line->gross_requirement);
        $this->assertSame('30.000000', $line->available_stock);
        $this->assertSame('20.000000', $line->safety_quantity);
        $this->assertSame('40.000000', $line->incoming_quantity);
        $this->assertSame('150.000000', $line->net_requirement);
        $this->assertSame('150.000000', $line->buy_quantity);
        $this->assertSame('PURCHASE', $line->source_hint);
    }

    public function test_mrp_blocks_generation_when_target_period_has_no_valid_product_bom(): void
    {
        $setup = $this->setupMrpData();
        $setup['bom']->update([
            'effective_from' => '2027-01-01',
            'effective_to' => null,
        ]);
        $demandPlan = $this->createApprovedDemandPlan($setup, 10, 0);

        $this->expectException(InvalidMrpException::class);
        $this->expectExceptionMessage('no active Product BOM is valid on 01 Dec 2026');

        app(MaterialRequirementPlanService::class)->generate(
            $demandPlan,
            $setup['user']->id,
        );
    }

    public function test_part_make_buy_and_both_modes_create_explicit_source_quantities(): void
    {
        $setup = $this->setupMrpData();
        $setup['bom']->items()->delete();
        $partCategory = PartCategory::query()->create([
            'name' => 'Planning Parts',
            'code' => 'PLAN-PART',
            'is_active' => true,
        ]);
        $makePart = $this->createPart($partCategory, $setup['pcsUnit'], 'MAKE', 'PT-MAKE');
        $buyPart = $this->createPart($partCategory, $setup['pcsUnit'], 'BUY', 'PT-BUY');
        $bothPart = $this->createPart($partCategory, $setup['pcsUnit'], 'BOTH', 'PT-BOTH');

        foreach ([$makePart, $buyPart, $bothPart] as $part) {
            ProductBomItem::query()->create([
                'product_bom_id' => $setup['bom']->id,
                'raw_material_id' => null,
                'part_id' => $part->id,
                'unit_id' => $setup['pcsUnit']->id,
                'required_quantity' => 1,
                'base_quantity' => 1,
                'wastage_percent' => 0,
            ]);
        }

        $supplier = Supplier::query()->create([
            'name' => 'Part Supplier',
            'code' => 'SUP-PART',
            'is_active' => true,
        ]);
        DB::table('part_suppliers')->insert([
            'part_id' => $bothPart->id,
            'supplier_id' => $supplier->id,
            'lead_days' => 5,
            'is_preferred' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $demandPlan = $this->createApprovedDemandPlan($setup, 10, 0);
        $mrp = app(MaterialRequirementPlanService::class)->generate(
            $demandPlan,
            $setup['user']->id,
        );
        $lines = $mrp->componentLines->keyBy('part_id');

        $this->assertSame('PRODUCTION', $lines[$makePart->id]->source_hint);
        $this->assertSame('10.000000', $lines[$makePart->id]->make_quantity);
        $this->assertSame('0.000000', $lines[$makePart->id]->buy_quantity);

        $this->assertSame('PURCHASE', $lines[$buyPart->id]->source_hint);
        $this->assertSame('0.000000', $lines[$buyPart->id]->make_quantity);
        $this->assertSame('10.000000', $lines[$buyPart->id]->buy_quantity);

        $this->assertSame('PURCHASE', $lines[$bothPart->id]->source_hint);
        $this->assertSame('10.000000', $lines[$bothPart->id]->buy_quantity);
    }

    public function test_fractional_purchase_requirement_is_not_rounded_to_zero(): void
    {
        $setup = $this->setupMrpData();
        $setup['unit']->update(['precision' => 0]);
        $setup['bom']->items()->firstOrFail()->update([
            'required_quantity' => 0.4,
            'base_quantity' => 0.4,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Fractional Material Supplier',
            'code' => 'SUP-FRACTION',
            'is_active' => true,
        ]);
        DB::table('raw_material_suppliers')->insert([
            'raw_material_id' => $setup['rawMaterial']->id,
            'supplier_id' => $supplier->id,
            'lead_days' => 2,
            'is_preferred' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $demandPlan = $this->createApprovedDemandPlan($setup, 1, 0);
        $service = app(MaterialRequirementPlanService::class);
        $mrp = $service->generate($demandPlan, $setup['user']->id);
        $recommendations = $service->purchaseRecommendations($mrp);
        $item = $recommendations['suppliers'][0]['items'][0];

        $this->assertSame('0.400000', $item['purchase_quantity']);
        $this->assertSame('0.4', $item['quantity_formatted']);
        $this->assertSame('0.4', $item['order_line']['quantity_input']);
    }

    /** @return array<string, mixed> */
    private function setupMrpData(): array
    {
        $this->seed(CorePermissionSeeder::class);
        $role = Role::query()->create(['name' => 'Super Admin', 'key' => 'super_admin', 'is_super' => 1]);
        $branch = Branch::query()->create(['name' => 'Chittagong Factory', 'code' => 'CTG-FAC', 'is_active' => true]);
        $warehouse = Warehouse::query()->create(['branch_id' => $branch->id, 'name' => 'Main Warehouse', 'code' => 'CTG-WH1', 'is_active' => true]);
        $user = User::query()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'name' => 'Factory Manager',
            'email' => 'manager.test@sinetec.local',
            'password' => Hash::make('secret123'),
            'status' => true,
        ]);
        $unit = Unit::query()->create(['name' => 'Kilogram', 'code' => 'KG', 'precision' => 2, 'is_active' => true]);
        $pcsUnit = Unit::query()->create(['name' => 'Pieces', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);

        $rmCat = RawMaterialCategory::query()->create(['name' => 'Chemicals', 'code' => 'CHEM', 'is_active' => true]);
        $rawMaterial = RawMaterial::query()->create([
            'material_code' => 'RM-001',
            'name' => 'Polymer Resin',
            'raw_material_category_id' => $rmCat->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        $fpCat = FinishedProductCategory::query()->create(['name' => 'Electronics', 'code' => 'ELEC', 'is_active' => true]);
        $product = FinishedProduct::query()->create([
            'product_code' => 'FG-001',
            'name' => 'Smart Inverter Case',
            'finished_product_category_id' => $fpCat->id,
            'base_unit_id' => $pcsUnit->id,
            'retail_price' => 500,
            'wholesale_price' => 450,
            'is_active' => true,
        ]);

        $period = ReportingPeriod::query()->create([
            'year' => 2026,
            'month' => 12,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'status' => ReportingPeriodStatus::Open,
        ]);

        // Create Active Product BOM: 1 Product = 2 KG of Raw Material
        $bom = ProductBom::query()->create([
            'finished_product_id' => $product->id,
            'version' => '1.0',
            'output_quantity' => 1,
            'status' => 'ACTIVE',
            'effective_from' => '2026-01-01',
            'created_by' => $user->id,
        ]);

        ProductBomItem::query()->create([
            'product_bom_id' => $bom->id,
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => 2,
            'base_quantity' => 2,
            'wastage_percent' => 0,
        ]);

        return compact('branch', 'warehouse', 'user', 'unit', 'pcsUnit', 'rawMaterial', 'product', 'period', 'bom');
    }

    private function createApprovedDemandPlan(array $setup, float $forecast, float $safety): DemandPlan
    {
        $plan = DemandPlan::query()->create([
            'plan_no' => 'DP-202612-TEST',
            'branch_id' => $setup['branch']->id,
            'reporting_period_id' => $setup['period']->id,
            'history_months' => 3,
            'status' => DemandPlanStatus::Approved,
            'created_by' => $setup['user']->id,
            'approved_by' => $setup['user']->id,
            'approved_at' => now(),
        ]);

        $plan->items()->create([
            'finished_product_id' => $setup['product']->id,
            'historical_net_quantity' => 100,
            'suggested_quantity' => 33.33,
            'forecast_quantity' => $forecast,
            'safety_quantity' => $safety,
            'basis' => 'Test Planning',
        ]);

        return $plan;
    }

    private function createPart(
        PartCategory $category,
        Unit $unit,
        string $mode,
        string $code,
    ): Part {
        return Part::query()->create([
            'part_code' => $code,
            'name' => str($code)->replace('-', ' ')->title()->toString(),
            'part_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => $mode,
            'is_active' => true,
        ]);
    }
}
