<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_periods', function (Blueprint $table) { $table->id(); $table->unsignedSmallInteger('year'); $table->unsignedTinyInteger('month'); $table->date('starts_on'); $table->date('ends_on'); $table->enum('status', ['OPEN','CLOSED'])->default('OPEN'); $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('closed_at')->nullable(); $table->timestamps(); $table->unique(['year','month']); });
        Schema::create('monthly_rate_sheets', function (Blueprint $table) { $table->id(); $table->foreignId('reporting_period_id')->constrained('reporting_periods')->restrictOnDelete(); $table->string('sheet_no', 50)->unique(); $table->enum('status', ['DRAFT','APPROVED','LOCKED'])->default('DRAFT'); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('approved_at')->nullable(); $table->timestamp('locked_at')->nullable(); $table->timestamps(); $table->unique('reporting_period_id'); });
        $this->createRateTable('monthly_raw_material_rates', 'raw_material_id', 'raw_materials');
        $this->createRateTable('monthly_part_rates', 'part_id', 'parts');
        $this->createRateTable('monthly_finished_product_rates', 'finished_product_id', 'finished_products');
        Schema::create('monthly_report_snapshots', function (Blueprint $table) { $table->id(); $table->foreignId('reporting_period_id')->constrained('reporting_periods')->restrictOnDelete(); $table->foreignId('monthly_rate_sheet_id')->constrained('monthly_rate_sheets')->restrictOnDelete(); $table->string('report_type', 80); $table->string('snapshot_key', 191)->unique(); $table->json('filters')->nullable(); $table->longText('payload'); $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('generated_at'); $table->timestamps(); $table->index(['reporting_period_id','report_type']); });

        Schema::create('demand_plans', function (Blueprint $table) { $table->id(); $table->string('plan_no', 50)->unique(); $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete(); $table->foreignId('reporting_period_id')->constrained('reporting_periods')->restrictOnDelete(); $table->enum('status', ['DRAFT','APPROVED','CANCELLED'])->default('DRAFT'); $table->text('note')->nullable(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('approved_at')->nullable(); $table->timestamps(); });
        Schema::create('demand_plan_items', function (Blueprint $table) { $table->id(); $table->foreignId('demand_plan_id')->constrained('demand_plans')->cascadeOnDelete(); $table->foreignId('finished_product_id')->constrained('finished_products')->restrictOnDelete(); $table->decimal('forecast_quantity', 20, 6); $table->decimal('safety_quantity', 20, 6)->default(0); $table->text('basis')->nullable(); $table->timestamps(); $table->unique(['demand_plan_id','finished_product_id']); });
        Schema::create('material_requirement_plans', function (Blueprint $table) { $table->id(); $table->string('plan_no', 50)->unique(); $table->foreignId('demand_plan_id')->constrained('demand_plans')->restrictOnDelete(); $table->dateTime('generated_at'); $table->enum('status', ['DRAFT','APPROVED','CANCELLED'])->default('DRAFT'); $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('approved_at')->nullable(); $table->timestamps(); });
        Schema::create('raw_material_requirement_items', function (Blueprint $table) { $table->id(); $table->foreignId('material_requirement_plan_id'); $table->foreign('material_requirement_plan_id', 'raw_requirement_plan_fk')->references('id')->on('material_requirement_plans')->cascadeOnDelete(); $table->foreignId('raw_material_id')->constrained('raw_materials')->restrictOnDelete(); $table->decimal('gross_requirement', 20, 6); $table->decimal('available_stock', 20, 6)->default(0); $table->decimal('incoming_quantity', 20, 6)->default(0); $table->decimal('safety_quantity', 20, 6)->default(0); $table->decimal('suggested_purchase', 20, 6)->default(0); $table->timestamps(); $table->unique(['material_requirement_plan_id','raw_material_id'], 'raw_requirement_plan_item_unique'); });
        Schema::create('part_requirement_items', function (Blueprint $table) { $table->id(); $table->foreignId('material_requirement_plan_id')->constrained('material_requirement_plans')->cascadeOnDelete(); $table->foreignId('part_id')->constrained('parts')->restrictOnDelete(); $table->decimal('gross_requirement', 20, 6); $table->decimal('available_stock', 20, 6)->default(0); $table->decimal('incoming_quantity', 20, 6)->default(0); $table->decimal('make_quantity', 20, 6)->default(0); $table->decimal('buy_quantity', 20, 6)->default(0); $table->decimal('suggested_procurement', 20, 6)->default(0); $table->timestamps(); $table->unique(['material_requirement_plan_id','part_id'], 'part_requirement_plan_item_unique'); });
    }

    private function createRateTable(string $tableName, string $itemColumn, string $itemTable): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($tableName, $itemColumn, $itemTable) { $table->id(); $table->foreignId('monthly_rate_sheet_id')->constrained('monthly_rate_sheets')->cascadeOnDelete(); $table->foreignId($itemColumn)->constrained($itemTable)->restrictOnDelete(); $table->decimal('rate', 20, 4); $table->string('rate_basis', 50)->default('MANUAL'); $table->timestamps(); $table->unique(['monthly_rate_sheet_id',$itemColumn], $tableName.'_unique'); });
    }

    public function down(): void
    {
        foreach (['part_requirement_items','raw_material_requirement_items','material_requirement_plans','demand_plan_items','demand_plans','monthly_report_snapshots','monthly_finished_product_rates','monthly_part_rates','monthly_raw_material_rates','monthly_rate_sheets','reporting_periods'] as $table) Schema::dropIfExists($table);
    }
};
