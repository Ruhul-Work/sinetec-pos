<?php

namespace App\Http\Controllers\backend;

class PartReorderLevelController extends ReorderLevelController
{
    protected function reorderConfig(): array
    {
        return [
            'title' => 'Part Reorder Levels',
            'list_title' => 'Part Reorder Level List',
            'singular' => 'Part',
            'reorder_table' => 'part_reorder_levels',
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'code_column' => 'part_code',
            'route_name' => 'inventory.part-reorder-levels',
        ];
    }
}
