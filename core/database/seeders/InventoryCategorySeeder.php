<?php

namespace Database\Seeders;

use App\Models\backend\AbstractInventoryCategory;
use App\Models\backend\FinishedProductCategory;
use App\Models\backend\PartCategory;
use App\Models\backend\RawMaterialCategory;
use Illuminate\Database\Seeder;

class InventoryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPair(
            RawMaterialCategory::class,
            [
                'code' => 'ELECTRONIC_COMPONENTS',
                'name' => 'Electronic Components',
                'description' => 'Electronic raw materials and consumable components.',
            ],
            [
                'code' => 'PCB_MATERIALS',
                'name' => 'PCB Materials',
                'description' => 'Materials used to prepare printed circuit boards.',
            ],
        );

        $this->seedPair(
            PartCategory::class,
            [
                'code' => 'CIRCUIT_BOARDS',
                'name' => 'Circuit Boards',
                'description' => 'Purchased or internally manufactured circuit-board parts.',
            ],
            [
                'code' => 'CONTROL_BOARDS',
                'name' => 'Control Boards',
                'description' => 'Control and logic board assemblies.',
            ],
        );

        $this->seedPair(
            FinishedProductCategory::class,
            [
                'code' => 'POWER_PRODUCTS',
                'name' => 'Power Products',
                'description' => 'Finished electrical power products.',
            ],
            [
                'code' => 'UPS_SYSTEMS',
                'name' => 'UPS Systems',
                'description' => 'Finished uninterruptible power supply systems.',
            ],
        );
    }

    /**
     * @param class-string<AbstractInventoryCategory> $modelClass
     * @param array{code: string, name: string, description: string} $rootData
     * @param array{code: string, name: string, description: string} $childData
     */
    private function seedPair(string $modelClass, array $rootData, array $childData): void
    {
        $root = $this->upsertCategory($modelClass, $rootData, null);
        $this->upsertCategory($modelClass, $childData, $root->id);
    }

    /**
     * @param class-string<AbstractInventoryCategory> $modelClass
     * @param array{code: string, name: string, description: string} $data
     */
    private function upsertCategory(string $modelClass, array $data, ?int $parentId): AbstractInventoryCategory
    {
        $category = $modelClass::withTrashed()->firstOrNew(['code' => $data['code']]);
        $category->fill([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'description' => $data['description'],
            'is_active' => true,
        ]);
        $category->deleted_at = null;
        $category->save();

        return $category;
    }
}
