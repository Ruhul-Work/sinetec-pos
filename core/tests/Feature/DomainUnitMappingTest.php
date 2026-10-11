<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainUnitMappingTest extends TestCase
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

    public function test_each_domain_stores_its_unit_mapping_in_the_separate_table(): void
    {
        [$piece, $box, $rawMaterial, $part, $product] = $this->foundation();

        $cases = [
            ['raw-materials', 'raw_material_units', 'raw_material_id', $rawMaterial->id, 'PURCHASE'],
            ['parts', 'part_units', 'part_id', $part->id, 'PRODUCTION'],
            ['finished-products', 'finished_product_units', 'finished_product_id', $product->id, 'SALE'],
        ];

        foreach ($cases as [$domain, $table, $itemColumn, $itemId, $usage]) {
            $this->postJson(route('inventory.item-units.store', $domain), [
                'item_id' => $itemId,
                'unit_id' => $box->id,
                'conversion_to_base' => 12,
                'usage_type' => $usage,
            ])->assertCreated()->assertJsonPath('success', true);

            $this->assertDatabaseHas($table, [
                $itemColumn => $itemId,
                'unit_id' => $box->id,
                'conversion_to_base' => 12,
                'usage_type' => $usage,
            ]);
        }
    }

    public function test_base_unit_and_duplicate_alternative_unit_are_rejected(): void
    {
        [$piece, $box, $rawMaterial] = $this->foundation();
        $url = route('inventory.item-units.store', 'raw-materials');

        $this->postJson($url, [
            'item_id' => $rawMaterial->id,
            'unit_id' => $piece->id,
            'conversion_to_base' => 1,
            'usage_type' => 'ALL',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        $payload = [
            'item_id' => $rawMaterial->id,
            'unit_id' => $box->id,
            'conversion_to_base' => 12,
            'usage_type' => 'ALL',
        ];
        $this->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_usage_is_limited_by_domain_business_rules(): void
    {
        [, $box, $rawMaterial, $part, $product] = $this->foundation();

        $this->postJson(route('inventory.item-units.store', 'raw-materials'), [
            'item_id' => $rawMaterial->id,
            'unit_id' => $box->id,
            'conversion_to_base' => 12,
            'usage_type' => 'SALE',
        ])->assertUnprocessable()->assertJsonValidationErrors('usage_type');

        $this->postJson(route('inventory.item-units.store', 'parts'), [
            'item_id' => $part->id,
            'unit_id' => $box->id,
            'conversion_to_base' => 12,
            'usage_type' => 'SALE',
        ])->assertUnprocessable()->assertJsonValidationErrors('usage_type');

        $this->postJson(route('inventory.item-units.store', 'finished-products'), [
            'item_id' => $product->id,
            'unit_id' => $box->id,
            'conversion_to_base' => 4,
            'usage_type' => 'PURCHASE',
        ])->assertUnprocessable()->assertJsonValidationErrors('usage_type');
    }

    public function test_item_base_unit_endpoint_returns_the_selected_items_base_unit(): void
    {
        [$piece, , $rawMaterial] = $this->foundation();

        $this->getJson(route('inventory.item-units.item-base-unit', [
            'domain' => 'raw-materials',
            'item' => $rawMaterial->id,
        ]))->assertOk()
            ->assertJsonPath('base_unit.id', $piece->id)
            ->assertJsonPath('base_unit.code', 'PCS');
    }

    public function test_item_unit_routes_are_mapped_to_their_own_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')->where('key', 'inventory.item_units')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(8, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
    }

    /** @return array{Unit, Unit, RawMaterial, Part, FinishedProduct} */
    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $box = Unit::create(['name' => 'Box', 'code' => 'BOX', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);
        $productCategory = FinishedProductCategory::create(['code' => 'FG', 'name' => 'Finished Goods', 'is_active' => true]);

        $rawMaterial = RawMaterial::create([
            'material_code' => 'RM-001',
            'name' => 'Copper Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $piece->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::create([
            'part_code' => 'PT-001',
            'name' => 'Transformer',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $piece->id,
            'procurement_mode' => 'BOTH',
            'is_active' => true,
        ]);
        $product = FinishedProduct::create([
            'product_code' => 'FG-001',
            'name' => 'Home UPS',
            'finished_product_category_id' => $productCategory->id,
            'base_unit_id' => $piece->id,
            'is_active' => true,
        ]);

        return [$piece, $box, $rawMaterial, $part, $product];
    }
}
