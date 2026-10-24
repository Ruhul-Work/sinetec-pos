<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\Branch;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\backend\Warehouse;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReorderLevelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Authenticate::class,
            CheckPermission::class,
            EnsureBranchScope::class,
        ]);
    }

    public function test_each_inventory_domain_uses_its_own_reorder_table(): void
    {
        [$rawMaterial, $part, $finishedProduct, $warehouse] = $this->foundation();

        foreach ([
            ['inventory.raw-material-reorder-levels.store', 'raw_material_reorder_levels', 'raw_material_id', $rawMaterial->id],
            ['inventory.part-reorder-levels.store', 'part_reorder_levels', 'part_id', $part->id],
            ['inventory.finished-product-reorder-levels.store', 'finished_product_reorder_levels', 'finished_product_id', $finishedProduct->id],
        ] as [$routeName, $table, $column, $itemId]) {
            $this->postJson(route($routeName), [
                'item_id' => $itemId,
                'warehouse_id' => $warehouse->id,
                'minimum_quantity' => 10,
                'reorder_quantity' => 25,
                'maximum_quantity' => 100,
            ])->assertCreated()->assertJsonPath('success', true);

            $this->assertDatabaseHas($table, [
                $column => $itemId,
                'warehouse_id' => $warehouse->id,
                'minimum_quantity' => 10,
                'reorder_quantity' => 25,
                'maximum_quantity' => 100,
            ]);
        }
    }

    public function test_default_replenishment_and_maximum_are_optional(): void
    {
        [$rawMaterial, , , $warehouse] = $this->foundation();

        $this->postJson(route('inventory.raw-material-reorder-levels.store'), [
            'item_id' => $rawMaterial->id,
            'warehouse_id' => $warehouse->id,
            'minimum_quantity' => 12.5,
            'reorder_quantity' => '',
            'maximum_quantity' => '',
        ])->assertCreated();

        $this->assertDatabaseHas('raw_material_reorder_levels', [
            'raw_material_id' => $rawMaterial->id,
            'warehouse_id' => $warehouse->id,
            'minimum_quantity' => 12.5,
            'reorder_quantity' => 0,
            'maximum_quantity' => null,
        ]);
    }

    public function test_duplicate_item_and_warehouse_is_rejected(): void
    {
        [, $part, , $warehouse] = $this->foundation();
        $payload = [
            'item_id' => $part->id,
            'warehouse_id' => $warehouse->id,
            'minimum_quantity' => 5,
        ];

        $this->postJson(route('inventory.part-reorder-levels.store'), $payload)->assertCreated();
        $this->postJson(route('inventory.part-reorder-levels.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('warehouse_id');
    }

    public function test_quantity_rules_and_branch_scope_are_enforced(): void
    {
        [$rawMaterial, , , $warehouse, $branch] = $this->foundation();

        $this->postJson(route('inventory.raw-material-reorder-levels.store'), [
            'item_id' => $rawMaterial->id,
            'warehouse_id' => $warehouse->id,
            'minimum_quantity' => 20,
            'maximum_quantity' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('maximum_quantity');

        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'BR-002', 'is_active' => true]);
        $otherWarehouse = Warehouse::create([
            'branch_id' => $otherBranch->id,
            'name' => 'Other Warehouse',
            'code' => 'WH-002',
            'type' => 'store',
            'is_active' => true,
        ]);

        $this->withSession(['branch_scope' => $branch->id])
            ->postJson(route('inventory.raw-material-reorder-levels.store'), [
                'item_id' => $rawMaterial->id,
                'warehouse_id' => $otherWarehouse->id,
                'minimum_quantity' => 20,
            ])->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');
    }

    public function test_reorder_level_can_be_updated_and_deleted(): void
    {
        [, , $finishedProduct, $warehouse] = $this->foundation();

        $response = $this->postJson(route('inventory.finished-product-reorder-levels.store'), [
            'item_id' => $finishedProduct->id,
            'warehouse_id' => $warehouse->id,
            'minimum_quantity' => 5,
        ])->assertCreated();

        $levelId = $response->json('id');

        $this->putJson(route('inventory.finished-product-reorder-levels.update', $levelId), [
            'item_id' => $finishedProduct->id,
            'warehouse_id' => $warehouse->id,
            'minimum_quantity' => 8,
            'reorder_quantity' => 20,
            'maximum_quantity' => 50,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('finished_product_reorder_levels', [
            'id' => $levelId,
            'minimum_quantity' => 8,
            'reorder_quantity' => 20,
            'maximum_quantity' => 50,
        ]);

        $this->deleteJson(route('inventory.finished-product-reorder-levels.destroy', $levelId))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('finished_product_reorder_levels', ['id' => $levelId]);
    }

    public function test_each_reorder_module_has_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        foreach ([
            'inventory.raw_material_reorder_levels' => 'inventory.raw-material-reorder-levels.index',
            'inventory.part_reorder_levels' => 'inventory.part-reorder-levels.index',
            'inventory.finished_product_reorder_levels' => 'inventory.finished-product-reorder-levels.index',
        ] as $permissionKey => $indexRoute) {
            $permissionId = DB::table('permissions')->where('key', $permissionKey)->value('id');

            $this->assertNotNull($permissionId);
            $this->assertSame(9, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
            $this->assertDatabaseHas('permission_routes', [
                'permission_id' => $permissionId,
                'route_name' => $indexRoute,
            ]);
        }
    }

    /** @return array{RawMaterial, Part, FinishedProduct, Warehouse, Branch} */
    private function foundation(): array
    {
        $unit = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $finishedCategory = FinishedProductCategory::create(['code' => 'FG', 'name' => 'Finished Products', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Main Branch', 'code' => 'BR-001', 'is_active' => true]);
        $warehouse = Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-001',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);

        $rawMaterial = RawMaterial::create([
            'material_code' => 'RM-001',
            'name' => 'Copper Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::create([
            'part_code' => 'PT-001',
            'name' => 'Transformer',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'BOTH',
            'is_active' => true,
        ]);
        $finishedProduct = FinishedProduct::create([
            'product_code' => 'FG-001',
            'name' => 'Voltage Stabilizer',
            'finished_product_category_id' => $finishedCategory->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        return [$rawMaterial, $part, $finishedProduct, $warehouse, $branch];
    }
}
