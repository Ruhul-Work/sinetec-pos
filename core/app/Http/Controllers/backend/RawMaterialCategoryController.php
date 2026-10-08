<?php

namespace App\Http\Controllers\backend;

use App\Models\backend\RawMaterialCategory;

class RawMaterialCategoryController extends InventoryCategoryController
{
    protected function modelClass(): string { return RawMaterialCategory::class; }
    protected function routePrefix(): string { return 'inventory.raw-material-categories'; }
    protected function singularLabel(): string { return 'Raw Material Category'; }
    protected function pluralLabel(): string { return 'Raw Material Categories'; }
    protected function itemTable(): string { return 'raw_materials'; }
    protected function itemCategoryColumn(): string { return 'raw_material_category_id'; }
}
