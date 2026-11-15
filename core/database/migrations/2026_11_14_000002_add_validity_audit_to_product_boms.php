<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_boms', function (Blueprint $table): void {
            $table->foreignId('validity_updated_by')
                ->nullable()
                ->after('effective_to')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('validity_updated_at')
                ->nullable()
                ->after('validity_updated_by');
        });

        Schema::table('product_bom_items', function (Blueprint $table): void {
            $table->unique(
                ['product_bom_id', 'raw_material_id'],
                'product_bom_items_raw_material_unique',
            );
            $table->unique(
                ['product_bom_id', 'part_id'],
                'product_bom_items_part_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_bom_items', function (Blueprint $table): void {
            $table->dropUnique('product_bom_items_raw_material_unique');
            $table->dropUnique('product_bom_items_part_unique');
        });

        Schema::table('product_boms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('validity_updated_by');
            $table->dropColumn('validity_updated_at');
        });
    }
};
