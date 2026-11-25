<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mrp_component_lines', function (Blueprint $table): void {
            $table->string('component_key', 40)->nullable()->after('component_type');
            $table->decimal('safety_quantity', 20, 6)->default(0)->after('available_stock');
            $table->decimal('incoming_quantity', 20, 6)->default(0)->after('safety_quantity');
            $table->decimal('make_quantity', 20, 6)->default(0)->after('net_requirement');
            $table->decimal('buy_quantity', 20, 6)->default(0)->after('make_quantity');
        });

        DB::table('mrp_component_lines')
            ->orderBy('id')
            ->each(function (object $line): void {
                $itemId = $line->component_type === 'RAW_MATERIAL'
                    ? $line->raw_material_id
                    : $line->part_id;

                DB::table('mrp_component_lines')
                    ->where('id', $line->id)
                    ->update([
                        'component_key' => $line->component_type.':'.$itemId,
                    ]);
            });

        Schema::table('mrp_component_lines', function (Blueprint $table): void {
            $table->unique(
                ['material_requirement_plan_id', 'component_key'],
                'mrp_component_plan_key_unique',
            );
        });

        Schema::table('purchase_order', function (Blueprint $table): void {
            $table->foreignId('material_requirement_plan_id')
                ->nullable()
                ->after('id')
                ->constrained('material_requirement_plans')
                ->restrictOnDelete();

            $table->index(
                ['material_requirement_plan_id', 'supplier_id', 'status'],
                'purchase_order_mrp_supplier_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order', function (Blueprint $table): void {
            $table->dropIndex('purchase_order_mrp_supplier_status_index');
            $table->dropConstrainedForeignId('material_requirement_plan_id');
        });

        Schema::table('mrp_component_lines', function (Blueprint $table): void {
            $table->dropUnique('mrp_component_plan_key_unique');
            $table->dropColumn([
                'component_key',
                'safety_quantity',
                'incoming_quantity',
                'make_quantity',
                'buy_quantity',
            ]);
        });
    }
};
