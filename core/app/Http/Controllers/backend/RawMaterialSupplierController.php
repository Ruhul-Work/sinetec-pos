<?php

namespace App\Http\Controllers\backend;

class RawMaterialSupplierController extends SupplierMappingController
{
    protected function mappingConfig(): array
    {
        return [
            'title' => 'Raw Material Supplier Mappings',
            'list_title' => 'Raw Material Supplier List',
            'singular' => 'Raw Material',
            'menu_label' => 'Raw Materials',
            'icon' => 'mdi:flask-outline',
            'mapping_table' => 'raw_material_suppliers',
            'item_table' => 'raw_materials',
            'item_column' => 'raw_material_id',
            'code_column' => 'material_code',
            'route_name' => 'inventory.raw-material-suppliers',
        ];
    }
}
