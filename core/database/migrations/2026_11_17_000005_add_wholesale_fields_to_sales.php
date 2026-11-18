<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('customer_reference', 100)->nullable()->after('customer_id');
            $table->date('due_date')->nullable()->after('sale_at');
            $table->index(['channel', 'due_date'], 'sales_channel_due_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_channel_due_date_index');
            $table->dropColumn(['customer_reference', 'due_date']);
        });
    }
};
