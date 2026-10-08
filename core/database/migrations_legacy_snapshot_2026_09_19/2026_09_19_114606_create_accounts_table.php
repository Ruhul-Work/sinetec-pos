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
        Schema::create('accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_type_id')->index('fk_accounts_type');
            $table->string('name', 100);
            $table->string('code', 50)->nullable()->unique('code');
            $table->string('description', 255)->nullable();
            $table->string('currency', 10)->default('BDT');
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_no', 100)->nullable();
            $table->text('bank_details')->nullable();
            $table->boolean('allow_negative')->default(false);
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
