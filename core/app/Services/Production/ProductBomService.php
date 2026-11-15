<?php

namespace App\Services\Production;

use App\Models\backend\FinishedProduct;
use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\Production\ProductBom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductBomService
{
    public function createDraft(array $header, array $lines, ?int $userId): ProductBom
    {
        return DB::transaction(function () use ($header, $lines, $userId): ProductBom {
            $product = $this->activeFinishedProduct((int) $header['finished_product_id']);
            $normalizedLines = $this->normalizeLines($lines);

            $bom = ProductBom::query()->create([
                'finished_product_id' => $product->id,
                'version' => $header['version'],
                'output_quantity' => $this->quantity(
                    $header['output_quantity'],
                    'Output quantity',
                ),
                'status' => 'DRAFT',
                'effective_from' => $header['effective_from'] ?: null,
                'effective_to' => $header['effective_to'] ?: null,
                'created_by' => $userId,
            ]);

            $this->insertLines($bom, $normalizedLines);

            return $this->load($bom);
        });
    }

    public function updateDraft(ProductBom $bom, array $header, array $lines): ProductBom
    {
        return DB::transaction(function () use ($bom, $header, $lines): ProductBom {
            $bom = $this->lock($bom);
            $this->assertDraft($bom);
            $product = $this->activeFinishedProduct((int) $header['finished_product_id']);
            $normalizedLines = $this->normalizeLines($lines);

            $bom->update([
                'finished_product_id' => $product->id,
                'version' => $header['version'],
                'output_quantity' => $this->quantity(
                    $header['output_quantity'],
                    'Output quantity',
                ),
                'effective_from' => $header['effective_from'] ?: null,
                'effective_to' => $header['effective_to'] ?: null,
            ]);

            $bom->items()->delete();
            $this->insertLines($bom, $normalizedLines);

            return $this->load($bom);
        });
    }

    public function activate(ProductBom $bom): ProductBom
    {
        return DB::transaction(function () use ($bom): ProductBom {
            $bom = $this->lock($bom);
            $this->activeFinishedProduct($bom->finished_product_id);

            if ($bom->isExpired()) {
                throw ValidationException::withMessages([
                    'effective_to' => 'This BOM has expired. Extend its validity before activating it.',
                ]);
            }

            if ($bom->items()->doesntExist()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one Raw Material or Part before activating this BOM.',
                ]);
            }

            ProductBom::query()
                ->where('finished_product_id', $bom->finished_product_id)
                ->where('id', '!=', $bom->id)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'INACTIVE',
                    'updated_at' => now(),
                ]);

            $bom->update(['status' => 'ACTIVE']);

            return $this->load($bom);
        });
    }

    public function deactivate(ProductBom $bom): ProductBom
    {
        return DB::transaction(function () use ($bom): ProductBom {
            $bom = $this->lock($bom);

            if ($bom->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'status' => 'Only an active BOM can be deactivated.',
                ]);
            }

            $bom->update(['status' => 'INACTIVE']);

            return $this->load($bom);
        });
    }

    public function extendValidity(
        ProductBom $bom,
        ?string $effectiveTo,
        ?int $userId,
    ): ProductBom {
        return DB::transaction(function () use ($bom, $effectiveTo, $userId): ProductBom {
            $bom = $this->lock($bom);

            if ($bom->status === 'DRAFT') {
                throw ValidationException::withMessages([
                    'status' => 'Edit the Draft BOM directly instead of extending its validity.',
                ]);
            }

            if ($bom->effective_to === null) {
                throw ValidationException::withMessages([
                    'effective_to' => 'This BOM already has no expiry date.',
                ]);
            }

            $newEffectiveTo = $effectiveTo
                ? CarbonImmutable::parse($effectiveTo)->startOfDay()
                : null;

            if ($newEffectiveTo !== null && $newEffectiveTo->isBefore(today())) {
                throw ValidationException::withMessages([
                    'effective_to' => 'The new validity date cannot be in the past.',
                ]);
            }

            if ($newEffectiveTo !== null && ! $newEffectiveTo->isAfter($bom->effective_to)) {
                throw ValidationException::withMessages([
                    'effective_to' => 'Choose a date after the current expiry date, or leave it blank for no expiry.',
                ]);
            }

            $bom->update([
                'effective_to' => $newEffectiveTo?->toDateString(),
                'validity_updated_by' => $userId,
                'validity_updated_at' => now(),
            ]);

            return $this->load($bom);
        });
    }

    public function deleteDraft(ProductBom $bom): void
    {
        DB::transaction(function () use ($bom): void {
            $bom = $this->lock($bom);
            $this->assertDraft($bom);
            $bom->delete();
        });
    }

    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one Raw Material or Part component.',
            ]);
        }

        $normalized = [];
        $componentKeys = [];

        foreach ($lines as $index => $line) {
            $componentType = strtoupper((string) ($line['component_type'] ?? ''));
            $componentId = (int) ($line['component_id'] ?? 0);
            $componentKey = $componentType.':'.$componentId;

            if ($componentId <= 0 || in_array($componentKey, $componentKeys, true)) {
                throw ValidationException::withMessages([
                    "lines.$index.component_id" => 'Each component may be used only once in a BOM.',
                ]);
            }

            $component = $this->activeComponent($componentType, $componentId, $index);
            $unitId = (int) ($line['unit_id'] ?? 0);
            $factor = $this->productionUnitFactor(
                $componentType,
                $component,
                $unitId,
                $index,
            );
            $requiredQuantity = $this->quantity(
                $line['required_quantity'] ?? null,
                'Required quantity',
            );

            $normalized[] = [
                'raw_material_id' => $componentType === 'RAW_MATERIAL' ? $component->id : null,
                'part_id' => $componentType === 'PART' ? $component->id : null,
                'unit_id' => $unitId,
                'required_quantity' => $requiredQuantity,
                'base_quantity' => $this->multiply($requiredQuantity, $factor),
                'wastage_percent' => $this->percentage(
                    $line['wastage_percent'] ?? 0,
                    'Wastage percentage',
                ),
            ];
            $componentKeys[] = $componentKey;
        }

        return $normalized;
    }

    private function activeComponent(string $type, int $id, int $index): RawMaterial|Part
    {
        $model = match ($type) {
            'RAW_MATERIAL' => RawMaterial::query()
                ->whereKey($id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first(),
            'PART' => Part::query()
                ->whereKey($id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first(),
            default => null,
        };

        if (! $model) {
            throw ValidationException::withMessages([
                "lines.$index.component_id" => 'Select an active Raw Material or Part.',
            ]);
        }

        return $model;
    }

    private function productionUnitFactor(
        string $componentType,
        RawMaterial|Part $component,
        int $unitId,
        int $index,
    ): string {
        if ($unitId === $component->base_unit_id) {
            return '1';
        }

        $mapping = match ($componentType) {
            'RAW_MATERIAL' => DB::table('raw_material_units')
                ->where('raw_material_id', $component->id),
            'PART' => DB::table('part_units')
                ->where('part_id', $component->id),
        };

        $conversion = $mapping
            ->where('unit_id', $unitId)
            ->whereIn('usage_type', ['PRODUCTION', 'ALL'])
            ->value('conversion_to_base');

        if ($conversion === null) {
            throw ValidationException::withMessages([
                "lines.$index.unit_id" => 'Select the base unit or a unit enabled for production.',
            ]);
        }

        return (string) $conversion;
    }

    private function insertLines(ProductBom $bom, array $lines): void
    {
        $bom->items()->createMany($lines);
    }

    private function activeFinishedProduct(int $productId): FinishedProduct
    {
        $product = FinishedProduct::query()
            ->whereKey($productId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'finished_product_id' => 'Select an active Finished Product.',
            ]);
        }

        return $product;
    }

    private function assertDraft(ProductBom $bom): void
    {
        if ($bom->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' => 'Only a Draft BOM can be edited or deleted. Create a new version instead.',
            ]);
        }
    }

    private function lock(ProductBom $bom): ProductBom
    {
        return ProductBom::query()->lockForUpdate()->findOrFail($bom->id);
    }

    private function load(ProductBom $bom): ProductBom
    {
        return $bom->fresh([
            'finishedProduct.baseUnit',
            'items.rawMaterial.baseUnit',
            'items.part.baseUnit',
            'items.unit',
        ]);
    }

    private function quantity(mixed $value, string $field): string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw ValidationException::withMessages([
                $field => "{$field} must be greater than zero.",
            ]);
        }

        return number_format(round((float) $value, 6), 6, '.', '');
    }

    private function percentage(mixed $value, string $field): string
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw ValidationException::withMessages([
                $field => "{$field} must be between 0 and 100.",
            ]);
        }

        return number_format(round((float) $value, 4), 4, '.', '');
    }

    private function multiply(string $left, string $right): string
    {
        return number_format(round((float) $left * (float) $right, 6), 6, '.', '');
    }
}
