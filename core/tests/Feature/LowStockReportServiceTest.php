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
use App\Models\backend\Role;
use App\Models\backend\Unit;
use App\Models\backend\User;
use App\Models\backend\Warehouse;
use App\Services\Reporting\LowStockReportService;
use Database\Seeders\CorePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LowStockReportServiceTest extends TestCase
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

    public function test_report_combines_all_domains_and_calculates_alerts(): void
    {
        $setup = $this->foundation();

        $this->insertReorderLevel(
            'raw_material_reorder_levels',
            'raw_material_id',
            $setup['raw_material']->id,
            $setup['warehouse']->id,
            10,
            25,
            100,
        );
        $this->insertReorderLevel(
            'part_reorder_levels',
            'part_id',
            $setup['part']->id,
            $setup['warehouse']->id,
            10,
            0,
            20,
        );
        $this->insertReorderLevel(
            'finished_product_reorder_levels',
            'finished_product_id',
            $setup['finished_product']->id,
            $setup['warehouse']->id,
            10,
            0,
            null,
        );

        $this->insertBalance(
            'raw_material_stock_balances',
            'raw_material_id',
            $setup['raw_material']->id,
            $setup['warehouse']->id,
            0,
        );
        $this->insertBalance(
            'part_stock_balances',
            'part_id',
            $setup['part']->id,
            $setup['warehouse']->id,
            5,
        );
        $this->insertBalance(
            'finished_product_stock_balances',
            'finished_product_id',
            $setup['finished_product']->id,
            $setup['warehouse']->id,
            10,
        );

        $service = app(LowStockReportService::class);
        $rows = $service->query($setup['branch']->id)->get()->keyBy('domain');
        $summary = $service->summary($setup['branch']->id);

        $this->assertCount(3, $rows);
        $this->assertSame('OUT_OF_STOCK', $rows['RAW_MATERIAL']->alert_status);
        $this->assertSame('PURCHASE', $rows['RAW_MATERIAL']->action_type);
        $this->assertSame(25.0, $service->suggestedQuantity($rows['RAW_MATERIAL']));

        $this->assertSame('LOW_STOCK', $rows['PART']->alert_status);
        $this->assertSame('PRODUCTION', $rows['PART']->action_type);
        $this->assertSame(15.0, $service->suggestedQuantity($rows['PART']));

        $this->assertSame('REORDER_POINT', $rows['FINISHED_PRODUCT']->alert_status);
        $this->assertSame('ASSEMBLY', $rows['FINISHED_PRODUCT']->action_type);
        $this->assertNull($service->suggestedQuantity($rows['FINISHED_PRODUCT']));

        $this->assertSame([
            'total_alerts' => 3,
            'out_of_stock' => 1,
            'low_stock' => 1,
            'reorder_point' => 1,
        ], $summary);
    }

    public function test_filters_and_ajax_register_are_branch_safe(): void
    {
        $setup = $this->foundation();
        $this->insertReorderLevel(
            'raw_material_reorder_levels',
            'raw_material_id',
            $setup['raw_material']->id,
            $setup['warehouse']->id,
            10,
            20,
            null,
        );

        $this->actingAs($setup['user'])
            ->get(route('planning.low-stock.index'))
            ->assertOk()
            ->assertSee('Warehouse Low-Stock Register');

        $response = $this->actingAs($setup['user'])
            ->postJson(route('planning.low-stock.list.ajax'), [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'branch_id' => $setup['branch']->id,
                'domain' => 'RAW_MATERIAL',
                'alert_status' => 'OUT_OF_STOCK',
                'search' => ['value' => 'Copper'],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('recordsFiltered'));
        $this->assertStringContainsString('Copper Wire', $response->json('data.0.3'));
        $this->assertStringContainsString('Out of Stock', $response->json('data.0.1'));
    }

    public function test_low_stock_routes_have_an_independent_permission(): void
    {
        $this->seed(CorePermissionSeeder::class);

        $permissionId = DB::table('permissions')
            ->where('key', 'planning.low_stock')
            ->value('id');

        $this->assertNotNull($permissionId);
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'planning.low-stock.index',
        ]);
        $this->assertDatabaseHas('permission_routes', [
            'permission_id' => $permissionId,
            'route_name' => 'planning.low-stock.list.ajax',
        ]);
    }

    /** @return array<string, mixed> */
    private function foundation(): array
    {
        $unit = Unit::query()->create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $branch = Branch::query()->create([
            'name' => 'Main Branch',
            'code' => 'BR-001',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Main Warehouse',
            'code' => 'WH-001',
            'type' => 'store',
            'is_default' => true,
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'name' => 'Super Admin',
            'key' => 'super_admin',
            'is_super' => true,
        ]);
        $user = User::query()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'name' => 'Planning Admin',
            'email' => 'planning.admin@sinetec.test',
            'password' => Hash::make('secret123'),
            'status' => true,
        ]);
        $rawCategory = RawMaterialCategory::query()->create([
            'code' => 'RM',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $partCategory = PartCategory::query()->create([
            'code' => 'PART',
            'name' => 'Parts',
            'is_active' => true,
        ]);
        $finishedCategory = FinishedProductCategory::query()->create([
            'code' => 'FG',
            'name' => 'Finished Products',
            'is_active' => true,
        ]);
        $rawMaterial = RawMaterial::query()->create([
            'material_code' => 'RM-001',
            'name' => 'Copper Wire',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::query()->create([
            'part_code' => 'PT-001',
            'name' => 'Internal Coil',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'MAKE',
            'is_active' => true,
        ]);
        $finishedProduct = FinishedProduct::query()->create([
            'product_code' => 'FG-001',
            'name' => 'Power Unit',
            'finished_product_category_id' => $finishedCategory->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        return [
            'branch' => $branch,
            'warehouse' => $warehouse,
            'user' => $user,
            'raw_material' => $rawMaterial,
            'part' => $part,
            'finished_product' => $finishedProduct,
        ];
    }

    private function insertReorderLevel(
        string $table,
        string $itemColumn,
        int $itemId,
        int $warehouseId,
        float $minimum,
        float $reorder,
        ?float $maximum,
    ): void {
        DB::table($table)->insert([
            $itemColumn => $itemId,
            'warehouse_id' => $warehouseId,
            'minimum_quantity' => $minimum,
            'reorder_quantity' => $reorder,
            'maximum_quantity' => $maximum,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBalance(
        string $table,
        string $itemColumn,
        int $itemId,
        int $warehouseId,
        float $quantity,
    ): void {
        DB::table($table)->insert([
            $itemColumn => $itemId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
