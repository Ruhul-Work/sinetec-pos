<?php

namespace App\Http\Controllers\backend;

class FinishedProductReorderLevelController extends ReorderLevelController
{
    protected function reorderConfig(): array
    {
        return [
            'title' => 'Finished Product Reorder Levels',
            'list_title' => 'Finished Product Reorder Level List',
            'singular' => 'Finished Product',
            'reorder_table' => 'finished_product_reorder_levels',
            'item_table' => 'finished_products',
            'item_column' => 'finished_product_id',
            'code_column' => 'product_code',
            'route_name' => 'inventory.finished-product-reorder-levels',
        ];
    }
}
