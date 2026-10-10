<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RawMaterialTest extends TestCase
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

    public function test_a_raw_material_can_be_created_with_its_required_master_relations(): void
    {
        [$category, $unit] = $this->foundation();

        $this->postJson(route('inventory.raw-materials.store'), [
            'material_code' => 'rm-pcb-001',
            'barcode' => '10000001',
            'name' => 'Copper Clad Board',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'material',
            'specification' => '1.6 mm',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('raw_materials', [
            'material_code' => 'RM-PCB-001',
            'barcode' => '10000001',
            'name' => 'Copper Clad Board',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
        ]);
    }

    public function test_material_code_and_barcode_are_unique(): void
    {
        [$category, $unit] = $this->foundation();
        $this->material($category, $unit);

        $this->postJson(route('inventory.raw-materials.store'), [
            'material_code' => 'RM-PCB-001',
            'barcode' => '10000001',
            'name' => 'Duplicate',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['material_code', 'barcode']);
    }

    public function test_referenced_raw_material_cannot_be_deleted(): void
    {
        [$category, $baseUnit] = $this->foundation();
        $material = $this->material($category, $baseUnit);
        $alternateUnit = Unit::create([
            'name' => 'Sheet',
            'code' => 'SHEET',
            'precision' => 0,
            'is_active' => true,
        ]);

        DB::table('raw_material_units')->insert([
            'raw_material_id' => $material->id,
            'unit_id' => $alternateUnit->id,
            'conversion_to_base' => 1,
            'usage_type' => 'PURCHASE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson(route('inventory.raw-materials.destroy', $material))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($material->fresh()->deleted_at);
    }

    public function test_raw_material_routes_are_mapped_to_their_own_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')->where('key', 'inventory.raw_materials')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(8, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
    }

    /** @return array{RawMaterialCategory, Unit} */
    private function foundation(): array
    {
        $category = RawMaterialCategory::create([
            'code' => 'PCB_MATERIALS',
            'name' => 'PCB Materials',
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);

        return [$category, $unit];
    }

    private function material(RawMaterialCategory $category, Unit $unit): RawMaterial
    {
        return RawMaterial::create([
            'material_code' => 'RM-PCB-001',
            'barcode' => '10000001',
            'name' => 'Copper Clad Board',
            'raw_material_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
    }
}
