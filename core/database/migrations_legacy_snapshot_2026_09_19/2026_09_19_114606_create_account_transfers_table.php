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
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id')->index('idx_branch');
            $table->unsignedBigInteger('from_account_id')->index('idx_from_account');
            $table->unsignedBigInteger('to_account_id')->index('idx_to_account');
            $table->decimal('amount', 15);
            $table->date('transfer_date');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index('idx_journal');
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
    }
};
