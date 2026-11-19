<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Unit;
use App\Services\Inventory\Ean13BarcodeService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BarcodeLabelTest extends TestCase
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

    public function test_preview_assigns_and_reuses_one_internal_ean_13(): void
    {
        $product = $this->product();

        $response = $this->post(route('inventory.barcode-labels.preview'), [
            'products' => [
                ['finished_product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        $response->assertOk()
            ->assertSee('A4 Barcode Preview')
            ->assertSee('3 labels')
            ->assertSee($product->name);

        $barcode = (string) $product->fresh()->barcode;

        $this->assertMatchesRegularExpression('/^\d{13}$/', $barcode);
        $this->assertTrue(app(Ean13BarcodeService::class)->isValid($barcode));
        $this->assertSame(6, substr_count($response->getContent(), $barcode));

        $this->post(route('inventory.barcode-labels.preview'), [
            'products' => [
                ['finished_product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $this->assertSame($barcode, $product->fresh()->barcode);
    }

    public function test_invalid_existing_barcode_is_not_silently_replaced(): void
    {
        $product = $this->product(['barcode' => '30000001']);

        $this->from(route('inventory.barcode-labels.index'))
            ->post(route('inventory.barcode-labels.preview'), [
                'products' => [
                    ['finished_product_id' => $product->id, 'quantity' => 1],
                ],
            ])
            ->assertRedirect(route('inventory.barcode-labels.index'))
            ->assertSessionHasErrors('products');

        $this->assertSame('30000001', $product->fresh()->barcode);
    }

    public function test_duplicate_products_and_excessive_label_jobs_are_rejected(): void
    {
        $product = $this->product();

        $this->post(route('inventory.barcode-labels.preview'), [
            'products' => [
                ['finished_product_id' => $product->id, 'quantity' => 250],
                ['finished_product_id' => $product->id, 'quantity' => 200],
            ],
        ])->assertSessionHasErrors('products.1.finished_product_id');

        $secondProduct = $this->product(['product_code' => 'FG-002', 'name' => 'Second Product']);

        $this->post(route('inventory.barcode-labels.preview'), [
            'products' => [
                ['finished_product_id' => $product->id, 'quantity' => 250],
                ['finished_product_id' => $secondProduct->id, 'quantity' => 200],
            ],
        ])->assertSessionHasErrors('products');
    }

    public function test_barcode_label_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')
            ->where('key', 'inventory.barcode_labels')
            ->value('id');

        $this->assertNotNull($permissionId);
        $this->assertSame(
            3,
            DB::table('permission_routes')->where('permission_id', $permissionId)->count(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): FinishedProduct
    {
        $category = FinishedProductCategory::query()->firstOrCreate(
            ['code' => 'FG'],
            ['name' => 'Finished Goods', 'is_active' => true],
        );
        $unit = Unit::query()->firstOrCreate(
            ['code' => 'PCS'],
            ['name' => 'Piece', 'precision' => 0, 'is_active' => true],
        );

        return FinishedProduct::query()->create(array_merge([
            'product_code' => 'FG-001',
            'barcode' => null,
            'name' => 'Internal Finished Product',
            'finished_product_category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ], $overrides));
    }
}
