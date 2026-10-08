<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index('user_id');
            $table->unsignedBigInteger('branch_id')->nullable()->index('branch_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->index('fk_pos_sessions_warehouse');
            $table->timestamp('opened_at')->nullable()->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_balance', 14)->default(0);
            $table->decimal('closing_balance', 14)->default(0);
            $table->string('status', 20)->nullable()->default('open');
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
