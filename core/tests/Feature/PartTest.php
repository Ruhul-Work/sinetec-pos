<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\Unit;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartTest extends TestCase
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

    public function test_a_part_can_be_created_with_procurement_mode_and_required_relations(): void
    {
        [$category, $unit] = $this->foundation();

        $this->postJson(route('inventory.parts.store'), [
            'part_code' => 'pt-transformer-001',
            'barcode' => '20000001',
            'name' => 'Step-down Transformer',
            'part_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'both',
            'specification' => '220V to 12V, 5A',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('parts', [
            'part_code' => 'PT-TRANSFORMER-001',
            'barcode' => '20000001',
            'name' => 'Step-down Transformer',
            'part_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'BOTH',
        ]);
    }

    public function test_part_code_barcode_and_procurement_mode_are_validated(): void
    {
        [$category, $unit] = $this->foundation();
        $this->part($category, $unit);

        $this->postJson(route('inventory.parts.store'), [
            'part_code' => 'PT-TRANSFORMER-001',
            'barcode' => '20000001',
            'name' => 'Duplicate',
            'part_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'OTHER',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['part_code', 'barcode', 'procurement_mode']);
    }

    public function test_referenced_part_cannot_be_deleted(): void
    {
        [$category, $baseUnit] = $this->foundation();
        $part = $this->part($category, $baseUnit);
        $alternateUnit = Unit::create([
            'name' => 'Box',
            'code' => 'BOX',
            'precision' => 0,
            'is_active' => true,
        ]);

        DB::table('part_units')->insert([
            'part_id' => $part->id,
            'unit_id' => $alternateUnit->id,
            'conversion_to_base' => 10,
            'usage_type' => 'PURCHASE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson(route('inventory.parts.destroy', $part))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($part->fresh()->deleted_at);
    }

    public function test_part_routes_are_mapped_to_their_own_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')->where('key', 'inventory.parts')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(8, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
    }

    /** @return array{PartCategory, Unit} */
    private function foundation(): array
    {
        $category = PartCategory::create([
            'code' => 'TRANSFORMERS',
            'name' => 'Transformers',
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

    private function part(PartCategory $category, Unit $unit): Part
    {
        return Part::create([
            'part_code' => 'PT-TRANSFORMER-001',
            'barcode' => '20000001',
            'name' => 'Step-down Transformer',
            'part_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'BOTH',
            'is_active' => true,
        ]);
    }
}
