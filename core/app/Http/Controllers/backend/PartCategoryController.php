<?php

namespace App\Http\Controllers\backend;

use App\Models\backend\PartCategory;

class PartCategoryController extends InventoryCategoryController
{
    protected function modelClass(): string { return PartCategory::class; }
    protected function routePrefix(): string { return 'inventory.part-categories'; }
    protected function singularLabel(): string { return 'Part Category'; }
    protected function pluralLabel(): string { return 'Part Categories'; }
    protected function itemTable(): string { return 'parts'; }
    protected function itemCategoryColumn(): string { return 'part_category_id'; }
}
