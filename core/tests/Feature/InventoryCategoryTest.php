<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterialCategory;
use Database\Seeders\CorePermissionSeeder;
use Database\Seeders\InventoryCategorySeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCategoryTest extends TestCase
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

    public function test_each_inventory_domain_has_an_independent_category_master(): void
    {
        foreach ([
            'inventory.raw-material-categories.store' => 'raw_material_categories',
            'inventory.part-categories.store' => 'part_categories',
            'inventory.finished-product-categories.store' => 'finished_product_categories',
        ] as $routeName => $table) {
            $this->postJson(route($routeName), [
                'code' => 'COMMON',
                'name' => 'Common category',
                'is_active' => true,
            ])->assertCreated()->assertJsonPath('success', true);

            $this->assertDatabaseHas($table, [
                'code' => 'COMMON',
                'name' => 'Common category',
            ]);
        }
    }

    public function test_category_code_is_unique_inside_its_own_domain(): void
    {
        RawMaterialCategory::create([
            'code' => 'PCB',
            'name' => 'PCB Materials',
            'is_active' => true,
        ]);

        $this->postJson(route('inventory.raw-material-categories.store'), [
            'code' => 'pcb',
            'name' => 'Duplicate PCB',
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_category_hierarchy_cannot_contain_a_cycle(): void
    {
        $parent = PartCategory::create([
            'code' => 'BOARD',
            'name' => 'Boards',
            'is_active' => true,
        ]);
        $child = PartCategory::create([
            'parent_id' => $parent->id,
            'code' => 'PCB',
            'name' => 'PCB',
            'is_active' => true,
        ]);

        $this->putJson(route('inventory.part-categories.update', $parent), [
            'parent_id' => $child->id,
            'code' => $parent->code,
            'name' => $parent->name,
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_category_with_a_child_cannot_be_deleted(): void
    {
        $parent = RawMaterialCategory::create([
            'code' => 'ELECTRICAL',
            'name' => 'Electrical',
            'is_active' => true,
        ]);
        RawMaterialCategory::create([
            'parent_id' => $parent->id,
            'code' => 'WIRE',
            'name' => 'Wire',
            'is_active' => true,
        ]);

        $this->deleteJson(route('inventory.raw-material-categories.destroy', $parent))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($parent->fresh()->deleted_at);
    }

    public function test_each_category_module_is_mapped_to_its_own_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        foreach ([
            'inventory.raw_material_categories',
            'inventory.part_categories',
            'inventory.finished_product_categories',
        ] as $permissionKey) {
            $permissionId = \DB::table('permissions')->where('key', $permissionKey)->value('id');

            $this->assertNotNull($permissionId);
            $this->assertSame(
                8,
                \DB::table('permission_routes')->where('permission_id', $permissionId)->count()
            );
        }
    }

    public function test_sample_seeder_creates_two_hierarchical_categories_per_domain_without_duplicates(): void
    {
        $this->seed(InventoryCategorySeeder::class);
        $this->seed(InventoryCategorySeeder::class);

        foreach ([
            'raw_material_categories',
            'part_categories',
            'finished_product_categories',
        ] as $table) {
            $this->assertDatabaseCount($table, 2);
            $this->assertSame(1, \DB::table($table)->whereNotNull('parent_id')->count());
        }
    }
}
