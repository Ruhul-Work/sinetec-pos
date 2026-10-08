<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('mapping_key', 80);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'mapping_key']);
        });
        Schema::create('account_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'branch_id', 'account_id'], 'account_opening_scope_unique');
        });

        // Future commerce foundations. These tables have no release-1 menu or posting logic.
        Schema::create('offers', function (Blueprint $table) {
            $table->id(); $table->string('code', 50)->unique(); $table->string('name', 150); $table->enum('discount_type', ['PERCENT','FIXED']); $table->decimal('discount_value', 20, 4); $table->dateTime('starts_at'); $table->dateTime('ends_at'); $table->boolean('is_active')->default(false); $table->timestamps();
        });
        Schema::create('offer_finished_products', function (Blueprint $table) {
            $table->id(); $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete(); $table->foreignId('finished_product_id')->constrained('finished_products')->cascadeOnDelete(); $table->timestamps(); $table->unique(['offer_id','finished_product_id']);
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->id(); $table->string('code', 80)->unique(); $table->enum('discount_type', ['PERCENT','FIXED']); $table->decimal('discount_value', 20, 4); $table->decimal('minimum_order_amount', 20, 4)->default(0); $table->unsignedInteger('usage_limit')->nullable(); $table->unsignedInteger('per_customer_limit')->nullable(); $table->dateTime('starts_at'); $table->dateTime('ends_at'); $table->boolean('is_active')->default(false); $table->timestamps();
        });
        Schema::create('coupon_finished_products', function (Blueprint $table) {
            $table->id(); $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete(); $table->foreignId('finished_product_id')->constrained('finished_products')->cascadeOnDelete(); $table->timestamps(); $table->unique(['coupon_id','finished_product_id']);
        });
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id(); $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete(); $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete(); $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete(); $table->decimal('discount_amount', 20, 4); $table->timestamp('used_at'); $table->timestamps(); $table->unique(['coupon_id','sale_id']);
        });
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->decimal('spend_amount', 20, 4); $table->unsignedInteger('points_earned'); $table->decimal('point_value', 20, 4)->default(0); $table->boolean('is_active')->default(false); $table->timestamps();
        });
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id(); $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete(); $table->foreignId('sale_id')->nullable()->constrained('sales')->restrictOnDelete(); $table->enum('type', ['EARN','REDEEM','ADJUST']); $table->integer('points'); $table->integer('balance_after'); $table->string('reference', 100)->nullable(); $table->timestamps(); $table->index(['customer_id','created_at']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_transactions','loyalty_rules','coupon_usages','coupon_finished_products','coupons','offer_finished_products','offers','account_opening_balances','finance_account_mappings'] as $table) Schema::dropIfExists($table);
    }
};
