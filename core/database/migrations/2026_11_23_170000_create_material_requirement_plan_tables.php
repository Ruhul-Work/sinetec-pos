<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing stub table so we can recreate it properly
        Schema::dropIfExists('material_requirement_plans');

        Schema::create('material_requirement_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_no', 40)->unique();
            $table->foreignId('demand_plan_id')
                ->unique()
                ->constrained('demand_plans')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignId('reporting_period_id')
                ->constrained('reporting_periods')
                ->cascadeOnDelete();
            $table->string('status', 20)->default('DRAFT');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('mrp_finished_product_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_requirement_plan_id')
                ->constrained('material_requirement_plans')
                ->cascadeOnDelete();
            $table->foreignId('finished_product_id')
                ->constrained('finished_products')
                ->cascadeOnDelete();
            $table->decimal('demand_quantity', 20, 6)->default(0);
            $table->decimal('safety_quantity', 20, 6)->default(0);
            $table->decimal('available_stock', 20, 6)->default(0);
            $table->decimal('net_required', 20, 6)->default(0);
            $table->timestamps();

            $table->unique(
                ['material_requirement_plan_id', 'finished_product_id'],
                'mrp_fp_plan_product_unique',
            );
        });

        Schema::create('mrp_component_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_requirement_plan_id')
                ->constrained('material_requirement_plans')
                ->cascadeOnDelete();
            $table->string('component_type', 20); // RAW_MATERIAL or PART
            $table->unsignedBigInteger('raw_material_id')->nullable();
            $table->unsignedBigInteger('part_id')->nullable();
            $table->decimal('gross_requirement', 20, 6)->default(0);
            $table->decimal('available_stock', 20, 6)->default(0);
            $table->decimal('net_requirement', 20, 6)->default(0);
            $table->string('source_hint', 60)->nullable();
            $table->timestamps();

            $table->foreign('raw_material_id')->references('id')->on('raw_materials')->cascadeOnDelete();
            $table->foreign('part_id')->references('id')->on('parts')->cascadeOnDelete();
            $table->unique(
                ['material_requirement_plan_id', 'component_type', 'raw_material_id', 'part_id'],
                'mrp_comp_plan_type_item_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_component_lines');
        Schema::dropIfExists('mrp_finished_product_lines');
        Schema::dropIfExists('material_requirement_plans');
    }
};
