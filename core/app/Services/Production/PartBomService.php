<?php

namespace App\Services\Production;

use App\Models\backend\Part;
use App\Models\backend\RawMaterial;
use App\Models\Production\PartBom;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartBomService
{
    public function createDraft(array $header, array $lines, ?int $userId): PartBom
    {
        return DB::transaction(function () use ($header, $lines, $userId): PartBom {
            $part = $this->produciblePart((int) $header['part_id']);
            $normalizedLines = $this->normalizeLines($lines);

            $bom = PartBom::query()->create([
                'part_id' => $part->id,
                'version' => $header['version'],
                'output_quantity' => $this->quantity($header['output_quantity'], 'Output quantity'),
                'status' => 'DRAFT',
                'effective_from' => $header['effective_from'] ?: null,
                'effective_to' => $header['effective_to'] ?: null,
                'created_by' => $userId,
            ]);

            $this->insertLines($bom, $normalizedLines);

            return $this->load($bom);
        });
    }

    public function updateDraft(PartBom $bom, array $header, array $lines): PartBom
    {
        return DB::transaction(function () use ($bom, $header, $lines): PartBom {
            $bom = $this->lock($bom);
            $this->assertDraft($bom);
            $part = $this->produciblePart((int) $header['part_id']);
            $normalizedLines = $this->normalizeLines($lines);

            $bom->update([
                'part_id' => $part->id,
                'version' => $header['version'],
                'output_quantity' => $this->quantity($header['output_quantity'], 'Output quantity'),
                'effective_from' => $header['effective_from'] ?: null,
                'effective_to' => $header['effective_to'] ?: null,
            ]);

            $bom->items()->delete();
            $this->insertLines($bom, $normalizedLines);

            return $this->load($bom);
        });
    }

    public function activate(PartBom $bom): PartBom
    {
        return DB::transaction(function () use ($bom): PartBom {
            $bom = $this->lock($bom);
            $this->produciblePart($bom->part_id);

            if ($bom->items()->doesntExist()) {
                throw ValidationException::withMessages([
                    'lines' => 'Add at least one raw material before activating this BOM.',
                ]);
            }

            PartBom::query()
                ->where('part_id', $bom->part_id)
                ->where('id', '!=', $bom->id)
                ->where('status', 'ACTIVE')
                ->update(['status' => 'INACTIVE', 'updated_at' => now()]);

            $bom->update(['status' => 'ACTIVE']);

            return $this->load($bom);
        });
    }

    public function deactivate(PartBom $bom): PartBom
    {
        return DB::transaction(function () use ($bom): PartBom {
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

    public function deleteDraft(PartBom $bom): void
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
                'lines' => 'Add at least one raw material line.',
            ]);
        }

        $normalized = [];
        $materialIds = [];

        foreach ($lines as $index => $line) {
            $materialId = (int) ($line['raw_material_id'] ?? 0);

            if ($materialId <= 0 || in_array($materialId, $materialIds, true)) {
                throw ValidationException::withMessages([
                    "lines.$index.raw_material_id" => 'Each raw material may be used once in a BOM.',
                ]);
            }

            $material = RawMaterial::query()
                ->whereKey($materialId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();

            if (! $material) {
                throw ValidationException::withMessages([
                    "lines.$index.raw_material_id" => 'Select an active raw material.',
                ]);
            }

            $unitId = (int) ($line['unit_id'] ?? 0);
            $factor = $this->productionUnitFactor($material, $unitId, $index);
            $requiredQuantity = $this->quantity($line['required_quantity'] ?? null, 'Required quantity');
            $wastagePercent = $this->percentage($line['wastage_percent'] ?? 0, 'Wastage percentage');

            $normalized[] = [
                'raw_material_id' => $material->id,
                'unit_id' => $unitId,
                'required_quantity' => $requiredQuantity,
                'base_quantity' => $this->multiply($requiredQuantity, $factor),
                'wastage_percent' => $wastagePercent,
            ];
            $materialIds[] = $materialId;
        }

        return $normalized;
    }

    private function productionUnitFactor(RawMaterial $material, int $unitId, int $index): string
    {
        if ($unitId === $material->base_unit_id) {
            return '1';
        }

        $mapping = DB::table('raw_material_units')
            ->where('raw_material_id', $material->id)
            ->where('unit_id', $unitId)
            ->whereIn('usage_type', ['PRODUCTION', 'ALL'])
            ->first(['conversion_to_base']);

        if (! $mapping) {
            throw ValidationException::withMessages([
                "lines.$index.unit_id" => 'Select the base unit or a raw-material unit enabled for production.',
            ]);
        }

        return (string) $mapping->conversion_to_base;
    }

    private function insertLines(PartBom $bom, array $lines): void
    {
        $now = now();

        $bom->items()->createMany(array_map(
            fn (array $line): array => $line + ['created_at' => $now, 'updated_at' => $now],
            $lines
        ));
    }

    private function produciblePart(int $partId): Part
    {
        $part = Part::query()
            ->whereKey($partId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('procurement_mode', ['MAKE', 'BOTH'])
            ->first();

        if (! $part) {
            throw ValidationException::withMessages([
                'part_id' => 'Select an active Part with Make or Both procurement mode.',
            ]);
        }

        return $part;
    }

    private function assertDraft(PartBom $bom): void
    {
        if ($bom->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' => 'Only a Draft BOM can be edited or deleted. Create a new version for an active or inactive BOM.',
            ]);
        }
    }

    private function lock(PartBom $bom): PartBom
    {
        return PartBom::query()->lockForUpdate()->findOrFail($bom->id);
    }

    private function load(PartBom $bom): PartBom
    {
        return $bom->fresh(['part.baseUnit', 'items.rawMaterial.baseUnit', 'items.unit']);
    }

    private function quantity(mixed $value, string $field): string
    {
        if (! is_numeric($value) || (float) $value <= 0) {
            throw ValidationException::withMessages([$field => "$field must be greater than zero."]);
        }

        return number_format(round((float) $value, 6), 6, '.', '');
    }

    private function percentage(mixed $value, string $field): string
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw ValidationException::withMessages([$field => "$field must be between 0 and 100."]);
        }

        return number_format(round((float) $value, 4), 4, '.', '');
    }

    private function multiply(string $left, string $right): string
    {
        return number_format(round((float) $left * (float) $right, 6), 6, '.', '');
    }
}
