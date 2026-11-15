<?php

namespace Tests\Feature;

use App\Models\backend\Part;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterial;
use App\Models\backend\RawMaterialCategory;
use App\Models\backend\Unit;
use App\Models\Production\PartBom;
use App\Services\Production\PartBomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PartBomTest extends TestCase
{
    use RefreshDatabase;

    public function test_make_part_bom_stores_base_quantity_and_only_one_version_is_active(): void
    {
        [$unit, $rawMaterial, $part] = $this->productionSetup('MAKE');
        $service = app(PartBomService::class);

        $firstBom = $service->createDraft([
            'part_id' => $part->id,
            'version' => 'V1',
            'output_quantity' => '10',
            'effective_from' => null,
            'effective_to' => null,
        ], [[
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => '12.5',
            'wastage_percent' => '2.5',
        ]], null);

        $this->assertDatabaseHas('part_bom_items', [
            'part_bom_id' => $firstBom->id,
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'base_quantity' => 12.5,
            'wastage_percent' => 2.5,
        ]);

        $service->activate($firstBom);

        $secondBom = $service->createDraft([
            'part_id' => $part->id,
            'version' => 'V2',
            'output_quantity' => '10',
            'effective_from' => null,
            'effective_to' => null,
        ], [[
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => '13',
            'wastage_percent' => '0',
        ]], null);

        $service->activate($secondBom);

        $this->assertSame('INACTIVE', PartBom::findOrFail($firstBom->id)->status);
        $this->assertSame('ACTIVE', PartBom::findOrFail($secondBom->id)->status);
    }

    public function test_buy_only_part_cannot_receive_a_part_bom(): void
    {
        [$unit, $rawMaterial, $part] = $this->productionSetup('BUY');

        $this->expectException(ValidationException::class);

        app(PartBomService::class)->createDraft([
            'part_id' => $part->id,
            'version' => 'V1',
            'output_quantity' => '1',
            'effective_from' => null,
            'effective_to' => null,
        ], [[
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => '1',
            'wastage_percent' => '0',
        ]], null);
    }

    public function test_expired_bom_cannot_be_activated(): void
    {
        [$unit, $rawMaterial, $part] = $this->productionSetup('MAKE');
        $service = app(PartBomService::class);

        $bom = $service->createDraft([
            'part_id' => $part->id,
            'version' => 'V1',
            'output_quantity' => '1',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => now()->subDay()->toDateString(),
        ], [[
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => '1',
            'wastage_percent' => '0',
        ]], null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This BOM has expired');

        $service->activate($bom);
    }

    public function test_active_bom_validity_can_be_extended_without_changing_recipe(): void
    {
        [$unit, $rawMaterial, $part] = $this->productionSetup('MAKE');
        $service = app(PartBomService::class);

        $bom = $service->createDraft([
            'part_id' => $part->id,
            'version' => 'V1',
            'output_quantity' => '5',
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => now()->addDay()->toDateString(),
        ], [[
            'raw_material_id' => $rawMaterial->id,
            'unit_id' => $unit->id,
            'required_quantity' => '2.5',
            'wastage_percent' => '1',
        ]], null);

        $bom = $service->activate($bom);
        $originalItem = $bom->items->firstOrFail();

        $this->travel(2)->days();
        $this->assertTrue($bom->fresh()->isExpired());

        $newEffectiveTo = now()->addMonth()->toDateString();
        $extendedBom = $service->extendValidity($bom, $newEffectiveTo, null);
        $extendedItem = $extendedBom->items->firstOrFail();

        $this->assertSame('ACTIVE', $extendedBom->status);
        $this->assertSame($newEffectiveTo, $extendedBom->effective_to?->toDateString());
        $this->assertNotNull($extendedBom->validity_updated_at);
        $this->assertSame($bom->part_id, $extendedBom->part_id);
        $this->assertSame($bom->version, $extendedBom->version);
        $this->assertSame($bom->output_quantity, $extendedBom->output_quantity);
        $this->assertSame($originalItem->raw_material_id, $extendedItem->raw_material_id);
        $this->assertSame($originalItem->base_quantity, $extendedItem->base_quantity);

        $this->travelBack();
    }

    /** @return array{0: Unit, 1: RawMaterial, 2: Part} */
    private function productionSetup(string $procurementMode): array
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
            'name' => 'Cable Assembly',
            'part_category_id' => $partCategory->id,
            'base_unit_id' => $unit->id,
            'procurement_mode' => $procurementMode,
            'is_active' => true,
        ]);

        return [$unit, $rawMaterial, $part];
    }
}
