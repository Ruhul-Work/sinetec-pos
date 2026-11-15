<?php

namespace Tests\Feature;

use App\Models\backend\FinishedProduct;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\Production\ProductBom;
use App\Services\Production\ProductBomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductBomTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_component_bom_stores_exactly_one_component_per_line(): void
    {
        [$unit, $material, $part, $product] = $this->productBomSetup();
        $service = app(ProductBomService::class);

        $bom = $service->createDraft(
            $this->header($product, 'V1'),
            [
                $this->line('RAW_MATERIAL', $material->id, $unit->id, '2.5'),
                $this->line('PART', $part->id, $unit->id, '3'),
            ],
            null,
        );

        $this->assertDatabaseHas('product_bom_items', [
            'product_bom_id' => $bom->id,
            'raw_material_id' => $material->id,
            'part_id' => null,
            'base_quantity' => 2.5,
        ]);
        $this->assertDatabaseHas('product_bom_items', [
            'product_bom_id' => $bom->id,
            'raw_material_id' => null,
            'part_id' => $part->id,
            'base_quantity' => 3,
        ]);
    }

    public function test_only_one_bom_version_is_active_for_a_finished_product(): void
    {
        [$unit, $material, $part, $product] = $this->productBomSetup();
        $service = app(ProductBomService::class);
        $lines = [
            $this->line('RAW_MATERIAL', $material->id, $unit->id, '1'),
            $this->line('PART', $part->id, $unit->id, '1'),
        ];

        $firstBom = $service->createDraft($this->header($product, 'V1'), $lines, null);
        $service->activate($firstBom);
        $secondBom = $service->createDraft($this->header($product, 'V2'), $lines, null);
        $service->activate($secondBom);

        $this->assertSame('INACTIVE', ProductBom::findOrFail($firstBom->id)->status);
        $this->assertSame('ACTIVE', ProductBom::findOrFail($secondBom->id)->status);
    }

    public function test_same_component_cannot_appear_twice_in_a_bom(): void
    {
        [$unit, $material, , $product] = $this->productBomSetup();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Each component may be used only once');

        app(ProductBomService::class)->createDraft(
            $this->header($product, 'V1'),
            [
                $this->line('RAW_MATERIAL', $material->id, $unit->id, '1'),
                $this->line('RAW_MATERIAL', $material->id, $unit->id, '2'),
            ],
            null,
        );
    }

    public function test_expired_product_bom_cannot_be_activated(): void
    {
        [$unit, $material, , $product] = $this->productBomSetup();
        $service = app(ProductBomService::class);
        $header = $this->header($product, 'V1');
        $header['effective_from'] = now()->subMonth()->toDateString();
        $header['effective_to'] = now()->subDay()->toDateString();
        $bom = $service->createDraft(
            $header,
            [$this->line('RAW_MATERIAL', $material->id, $unit->id, '1')],
            null,
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This BOM has expired');

        $service->activate($bom);
    }

    public function test_released_product_bom_validity_can_be_extended(): void
    {
        [$unit, , $part, $product] = $this->productBomSetup();
        $service = app(ProductBomService::class);
        $header = $this->header($product, 'V1');
        $header['effective_to'] = now()->addDay()->toDateString();
        $bom = $service->createDraft(
            $header,
            [$this->line('PART', $part->id, $unit->id, '1')],
            null,
        );
        $bom = $service->activate($bom);
        $originalItem = $bom->items->firstOrFail();

        $this->travel(2)->days();
        $newEffectiveTo = now()->addMonth()->toDateString();
        $extendedBom = $service->extendValidity($bom, $newEffectiveTo, null);

        $this->assertSame($newEffectiveTo, $extendedBom->effective_to?->toDateString());
        $this->assertNotNull($extendedBom->validity_updated_at);
        $this->assertSame(
            $originalItem->part_id,
            $extendedBom->items->firstOrFail()->part_id,
        );

        $this->travelBack();
    }

    /**
     * @return array{Unit, RawMaterial, Part, FinishedProduct}
     */
    private function productBomSetup(): array
    {
        $unit = Unit::create([
            'name' => 'Piece',
            'code' => 'PCS',
            'precision' => 0,
            'is_active' => true,
        ]);
        $rawCategory = RawMaterialCategory::create([
            'code' => 'RAW',
            'name' => 'Raw Materials',
            'is_active' => true,
        ]);
        $partCategory = PartCategory::create([
            'code' => 'PART',
            'name' => 'Parts',
            'is_active' => true,
        ]);
        $productCategory = FinishedProductCategory::create([
            'code' => 'FG',
            'name' => 'Finished Products',
            'is_active' => true,
        ]);
        $material = RawMaterial::create([
            'material_code' => 'RM-001',
            'name' => 'Outer Case',
            'raw_material_category_id' => $rawCategory->id,
            'base_unit_id' => $unit->id,
            'material_kind' => 'MATERIAL',
            'is_active' => true,
        ]);
        $part = Part::create([
            'part_code' => 'PT-001',
            'name' => 'Controller Board',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => 'BOTH',
            'is_active' => true,
        ]);
        $product = FinishedProduct::create([
            'product_code' => 'FG-001',
            'name' => 'Smart Device',
            'finished_product_category_id' => $productCategory->id,
            'base_unit_id' => $unit->id,
            'is_active' => true,
        ]);

        return [$unit, $material, $part, $product];
    }

    private function header(FinishedProduct $product, string $version): array
    {
        return [
            'finished_product_id' => $product->id,
            'version' => $version,
            'output_quantity' => '1',
            'effective_from' => null,
            'effective_to' => null,
        ];
    }

    private function line(
        string $type,
        int $componentId,
        int $unitId,
        string $quantity,
    ): array {
        return [
            'component_type' => $type,
            'component_id' => $componentId,
            'unit_id' => $unitId,
            'required_quantity' => $quantity,
            'wastage_percent' => '0',
        ];
    }
}
