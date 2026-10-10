<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Unit;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinishedProductTest extends TestCase
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

    public function test_a_finished_product_can_be_created_with_default_selling_prices(): void
    {
        [$category, $unit] = $this->foundation();

        $this->postJson(route('inventory.finished-products.store'), [
            'product_code' => 'fg-ups-001',
            'barcode' => '30000001',
            'name' => 'Home UPS 1200VA',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => '14500.50',
            'wholesale_price' => '13750',
            'specification' => '1200VA, 12V',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('finished_products', [
            'product_code' => 'FG-UPS-001',
            'barcode' => '30000001',
            'name' => 'Home UPS 1200VA',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => '14500.5000',
            'wholesale_price' => '13750.0000',
        ]);
    }

    public function test_product_code_barcode_and_non_negative_prices_are_validated(): void
    {
        [$category, $unit] = $this->foundation();
        $this->product($category, $unit);

        $this->postJson(route('inventory.finished-products.store'), [
            'product_code' => 'FG-UPS-001',
            'barcode' => '30000001',
            'name' => 'Duplicate',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => -1,
            'wholesale_price' => -5,
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['product_code', 'barcode', 'retail_price', 'wholesale_price']);
    }

    public function test_referenced_finished_product_cannot_be_deleted(): void
    {
        [$category, $baseUnit] = $this->foundation();
        $product = $this->product($category, $baseUnit);
        $alternateUnit = Unit::create([
            'name' => 'Carton',
            'code' => 'CTN',
            'precision' => 0,
            'is_active' => true,
        ]);

        DB::table('finished_product_units')->insert([
            'finished_product_id' => $product->id,
            'unit_id' => $alternateUnit->id,
            'conversion_to_base' => 4,
            'usage_type' => 'SALE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson(route('inventory.finished-products.destroy', $product))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertNull($product->fresh()->deleted_at);
    }

    public function test_finished_product_routes_are_mapped_to_their_own_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')->where('key', 'inventory.finished_products')->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(8, DB::table('permission_routes')->where('permission_id', $permissionId)->count());
    }

    /** @return array{FinishedProductCategory, Unit} */
    private function foundation(): array
    {
        $category = FinishedProductCategory::create([
            'code' => 'UPS',
            'name' => 'UPS Systems',
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

    private function product(FinishedProductCategory $category, Unit $unit): FinishedProduct
    {
        return FinishedProduct::create([
            'product_code' => 'FG-UPS-001',
            'barcode' => '30000001',
            'name' => 'Home UPS 1200VA',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'retail_price' => 14500.50,
            'wholesale_price' => 13750,
            'is_active' => true,
        ]);
    }
}
