<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demand_plans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('history_months')
                ->default(3)
                ->after('reporting_period_id');

            $table->unique(
                ['branch_id', 'reporting_period_id'],
                'demand_plans_branch_period_unique',
            );
        });

        Schema::table('demand_plan_items', function (Blueprint $table): void {
            $table->decimal('historical_net_quantity', 20, 6)
                ->default(0)
                ->after('finished_product_id');
            $table->decimal('suggested_quantity', 20, 6)
                ->default(0)
                ->after('historical_net_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('demand_plan_items', function (Blueprint $table): void {
            $table->dropColumn([
                'historical_net_quantity',
                'suggested_quantity',
            ]);
        });

        Schema::table('demand_plans', function (Blueprint $table): void {
            $table->dropUnique('demand_plans_branch_period_unique');
            $table->dropColumn('history_months');
        });
    }
};
