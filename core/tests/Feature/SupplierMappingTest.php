<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Supplier;
use App\Models\backend\Unit;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierMappingTest extends TestCase
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

    public function test_raw_material_and_part_use_separate_mapping_routes_and_tables(): void
    {
        [$rawMaterial, $part, $supplier] = $this->foundation();

        $this->postJson(route('inventory.raw-material-suppliers.store'), [
            'item_id' => $rawMaterial->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => 'SUP-CU-01',
            'lead_days' => 14,
            'is_preferred' => true,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->postJson(route('inventory.part-suppliers.store'), [
            'item_id' => $part->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => 'SUP-TR-01',
            'lead_days' => 7,
            'is_preferred' => false,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('raw_material_suppliers', [
            'raw_material_id' => $rawMaterial->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => 'SUP-CU-01',
            'lead_days' => 14,
            'is_preferred' => true,
        ]);
        $this->assertDatabaseHas('part_suppliers', [
            'part_id' => $part->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => 'SUP-TR-01',
            'lead_days' => 7,
            'is_preferred' => false,
        ]);
    }

    public function test_duplicate_item_supplier_mapping_is_rejected(): void
    {
        [$rawMaterial, , $supplier] = $this->foundation();
        $payload = [
            'item_id' => $rawMaterial->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => null,
            'lead_days' => 5,
            'is_preferred' => false,
        ];

        $this->postJson(route('inventory.raw-material-suppliers.store'), $payload)->assertCreated();
        $this->postJson(route('inventory.raw-material-suppliers.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
    }

    public function test_only_one_supplier_is_preferred_for_each_item(): void
    {
        [$rawMaterial, , $firstSupplier] = $this->foundation();
        $secondSupplier = Supplier::create([
            'code' => 'SUP-002',
            'name' => 'Delta Components',
            'is_active' => true,
        ]);

        foreach ([$firstSupplier, $secondSupplier] as $supplier) {
            $this->postJson(route('inventory.raw-material-suppliers.store'), [
                'item_id' => $rawMaterial->id,
                'supplier_id' => $supplier->id,
                'lead_days' => 10,
                'is_preferred' => true,
            ])->assertCreated();
        }

        $this->assertDatabaseHas('raw_material_suppliers', [
            'raw_material_id' => $rawMaterial->id,
            'supplier_id' => $firstSupplier->id,
            'is_preferred' => false,
        ]);
        $this->assertDatabaseHas('raw_material_suppliers', [
            'raw_material_id' => $rawMaterial->id,
            'supplier_id' => $secondSupplier->id,
            'is_preferred' => true,
        ]);
    }

    public function test_mapping_can_be_updated_and_deleted(): void
    {
        [$rawMaterial, , $supplier] = $this->foundation();

        $response = $this->postJson(route('inventory.raw-material-suppliers.store'), [
            'item_id' => $rawMaterial->id,
            'supplier_id' => $supplier->id,
            'lead_days' => 8,
            'is_preferred' => false,
        ])->assertCreated();

        $mappingId = $response->json('id');

        $this->putJson(route('inventory.raw-material-suppliers.update', $mappingId), [
            'item_id' => $rawMaterial->id,
            'supplier_id' => $supplier->id,
            'supplier_code' => 'UPDATED-CODE',
            'lead_days' => 12,
            'is_preferred' => true,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('raw_material_suppliers', [
            'id' => $mappingId,
            'supplier_code' => 'UPDATED-CODE',
            'lead_days' => 12,
            'is_preferred' => true,
        ]);

        $this->deleteJson(route('inventory.raw-material-suppliers.destroy', $mappingId))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('raw_material_suppliers', ['id' => $mappingId]);
    }

    public function test_each_supplier_mapping_module_has_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $rawPermissionId = DB::table('permissions')->where('key', 'inventory.raw_material_suppliers')->value('id');
        $partPermissionId = DB::table('permissions')->where('key', 'inventory.part_suppliers')->value('id');

        $this->assertNotNull($rawPermissionId);
        $this->assertNotNull($partPermissionId);
        $this->assertNotSame($rawPermissionId, $partPermissionId);
        $this->assertSame(9, DB::table('permission_routes')->where('permission_id', $rawPermissionId)->count());
        $this->assertSame(9, DB::table('permission_routes')->where('permission_id', $partPermissionId)->count());
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $rawPermissionId,
            'route_name' => 'inventory.raw-material-suppliers.index',
        ]);
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $partPermissionId,
            'route_name' => 'inventory.part-suppliers.index',
        ]);
    }

    /** @return array{RawMaterial, Part, Supplier} */
    private function foundation(): array
    {
        $piece = Unit::create(['name' => 'Piece', 'code' => 'PCS', 'precision' => 0, 'is_active' => true]);
        $rawCategory = RawMaterialCategory::create(['code' => 'RM', 'name' => 'Raw Materials', 'is_active' => true]);
        $partCategory = PartCategory::create(['code' => 'PART', 'name' => 'Parts', 'is_active' => true]);

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
        $supplier = Supplier::create([
            'code' => 'SUP-001',
            'name' => 'Prime Electronics',
            'is_active' => true,
        ]);

        return [$rawMaterial, $part, $supplier];
    }
}
