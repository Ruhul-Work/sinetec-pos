<?php

namespace App\Http\Controllers\backend;

use App\Models\backend\FinishedProductCategory;

class FinishedProductCategoryController extends InventoryCategoryController
{
    protected function modelClass(): string { return FinishedProductCategory::class; }
    protected function routePrefix(): string { return 'inventory.finished-product-categories'; }
    protected function singularLabel(): string { return 'Finished Product Category'; }
    protected function pluralLabel(): string { return 'Finished Product Categories'; }
    protected function itemTable(): string { return 'finished_products'; }
    protected function itemCategoryColumn(): string { return 'finished_product_category_id'; }
}
