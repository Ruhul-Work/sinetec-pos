<?php

namespace App\Http\Controllers\backend;

class PartSupplierController extends SupplierMappingController
{
    protected function mappingConfig(): array
    {
        return [
            'title' => 'Part Supplier Mappings',
            'list_title' => 'Part Supplier List',
            'singular' => 'Part',
            'menu_label' => 'Parts',
            'icon' => 'mdi:cog-outline',
            'mapping_table' => 'part_suppliers',
            'item_table' => 'parts',
            'item_column' => 'part_id',
            'code_column' => 'part_code',
            'route_name' => 'inventory.part-suppliers',
        ];
    }
}
