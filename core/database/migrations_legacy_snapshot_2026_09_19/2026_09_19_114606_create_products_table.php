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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('has_variants')->default(false);
            $table->boolean('is_sellable')->default(false);
            $table->string('name', 255);
            $table->string('slug', 191)->index('products_slug_idx');
            $table->string('sku', 191)->unique();
            $table->string('barcode', 191)->nullable()->unique();
            $table->unsignedBigInteger('unit_id')->nullable()->index('products_unit_fk');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('product_type_id')->nullable()->index('products_ptype_fk');
            $table->unsignedBigInteger('category_type_id')->nullable()->index('products_ctype_fk');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('subcategory_id')->nullable()->index('products_subcat_fk');
            $table->unsignedBigInteger('tax_id')->nullable()->index('products_tax_fk');
            $table->boolean('tax_included')->default(false);
            $table->unsignedBigInteger('color_id')->nullable()->index('products_color_fk');
            $table->unsignedBigInteger('size_id')->nullable()->index('products_size_fk');
            $table->unsignedBigInteger('paper_id')->nullable()->index('products_paper_fk');
            $table->decimal('price', 12)->default(0);
            $table->decimal('cost_price', 12)->nullable();
            $table->decimal('mrp', 12)->nullable();
            $table->enum('discount_type', ['percent', 'flat'])->nullable();
            $table->decimal('discount_value', 12)->nullable();
            $table->timestamp('discount_starts_at')->nullable();
            $table->timestamp('discount_ends_at')->nullable();
            $table->boolean('track_stock')->default(true);
            $table->decimal('reorder_level', 12, 3)->nullable();
            $table->string('image', 255)->nullable();
            $table->string('thumbnail_image', 255)->nullable();
            $table->string('size_chart_image', 255)->nullable();
            $table->string('material', 255)->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->string('meta_image', 255)->nullable();
            $table->string('short_description', 255)->nullable();
            $table->longText('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('weight', 12, 3)->nullable();
            $table->decimal('width', 12, 3)->nullable();
            $table->decimal('height', 12, 3)->nullable();
            $table->decimal('length', 12, 3)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['parent_id', 'color_id', 'size_id', 'paper_id'], 'prod_parent_attr_combo_uniq');
            $table->index(['parent_id', 'is_sellable'], 'prod_parent_sellable_idx');
            $table->index(['brand_id', 'is_active'], 'products_brand_active_idx');
            $table->index(['category_id', 'subcategory_id'], 'products_cat_sub_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
