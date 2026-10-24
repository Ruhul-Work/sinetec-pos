<?php

namespace App\Http\Controllers\backend;

class RawMaterialReorderLevelController extends ReorderLevelController
{
    protected function reorderConfig(): array
    {
        return [
            'title' => 'Raw Material Reorder Levels',
            'list_title' => 'Raw Material Reorder Level List',
            'singular' => 'Raw Material',
            'reorder_table' => 'raw_material_reorder_levels',
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'code_column' => 'material_code',
            'route_name' => 'inventory.raw-material-reorder-levels',
        ];
    }
}
